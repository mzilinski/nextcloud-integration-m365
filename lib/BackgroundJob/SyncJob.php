<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\BackgroundJob;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Sync\UserSyncService;
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
 */
class SyncJob extends TimedJob {
	public const BUDGET_SECONDS = 240;

	public function __construct(
		ITimeFactory $time,
		private CollectionMapper $collections,
		private UserSyncService $userSync,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
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
