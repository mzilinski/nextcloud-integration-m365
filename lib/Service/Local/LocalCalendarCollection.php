<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Local;

use OCA\DAV\CalDAV\CalDavBackend;

class LocalCalendarCollection implements LocalCollectionInterface {
	public function __construct(
		private CalDavBackend $backend,
		private string $principal,
		private int $calendarId,
		private string $calendarUri,
	) {
	}

	public function changes(?int $syncToken): array {
		// A non-numeric token makes the backend list every object as "added".
		$result = $this->backend->getChangesForCalendar((string)$this->calendarId, $syncToken === null ? '' : (string)$syncToken, 1);
		if ($result === null) {
			throw new \RuntimeException('calendar ' . $this->calendarId . ' no longer exists');
		}
		return [
			'token' => (int)$result['syncToken'],
			'added' => array_values(array_map('strval', $result['added'] ?? [])),
			'modified' => array_values(array_map('strval', $result['modified'] ?? [])),
			'deleted' => array_values(array_map('strval', $result['deleted'] ?? [])),
			'full' => $syncToken === null,
		];
	}

	public function get(string $uri): ?array {
		$obj = $this->backend->getCalendarObject($this->calendarId, $uri);
		if ($obj === null) {
			return null;
		}
		$data = $obj['calendardata'];
		if (is_resource($data)) {
			$data = (string)stream_get_contents($data);
		}
		return ['data' => (string)$data, 'etag' => self::etag((string)($obj['etag'] ?? ''))];
	}

	public function create(string $uri, string $data): string {
		return self::etag((string)$this->backend->createCalendarObject($this->calendarId, $uri, $data));
	}

	public function update(string $uri, string $data): string {
		return self::etag((string)$this->backend->updateCalendarObject($this->calendarId, $uri, $data));
	}

	public function delete(string $uri): void {
		$this->backend->deleteCalendarObject($this->calendarId, $uri);
	}

	public function findUriByUid(string $uid): ?string {
		$path = $this->backend->getCalendarObjectByUID($this->principal, $uid, $this->calendarUri);
		if ($path === null) {
			return null;
		}
		$prefix = $this->calendarUri . '/';
		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : null;
	}

	public static function etag(string $etag): string {
		return trim($etag, '"');
	}
}
