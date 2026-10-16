<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Db\ItemMapper;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Everything that removes or resets sync state: disconnect, user deletion, admin
 * changing the app registration, a pair whose local collection disappeared.
 *
 * Local calendars and address books are never deleted here: they are the user's data
 * and Nextcloud removes them itself when the user is deleted.
 */
class LifecycleService {
	public function __construct(
		private CollectionMapper $collections,
		private ItemMapper $items,
		private OAuthService $oauth,
		private IUserConfig $userConfig,
		private IJobList $jobList,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	/** Forget tokens; keep the pairs (disabled) unless $purge, then drop pairs and correlations. */
	public function disconnect(string $userId, bool $purge): void {
		$this->oauth->disconnect($userId);
		$this->jobList->remove(UserSyncJob::class, ['user_id' => $userId]);
		if ($purge) {
			$this->forgetPairs($userId);
			return;
		}
		foreach ($this->collections->findByUser($userId) as $pair) {
			if ($pair->getEnabled() || $pair->getLockedUntil() !== null) {
				$pair->setEnabled(false);
				$pair->setLockedUntil(null);
				$this->collections->update($pair);
			}
		}
	}

	/** Drop every pair and correlation of the user (the remote ids belong to one mailbox only). */
	public function forgetPairs(string $userId): void {
		foreach ($this->collections->findByUser($userId) as $pair) {
			$this->items->deleteByCollection((int)$pair->getId());
		}
		$this->collections->deleteByUser($userId);
	}

	/** Remove every trace of the user: tokens, pairs, correlations, queued jobs, notifications. */
	public function purgeUser(string $userId): void {
		$this->forgetPairs($userId);
		$this->jobList->remove(UserSyncJob::class, ['user_id' => $userId]);
		foreach ($this->userConfig->getKeys($userId, Application::APP_ID) as $key) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
		}
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)->setUser($userId);
		$this->notifications->markProcessed($notification);
	}

	/**
	 * Forget the cursors and correlations of one pair; the next run lists both sides
	 * in full and re-pairs objects by UID, so nothing is duplicated or deleted.
	 */
	public function resetPair(Collection $pair, bool $enabled): Collection {
		$this->items->deleteByCollection((int)$pair->getId());
		$pair->setLocalSyncToken(null);
		$pair->setDeltaLink(null);
		$pair->setPendingItems(null);
		$pair->setLastError(null);
		$pair->setLockedUntil(null);
		$pair->setEnabled($enabled);
		return $this->collections->update($pair);
	}

	/**
	 * The admin changed client id or tenant: every stored token was issued for the old
	 * registration and would only produce confusing AADSTS errors. Drop them and ask
	 * the users to connect again (pairs stay, so nothing has to be reconfigured).
	 *
	 * @return int number of users that were connected
	 */
	public function invalidateAllTokens(string $reason): int {
		$n = 0;
		foreach (array_keys($this->userConfig->getValuesByUsers(Application::APP_ID, 'refresh_token')) as $userId) {
			$this->oauth->forgetTokens((string)$userId);
			$this->oauth->markReauthRequired((string)$userId, 'admin', $reason);
			$n++;
		}
		$this->logger->info('Microsoft 365 app registration changed, tokens of all users invalidated', ['app' => Application::APP_ID, 'users' => $n, 'reason' => $reason]);
		return $n;
	}

	/** @return list<string> every user that has a pair or a token */
	public function allUsers(): array {
		$users = array_merge(
			$this->collections->findUsersWithPairs(),
			array_map('strval', array_keys($this->userConfig->getValuesByUsers(Application::APP_ID, 'refresh_token'))),
		);
		return array_values(array_unique($users));
	}
}
