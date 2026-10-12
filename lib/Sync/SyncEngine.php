<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Sync;

use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\Item;
use OCA\IntegrationM365\Db\ItemStoreInterface;
use OCA\IntegrationM365\Service\Local\LocalCollectionInterface;
use OCA\IntegrationM365\Service\Mapping\CanonicalHasher;
use OCA\IntegrationM365\Service\Mapping\ItemMapperInterface;
use OCA\IntegrationM365\Service\Remote\RemoteCollectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Two-way sync of one collection pair.
 *
 * Both sides report changes (local sync token, remote delta link). Correlations in
 * m365_items remember, per object, the etags we last saw/produced on either side and a
 * canonical content hash. Echo filter: an etag we already know is ignored; a changed
 * etag with an unchanged content hash only refreshes the stored etag (this absorbs
 * Exchange's changeKey bump after an upload and local no-op rewrites). Real changes
 * are copied across; changes on both sides are resolved by the pair's conflict policy.
 */
class SyncEngine {
	public const POLICY_REMOTE = 'remote';
	public const POLICY_LOCAL = 'local';
	public const MAX_WRITES = 500;
	public const MAX_SIZE = 1_000_000;

	public function __construct(
		private ItemStoreInterface $items,
		private LoggerInterface $logger,
	) {
	}

	public function sync(
		Collection $pair,
		LocalCollectionInterface $local,
		RemoteCollectionInterface $remote,
		ItemMapperInterface $mapper,
		bool $dryRun = false,
		?float $deadline = null,
	): SyncResult {
		$r = new SyncResult();
		$policy = $pair->getConflictPolicy() === self::POLICY_LOCAL ? self::POLICY_LOCAL : self::POLICY_REMOTE;
		$pairId = (int)$pair->getId();

		// --- correlations ---------------------------------------------------------
		$byLocal = [];
		$byRemote = [];
		$byUid = [];
		foreach ($this->items->findByCollection($pairId) as $it) {
			$byLocal[$it->getLocalUri()] = $it;
			$byRemote[$it->getRemoteId()] = $it;
			$byUid[$it->getUid()] = $it;
		}

		// --- detect ---------------------------------------------------------------
		$lc = $local->changes($pair->getLocalSyncToken());
		$rc = $remote->changes($pair->getDeltaLink());
		$r->log[] = sprintf('local: %d added, %d modified, %d deleted%s | remote: %d changed, %d removed%s',
			count($lc['added']), count($lc['modified']), count($lc['deleted']), $lc['full'] ? ' (full)' : '',
			count($rc['items']), count($rc['removed']), $rc['full'] ? ' (full)' : '');

		/** @var array<string,array{data:string,etag:string}> $localChanged */
		$localChanged = [];
		foreach (array_unique(array_merge($lc['added'], $lc['modified'])) as $uri) {
			$obj = $local->get($uri);
			if ($obj === null) {
				continue;
			}
			$corr = $byLocal[$uri] ?? null;
			if ($corr !== null && $corr->getLocalEtag() === $obj['etag']) {
				continue; // our own write or already seen
			}
			if (strlen($obj['data']) > self::MAX_SIZE) {
				$r->skipped++;
				continue;
			}
			$localChanged[$uri] = $obj;
		}
		$localDeleted = [];
		foreach ($lc['deleted'] as $uri) {
			if (isset($byLocal[$uri])) {
				$localDeleted[$uri] = true;
			}
		}
		if ($lc['full']) {
			$present = array_fill_keys($lc['added'], true);
			foreach ($byLocal as $uri => $corr) {
				if (!isset($present[$uri]) && $local->get($uri) === null) {
					$localDeleted[$uri] = true;
				}
			}
		}

		/** @var array<string,array> $remoteChanged */
		$remoteChanged = [];
		foreach ($rc['items'] as $id => $json) {
			if ($mapper->skip($json)) {
				$r->skipped++;
				continue;
			}
			$corr = $byRemote[$id] ?? null;
			if ($corr !== null && $corr->getRemoteEtag() === $remote->etagOf($json)) {
				continue;
			}
			$remoteChanged[$id] = $json;
		}
		$remoteDeleted = [];
		foreach ($rc['removed'] as $id) {
			if (isset($byRemote[$id])) {
				$remoteDeleted[$id] = true;
			}
		}
		if ($rc['full']) {
			foreach ($byRemote as $id => $corr) {
				if (!isset($rc['items'][$id])) {
					$remoteDeleted[$id] = true;
				}
			}
		}
		$extras = $remoteChanged !== [] ? $remote->extrasFor(array_keys($remoteChanged)) : [];

		// --- helpers --------------------------------------------------------------
		$writes = 0;
		$budget = function () use (&$writes, $r, $deadline): bool {
			if ($r->truncated) {
				return false;
			}
			if ($writes >= self::MAX_WRITES) {
				$r->truncated = true;
				$r->log[] = 'write budget exhausted, continuing next run';
				return false;
			}
			if ($deadline !== null && microtime(true) > $deadline) {
				$r->truncated = true;
				$r->log[] = 'time budget exhausted, continuing next run';
				return false;
			}
			$writes++;
			return true;
		};
		$hashLocal = static fn (string $data): string => CanonicalHasher::hash($mapper->toGraph($data), $mapper->photoOf($data)[0] ?? null);
		$hashRemote = function (array $json, string $id) use ($mapper, $extras): string {
			$text = $mapper->toLocal($json, $extras[$id] ?? []);
			return $text === null ? '' : CanonicalHasher::hash($mapper->toGraph($text), ($extras[$id]['photo'][0] ?? null));
		};
		$save = function (Item $corr) use ($dryRun): void {
			if ($dryRun) {
				return;
			}
			$corr->setLastSynced(time());
			$corr->getId() === null ? $this->items->insertItem($corr) : $this->items->updateItem($corr);
		};
		$drop = function (Item $corr) use ($dryRun): void {
			if (!$dryRun && $corr->getId() !== null) {
				$this->items->deleteItem($corr);
			}
		};
		$newCorr = static function (int $pairId, string $uid, string $uri, string $localEtag, string $remoteId, string $remoteEtag, string $hash): Item {
			$it = new Item();
			$it->setCollectionId($pairId);
			$it->setUid($uid);
			$it->setLocalUri($uri);
			$it->setLocalEtag($localEtag);
			$it->setRemoteId($remoteId);
			$it->setRemoteEtag($remoteEtag);
			$it->setContentHash($hash);
			return $it;
		};
		$localUriFor = function (string $uid) use ($mapper, $local, $byLocal): string {
			$base = substr(preg_replace('/[^A-Za-z0-9._@-]+/', '_', $uid) ?? 'item', 0, 200);
			$uri = $base . '.' . $mapper->extension();
			$n = 1;
			while (isset($byLocal[$uri]) || $local->get($uri) !== null) {
				$uri = $base . '-' . (++$n) . '.' . $mapper->extension();
			}
			return $uri;
		};

		// Writers (each returns true when it wrote, false when skipped/failed).
		$writeRemoteFrom = function (string $uri, array $obj, ?Item $corr) use ($mapper, $remote, $pairId, $newCorr, $save, $dryRun, $budget, $r, $hashLocal): bool {
			if (!$budget()) {
				return false;
			}
			$body = $mapper->toGraph($obj['data']);
			$uid = $corr?->getUid() ?? ($mapper->uidOf($obj['data']) ?? $uri);
			$hash = $hashLocal($obj['data']);
			if ($dryRun) {
				$r->log[] = ($corr === null ? 'would create remote from ' : 'would update remote from ') . $uri;
				$corr === null ? $r->remoteCreated++ : $r->remoteUpdated++;
				return true;
			}
			if ($corr === null) {
				$created = $remote->create($body);
				$remote->afterWrite((string)$created['id'], $mapper->photoOf($obj['data']));
				$fresh = $remote->get((string)$created['id']) ?? $created;
				$corr = $newCorr($pairId, $uid, $uri, $obj['etag'], (string)$created['id'], $remote->etagOf($fresh), $hash);
				$r->remoteCreated++;
				$r->log[] = "created remote {$created['id']} from $uri";
			} else {
				$updated = $remote->update($corr->getRemoteId(), $body);
				$remote->afterWrite($corr->getRemoteId(), $mapper->photoOf($obj['data']));
				$fresh = $remote->get($corr->getRemoteId()) ?? $updated;
				$corr->setLocalEtag($obj['etag']);
				$corr->setRemoteEtag($remote->etagOf($fresh));
				$corr->setContentHash($hash);
				$r->remoteUpdated++;
				$r->log[] = "updated remote {$corr->getRemoteId()} from $uri";
			}
			$save($corr);
			return true;
		};
		$writeLocalFrom = function (string $id, array $json, ?Item $corr) use ($mapper, $remote, $local, $extras, $pairId, $newCorr, $save, $dryRun, $budget, $r, $localUriFor, $hashRemote, &$byLocal): bool {
			$text = $mapper->toLocal($json, $extras[$id] ?? []);
			if ($text === null) {
				$r->skipped++;
				return false;
			}
			if (!$budget()) {
				return false;
			}
			$hash = $hashRemote($json, $id);
			if ($dryRun) {
				$r->log[] = ($corr === null ? 'would create local from ' : 'would update local from ') . $id;
				$corr === null ? $r->localCreated++ : $r->localUpdated++;
				return true;
			}
			if ($corr === null) {
				$uid = $mapper->remoteUid($json);
				$text = $mapper->withUid($text, $uid);
				$uri = $localUriFor($uid);
				$etag = $local->create($uri, $text);
				$corr = $newCorr($pairId, $uid, $uri, $etag, $id, $remote->etagOf($json), $hash);
				$byLocal[$uri] = $corr;
				$r->localCreated++;
				$r->log[] = "created local $uri from $id";
			} else {
				$text = $mapper->withUid($text, $corr->getUid());
				$etag = $local->update($corr->getLocalUri(), $text);
				$corr->setLocalEtag($etag);
				$corr->setRemoteEtag($remote->etagOf($json));
				$corr->setContentHash($hash);
				$r->localUpdated++;
				$r->log[] = "updated local {$corr->getLocalUri()} from $id";
			}
			$save($corr);
			return true;
		};

		// --- correlated items -----------------------------------------------------
		$handled = [];
		foreach ($byLocal as $uri => $corr) {
			$id = $corr->getRemoteId();
			$lChg = $localChanged[$uri] ?? null;
			$rChg = $remoteChanged[$id] ?? null;
			$lDel = isset($localDeleted[$uri]);
			$rDel = isset($remoteDeleted[$id]);
			if ($lChg === null && $rChg === null && !$lDel && !$rDel) {
				continue;
			}
			$handled[$uri] = true;
			$handled['r:' . $id] = true;
			try {
				if ($lDel && $rDel) {
					$drop($corr);
					$r->log[] = "both deleted $uri";
				} elseif ($lDel) {
					if ($rChg !== null && $policy === self::POLICY_REMOTE) {
						$r->conflicts++;
						$drop($corr);
						$writeLocalFrom($id, $rChg, null); // remote wins: comes back
					} else {
						if ($budget()) {
							$dryRun || $remote->delete($id);
							$drop($corr);
							$r->remoteDeleted++;
							$r->log[] = ($dryRun ? 'would delete remote ' : 'deleted remote ') . $id;
						}
					}
				} elseif ($rDel) {
					if ($lChg !== null && $policy === self::POLICY_LOCAL) {
						$r->conflicts++;
						$drop($corr);
						$writeRemoteFrom($uri, $lChg, null);
					} else {
						if ($budget()) {
							$dryRun || $local->delete($uri);
							$drop($corr);
							$r->localDeleted++;
							$r->log[] = ($dryRun ? 'would delete local ' : 'deleted local ') . $uri;
						}
					}
				} else {
					$lHash = $lChg !== null ? $hashLocal($lChg['data']) : null;
					$rHash = $rChg !== null ? $hashRemote($rChg, $id) : null;
					$lReal = $lHash !== null && $lHash !== $corr->getContentHash();
					$rReal = $rHash !== null && $rHash !== $corr->getContentHash();
					if (!$lReal && !$rReal) {
						// echoes only: remember the new etags
						if ($lChg !== null) {
							$corr->setLocalEtag($lChg['etag']);
						}
						if ($rChg !== null) {
							$corr->setRemoteEtag($remote->etagOf($rChg));
						}
						$save($corr);
						$r->log[] = "echo absorbed $uri";
					} elseif ($lReal && $rReal) {
						$r->conflicts++;
						if ($lHash === $rHash) {
							$corr->setLocalEtag($lChg['etag']);
							$corr->setRemoteEtag($remote->etagOf($rChg));
							$corr->setContentHash($lHash);
							$save($corr);
							$r->log[] = "both changed identically $uri";
						} elseif ($policy === self::POLICY_REMOTE) {
							$r->log[] = "conflict on $uri: remote wins";
							$writeLocalFrom($id, $rChg, $corr);
						} else {
							$r->log[] = "conflict on $uri: local wins";
							$writeRemoteFrom($uri, $lChg, $corr);
						}
					} elseif ($lReal) {
						if ($rChg !== null) {
							$corr->setRemoteEtag($remote->etagOf($rChg));
						}
						$writeRemoteFrom($uri, $lChg, $corr);
					} else {
						if ($lChg !== null) {
							$corr->setLocalEtag($lChg['etag']);
						}
						$writeLocalFrom($id, $rChg, $corr);
					}
				}
			} catch (\Throwable $e) {
				$r->errors++;
				$r->log[] = "error on $uri: " . $e->getMessage();
				$this->logger->warning('sync item failed', ['app' => 'integration_m365', 'pair' => $pairId, 'uri' => $uri, 'exception' => $e]);
			}
		}

		// --- new on the remote side ----------------------------------------------
		$remoteNewByUid = [];
		foreach ($remoteChanged as $id => $json) {
			if (isset($handled['r:' . $id]) || isset($byRemote[$id])) {
				continue;
			}
			$remoteNewByUid[$mapper->remoteUid($json)] = $id;
		}

		// --- new on the local side ------------------------------------------------
		foreach ($localChanged as $uri => $obj) {
			if (isset($handled[$uri]) || isset($byLocal[$uri])) {
				continue;
			}
			try {
				$uid = $mapper->uidOf($obj['data']);
				if ($uid !== null && isset($remoteNewByUid[$uid])) {
					// Same UID appeared on both sides (e.g. first sync of pre-existing data): pair without copying.
					$id = $remoteNewByUid[$uid];
					$json = $remoteChanged[$id];
					$handled['r:' . $id] = true;
					$lHash = $hashLocal($obj['data']);
					$rHash = $hashRemote($json, $id);
					$corr = $newCorr($pairId, $uid, $uri, $obj['etag'], $id, $remote->etagOf($json), $lHash);
					if ($lHash === $rHash) {
						$save($corr);
						$r->log[] = "paired by uid $uri <-> $id";
					} elseif ($policy === self::POLICY_REMOTE) {
						$save($corr);
						$writeLocalFrom($id, $json, $corr);
					} else {
						$save($corr);
						$writeRemoteFrom($uri, $obj, $corr);
					}
					continue;
				}
				$writeRemoteFrom($uri, $obj, null);
			} catch (\Throwable $e) {
				$r->errors++;
				$r->log[] = "error on $uri: " . $e->getMessage();
				$this->logger->warning('sync item failed', ['app' => 'integration_m365', 'pair' => $pairId, 'uri' => $uri, 'exception' => $e]);
			}
		}

		foreach ($remoteChanged as $id => $json) {
			if (isset($handled['r:' . $id]) || isset($byRemote[$id])) {
				continue;
			}
			try {
				$uid = $mapper->remoteUid($json);
				$existingUri = $uid !== '' ? $local->findUriByUid($uid) : null;
				if ($existingUri !== null && !isset($byLocal[$existingUri])) {
					// Local object with this UID exists but was never correlated (pre-existing data).
					$obj = $local->get($existingUri);
					if ($obj !== null) {
						$lHash = $hashLocal($obj['data']);
						$rHash = $hashRemote($json, $id);
						$corr = $newCorr($pairId, $uid, $existingUri, $obj['etag'], $id, $remote->etagOf($json), $rHash);
						if ($lHash === $rHash || $policy === self::POLICY_REMOTE) {
							$save($corr);
							$byLocal[$existingUri] = $corr;
							if ($lHash !== $rHash) {
								$writeLocalFrom($id, $json, $corr);
							} else {
								$r->log[] = "paired by uid $existingUri <-> $id";
							}
						} else {
							$corr->setContentHash($lHash);
							$save($corr);
							$byLocal[$existingUri] = $corr;
							$writeRemoteFrom($existingUri, $obj, $corr);
						}
						continue;
					}
				}
				$writeLocalFrom($id, $json, null);
			} catch (\Throwable $e) {
				$r->errors++;
				$r->log[] = "error on $id: " . $e->getMessage();
				$this->logger->warning('sync item failed', ['app' => 'integration_m365', 'pair' => $pairId, 'remote' => $id, 'exception' => $e]);
			}
		}

		// Advance the cursors only after a complete pass. After a truncated run the next
		// run re-lists the same changes; what was already written is absorbed by the echo filter.
		if (!$dryRun && !$r->truncated) {
			$pair->setLocalSyncToken($lc['token']);
			if ($rc['deltaLink'] !== null) {
				$pair->setDeltaLink($rc['deltaLink']);
			}
		}
		$r->log[] = $r->summary();
		return $r;
	}
}
