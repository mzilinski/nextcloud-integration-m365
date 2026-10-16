<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Local;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Exception\LocalCollectionMissingException;

/**
 * Wraps Nextcloud's internal CalDavBackend (there is no public write API for calendars).
 * Used for event calendars and for VTODO calendars (tasks) alike.
 */
class LocalCalendarService {
	public function __construct(
		private CalDavBackend $backend,
	) {
	}

	public static function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	/**
	 * Find or create the local calendar for a pair. Returns [calendarId, calendarUri].
	 *
	 * @param string $components VEVENT or VTODO
	 * @return array{0:int,1:string}
	 * @throws LocalCollectionMissingException the calendar the pair was bound to is deleted or in the trash bin
	 */
	public function ensureCalendar(string $userId, Collection $pair, string $components): array {
		$principal = self::principal($userId);
		if ($pair->getLocalId() !== null) {
			$existing = $this->backend->getCalendarById($pair->getLocalId());
			if ($existing === null || $existing['principaluri'] !== $principal || !empty($existing['{http://nextcloud.com/ns}deleted-at'])) {
				throw new LocalCollectionMissingException("calendar {$pair->getLocalId()} of pair {$pair->getId()} is gone");
			}
			return [(int)$existing['id'], (string)$existing['uri']];
		}
		$uri = $pair->getLocalUri() !== '' ? $pair->getLocalUri() : self::slug($pair);
		$existing = $this->backend->getCalendarByUri($principal, $uri);
		if ($existing !== null) {
			return [(int)$existing['id'], $uri];
		}
		$id = $this->backend->createCalendar($principal, $uri, [
			'{DAV:}displayname' => $pair->getLocalName() !== '' ? $pair->getLocalName() : $pair->getRemoteName(),
			'components' => $components,
		]);
		return [(int)$id, $uri];
	}

	public static function slug(Collection $pair): string {
		$base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $pair->getRemoteName() ?: $pair->getLocalName() ?: $pair->getType()) ?? '');
		$base = trim($base, '-') ?: $pair->getType();
		return 'm365-' . substr($base, 0, 60) . '-' . substr(hash('crc32b', $pair->getRemoteId()), 0, 6);
	}

	public function bind(string $userId, int $calendarId, string $calendarUri): LocalCollectionInterface {
		return new LocalCalendarCollection($this->backend, self::principal($userId), $calendarId, $calendarUri);
	}
}
