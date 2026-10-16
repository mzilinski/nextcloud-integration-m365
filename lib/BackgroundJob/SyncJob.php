<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\BackgroundJob;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Sync\UserSyncService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic sync of every user with at least one enabled pair.
 *
 * Time sensitive: otherwise instances with a maintenance window would only sync during
 * that window. One run spends at most BUDGET_SECONDS; users are visited least recently
 * synced first, so whoever was cut off comes first next time.
 *
 * The interval is the admin setting `sync_interval` (seconds, default 300, minimum 60).
 * Nextcloud's cron must run at least that often for it to matter.
 */
class SyncJob extends TimedJob {
	public const BUDGET_SECONDS = 240;
	public const DEFAULT_INTERVAL = 300;
	public const MIN_INTERVAL = 60;
	public const MAX_INTERVAL = 3600;

	public function __construct(
		ITimeFactory $time,
		IAppConfig $appConfig,
		private CollectionMapper $collections,
		private UserSyncService $userSync,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::interval($appConfig));
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	/** The configured interval, clamped to the allowed range. */
	public static function interval(IAppConfig $appConfig): int {
		return min(self::MAX_INTERVAL, max(self::MIN_INTERVAL, $appConfig->getAppValueInt('sync_interval', self::DEFAULT_INTERVAL, lazy: true)));
	}

	protected function run($argument): void {
		$deadline = microtime(true) + (float)self::BUDGET_SECONDS;
		$users = $this->collections->findUsersWithEnabledPairs();
		foreach (array_values($users) as $i => $userId) {
			if (microtime(true) > $deadline) {
				$this->logger->info('sync run out of time, remaining users continue next run', ['app' => Application::APP_ID, 'remaining' => count($users) - $i]);
				return;
			}
			try {
				$this->userSync->syncUser($userId, deadline: $deadline);
			} catch (\Throwable $e) {
				// one user must not take the others down with them
				$this->logger->error('sync of user failed', ['app' => Application::APP_ID, 'uid' => $userId, 'exception' => $e]);
			}
		}
	}
}
