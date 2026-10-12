<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Sync;

/** Counters of one engine run, plus the log lines it produced. */
class SyncResult {
	public int $localCreated = 0;
	public int $localUpdated = 0;
	public int $localDeleted = 0;
	public int $remoteCreated = 0;
	public int $remoteUpdated = 0;
	public int $remoteDeleted = 0;
	public int $conflicts = 0;
	public int $skipped = 0;
	public int $errors = 0;
	/** True when the write or time budget ran out: tokens are not advanced, the next run continues. */
	public bool $truncated = false;
	/** @var list<string> */
	public array $log = [];

	public function writes(): int {
		return $this->localCreated + $this->localUpdated + $this->localDeleted + $this->remoteCreated + $this->remoteUpdated + $this->remoteDeleted;
	}

	public function summary(): string {
		return sprintf(
			'local +%d ~%d -%d | remote +%d ~%d -%d | conflicts %d, skipped %d, errors %d',
			$this->localCreated, $this->localUpdated, $this->localDeleted,
			$this->remoteCreated, $this->remoteUpdated, $this->remoteDeleted,
			$this->conflicts, $this->skipped, $this->errors,
		);
	}
}
