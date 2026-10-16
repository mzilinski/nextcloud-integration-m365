<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Sync;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\ConfigurationException;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Exception\LocalCollectionMissingException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\LifecycleService;
use OCA\IntegrationM365\Service\Local\LocalCalendarService;
use OCA\IntegrationM365\Service\Local\LocalCollectionInterface;
use OCA\IntegrationM365\Service\Local\LocalContactsService;
use OCA\IntegrationM365\Service\Mapping\CalendarItemMapper;
use OCA\IntegrationM365\Service\Mapping\ContactItemMapper;
use OCA\IntegrationM365\Service\Mapping\ItemMapperInterface;
use OCA\IntegrationM365\Service\Mapping\TodoItemMapper;
use OCA\IntegrationM365\Service\OAuthService;
use OCA\IntegrationM365\Service\Remote\RemoteCalendarService;
use OCA\IntegrationM365\Service\Remote\RemoteCollectionInterface;
use OCA\IntegrationM365\Service\Remote\RemoteContactsService;
use OCA\IntegrationM365\Service\Remote\RemoteTasksService;
use Psr\Log\LoggerInterface;

/** Runs every enabled pair of one user: locking, service wiring, error bookkeeping. */
class UserSyncService {
	private const LOCK_SECONDS = 900;

	public function __construct(
		private CollectionMapper $collections,
		private SyncEngine $engine,
		private LocalCalendarService $localCalendars,
		private LocalContactsService $localContacts,
		private RemoteCalendarService $remoteCalendars,
		private RemoteTasksService $remoteTasks,
		private RemoteContactsService $remoteContacts,
		private OAuthService $oauth,
		private LifecycleService $lifecycle,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(string):void|null $out verbose output (occ -v)
	 * @param bool $full forget sync token and delta link first (re-lists everything, keeps correlations)
	 * @return array<int, SyncResult> keyed by pair id
	 */
	public function syncUser(string $userId, bool $dryRun = false, ?callable $out = null, ?int $onlyPair = null, ?float $deadline = null, bool $full = false): array {
		$results = [];
		$pairs = $this->collections->findEnabledByUser($userId);
		if ($pairs !== [] && $this->oauth->getAccountInfo($userId)['reauthRequired']) {
			// flagged by a previous run or by the admin: no Graph call until the user reconnects
			$out && $out("user $userId: Microsoft account must be reconnected, skipping");
			foreach ($pairs as $pair) {
				$this->markError($pair, SyncError::REAUTH_REQUIRED, $dryRun);
			}
			return [];
		}
		foreach ($pairs as $pair) {
			if ($onlyPair !== null && $pair->getId() !== $onlyPair) {
				continue;
			}
			if ($deadline !== null && microtime(true) > $deadline) {
				$out && $out("pair {$pair->getId()}: time budget exhausted before start, continuing next run");
				$results[(int)$pair->getId()] = $this->truncatedResult();
				continue;
			}
			if (!$dryRun) {
				$now = time();
				if (!$this->collections->tryLock((int)$pair->getId(), $now, $now + self::LOCK_SECONDS)) {
					$out && $out("pair {$pair->getId()} is locked by another run, skipping");
					$results[(int)$pair->getId()] = $this->truncatedResult(true);
					continue;
				}
				$pair->setLockedUntil($now + self::LOCK_SECONDS);
			}
			if ($full) {
				$pair->setLocalSyncToken(null);
				$pair->setDeltaLink(null);
			}
			try {
				$result = $this->syncPair($userId, $pair, $dryRun, $out, $deadline);
				$results[(int)$pair->getId()] = $result;
				$pair->setLastError($result->errors > 0 ? SyncError::ITEMS_FAILED . $result->errors : null);
				$pair->setPendingItems($result->truncated ? $result->remaining : null);
				if (!$dryRun) {
					$pair->setLastSync(time());
				}
			} catch (TokenException $e) {
				$pair->setLastError(SyncError::REAUTH_REQUIRED);
				$out && $out("pair {$pair->getId()}: " . $e->getMessage());
				break; // no point in trying the other pairs of this user
			} catch (ConfigurationException $e) {
				$pair->setLastError(SyncError::CONFIG_ERROR);
				$out && $out("pair {$pair->getId()}: app registration rejected by Entra ID ({$e->graphCode}), see admin settings");
				break;
			} catch (LocalCollectionMissingException $e) {
				// the user deleted the calendar / address book: stop instead of recreating it and
				// replaying the old cursors (which would delete everything on the Microsoft side)
				$out && $out("pair {$pair->getId()}: local collection is gone, pair disabled");
				$this->logger->warning('local collection of pair is gone, pair disabled', ['app' => Application::APP_ID, 'pair' => $pair->getId(), 'uid' => $userId]);
				if (!$dryRun) {
					$pair->setLocalId(null);
					$pair = $this->lifecycle->resetPair($pair, false);
					$pair->setLastError(SyncError::LOCAL_MISSING);
				}
			} catch (GraphException|\Throwable $e) {
				$pair->setLastError(substr($e->getMessage(), 0, 500));
				$out && $out("pair {$pair->getId()}: " . $e->getMessage());
				$this->logger->error('sync of pair failed', ['app' => Application::APP_ID, 'pair' => $pair->getId(), 'exception' => $e]);
			} finally {
				// also runs on `break` and when logging itself throws: never leave a stale lock behind
				$this->finish($pair, $dryRun);
			}
		}
		return $results;
	}

	/** Placeholder for a pair that could not run in this call (locked or out of time). */
	private function truncatedResult(bool $locked = false): SyncResult {
		$r = new SyncResult();
		$r->truncated = true;
		$r->locked = $locked;
		return $r;
	}

	private function markError(Collection $pair, string $error, bool $dryRun): void {
		if (!$dryRun && $pair->getLastError() !== $error) {
			$pair->setLastError($error);
			$this->collections->update($pair);
		}
	}

	private function finish(Collection $pair, bool $dryRun): void {
		if ($dryRun) {
			return;
		}
		$pair->setLockedUntil(null);
		$this->collections->update($pair);
	}

	/** @param callable(string):void|null $out */
	private function syncPair(string $userId, Collection $pair, bool $dryRun, ?callable $out, ?float $deadline): SyncResult {
		[$local, $remote, $mapper] = $this->wire($userId, $pair, $dryRun);
		$out && $out(sprintf('pair %d [%s] %s -> %s', $pair->getId(), $pair->getType(), $pair->getRemoteName(), $pair->getLocalUri()));
		$result = $this->engine->sync($pair, $local, $remote, $mapper, $dryRun, $deadline);
		if ($out) {
			foreach ($result->log as $line) {
				$out('  ' . $line);
			}
		}
		return $result;
	}

	/** @return array{0:LocalCollectionInterface,1:RemoteCollectionInterface,2:ItemMapperInterface} */
	private function wire(string $userId, Collection $pair, bool $dryRun): array {
		switch ($pair->getType()) {
			case Application::TYPE_CALENDAR:
				[$id, $uri] = $this->localCalendars->ensureCalendar($userId, $pair, 'VEVENT');
				$this->rememberLocal($pair, $id, $uri, $dryRun);
				return [$this->localCalendars->bind($userId, $id, $uri), $this->remoteCalendars->bind($userId, $pair->getRemoteId()), new CalendarItemMapper()];
			case Application::TYPE_TASKS:
				[$id, $uri] = $this->localCalendars->ensureCalendar($userId, $pair, 'VTODO');
				$this->rememberLocal($pair, $id, $uri, $dryRun);
				return [$this->localCalendars->bind($userId, $id, $uri), $this->remoteTasks->bind($userId, $pair->getRemoteId()), new TodoItemMapper()];
			case Application::TYPE_CONTACTS:
				[$id, $uri] = $this->localContacts->ensureAddressBook($userId, $pair);
				$this->rememberLocal($pair, $id, $uri, $dryRun);
				return [$this->localContacts->bind($id), $this->remoteContacts->bind($userId, $pair->getRemoteId()), new ContactItemMapper()];
			default:
				throw new \RuntimeException('unknown pair type ' . $pair->getType());
		}
	}

	private function rememberLocal(Collection $pair, int $id, string $uri, bool $dryRun): void {
		if ($pair->getLocalId() !== $id || $pair->getLocalUri() !== $uri) {
			$pair->setLocalId($id);
			$pair->setLocalUri($uri);
			if (!$dryRun) {
				$this->collections->update($pair);
			}
		}
	}
}
