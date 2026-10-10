<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\BackgroundJob;

use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Sync\UserSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Periodic sync of every user with at least one enabled pair. */
class SyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private CollectionMapper $collections,
		private UserSyncService $userSync,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		foreach ($this->collections->findUsersWithEnabledPairs() as $userId) {
			$this->userSync->syncUser($userId);
		}
	}
}
