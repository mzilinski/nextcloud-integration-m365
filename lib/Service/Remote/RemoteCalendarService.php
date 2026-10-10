<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

use OCA\IntegrationM365\Service\GraphClient;

/** Graph calendars: /me/calendars/{id}/events (delta) and /me/events/{id}. */
class RemoteCalendarService {
	public const SELECT = 'id,iCalUId,changeKey,subject,body,bodyPreview,isAllDay,isCancelled,type,start,end,location,'
		. 'organizer,attendees,categories,showAs,sensitivity,recurrence';

	public function __construct(
		private GraphClient $graph,
	) {
	}

	public function bind(string $userId, string $calendarId): RemoteCollectionInterface {
		return new GraphRemoteCollection(
			$this->graph,
			$userId,
			'/me/calendars/' . rawurlencode($calendarId) . '/events/delta?$select=' . self::SELECT,
			static fn (string $id) => '/me/events/' . rawurlencode($id) . '?$select=' . self::SELECT,
			'/me/calendars/' . rawurlencode($calendarId) . '/events',
			static fn (string $id) => '/me/events/' . rawurlencode($id),
			'changeKey',
		);
	}
}
