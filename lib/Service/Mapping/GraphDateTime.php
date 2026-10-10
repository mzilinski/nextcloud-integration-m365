<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Graph ``dateTimeTimeZone`` <-> PHP DateTimeImmutable.
 *
 * Graph uses 7 fractional digits and names zones either IANA-style or with Windows
 * names ("W. Europe Standard Time"); both are resolved. Port of graphpim/_datetime.py.
 */
final class GraphDateTime {
	public const GRAPH_FORMAT = 'Y-m-d\TH:i:s.0000000';

	/** @var array<string,string>|null */
	private static ?array $windowsZones = null;

	public static function zone(?string $name): DateTimeZone {
		if ($name === null || $name === '' || strtoupper($name) === 'UTC') {
			return new DateTimeZone('UTC');
		}
		try {
			return new DateTimeZone($name);
		} catch (\Exception) {
			// not an IANA name
		}
		self::$windowsZones ??= require __DIR__ . '/../../../resources/windows_zones.php';
		$iana = self::$windowsZones[$name] ?? null;
		if ($iana !== null) {
			try {
				return new DateTimeZone($iana);
			} catch (\Exception) {
				// fall through
			}
		}
		return new DateTimeZone('UTC');
	}

	/**
	 * Parse ``{"dateTime": "...", "timeZone": "..."}``.
	 *
	 * @return DateTimeImmutable|null aware datetime; for all-day values the date at 00:00 UTC (use ->format('Y-m-d'))
	 */
	public static function parse(?array $dtz, bool $allDay = false): ?DateTimeImmutable {
		if ($dtz === null) {
			return null;
		}
		$raw = (string)($dtz['dateTime'] ?? '');
		if ($raw === '') {
			return null;
		}
		$raw = explode('.', $raw, 2)[0];
		$naive = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $raw, new DateTimeZone('UTC'));
		if ($naive === false) {
			return null;
		}
		if ($allDay) {
			// Take the date verbatim: converting a midnight through a zone would shift the day.
			return $naive->setTime(0, 0, 0);
		}
		$zone = self::zone($dtz['timeZone'] ?? null);
		$local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $raw, $zone);
		return $local === false ? $naive : $local;
	}

	/** Aware datetime -> Graph value in UTC. */
	public static function format(DateTimeImmutable $dt): array {
		return ['dateTime' => $dt->setTimezone(new DateTimeZone('UTC'))->format(self::GRAPH_FORMAT), 'timeZone' => 'UTC'];
	}

	/** Date (all-day) -> Graph value at midnight, date taken verbatim. */
	public static function formatDate(string $ymd): array {
		return ['dateTime' => $ymd . 'T00:00:00.0000000', 'timeZone' => 'UTC'];
	}
}
