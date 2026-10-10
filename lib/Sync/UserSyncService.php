<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Sync;

use OCA\IntegrationM365\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Runs all enabled pairs of one user. Phase A stub: the engine arrives in phase C.
 */
class UserSyncService {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	public function syncUser(string $userId, bool $dryRun = false): void {
		$this->logger->debug('sync requested (engine not implemented yet)', ['app' => Application::APP_ID, 'uid' => $userId]);
	}
}
