<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Repair;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\BackgroundJob\SyncJob;
use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Notification\IManager as INotificationManager;

/**
 * Runs on `app:disable` and `app:remove`. Only removes what would break or nag while
 * the app is gone: background jobs and open notifications. Tokens, pairs and
 * correlations stay so that re-enabling the app resumes where it stopped; use
 * `occ integration_m365:reset --all` before `app:remove` to wipe them.
 */
class Uninstall implements IRepairStep {
	public function __construct(
		private IJobList $jobList,
		private INotificationManager $notifications,
	) {
	}

	public function getName(): string {
		return 'Remove Microsoft 365 integration background jobs and notifications';
	}

	public function run(IOutput $output): void {
		$this->jobList->remove(SyncJob::class);
		$this->jobList->remove(UserSyncJob::class);
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID);
		$this->notifications->markProcessed($notification);
	}
}
