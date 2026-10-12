<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\BackgroundJob;

use OCA\IntegrationM365\Sync\UserSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/** One-off "sync now" for a single user, queued from the personal settings. */
class UserSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private UserSyncService $userSync,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$userId = is_array($argument) ? (string)($argument['user_id'] ?? '') : '';
		if ($userId === '') {
			return;
		}
		$results = $this->userSync->syncUser($userId);
		foreach ($results as $result) {
			if ($result->truncated) {
				// budget hit: queue the next round right away instead of waiting for the 5-minute job
				$this->jobList->add(self::class, ['user_id' => $userId]);
				return;
			}
		}
	}
}
