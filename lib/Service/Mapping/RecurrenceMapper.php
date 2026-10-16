<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

/**
 * Graph ``patternedRecurrence`` <-> RRULE parts. Port of graphpim/recurrence.py.
 *
 * relativeMonthly/relativeYearly ("every first Monday") map to BYDAY + BYSETPOS
 * (or the BYDAY=1MO ordinal form on the way back).
 */
final class RecurrenceMapper {
	private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
	private const ICAL_DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
	private const INDEX = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1];

	/** @param list<string> $daysOfWeek */
	private static function byday(array $daysOfWeek): array {
		$out = [];
		foreach ($daysOfWeek as $d) {
			$i = array_search($d, self::DAYS, true);
			if ($i !== false) {
				$out[] = self::ICAL_DAYS[$i];
			}
		}
		return $out;
	}

	/**
	 * Graph recurrence -> RRULE parts (FREQ, INTERVAL, BYDAY as list, COUNT, UNTIL as Y-m-d string).
	 * Null for a missing or unsupported pattern (single occurrence).
	 */
	public static function graphToRrule(?array $recurrence): ?array {
		if (empty($recurrence)) {
			return null;
		}
		$pattern = $recurrence['pattern'] ?? [];
		$range = $recurrence['range'] ?? [];
		$type = $pattern['type'] ?? null;
		$interval = (int)($pattern['interval'] ?? 1) ?: 1;
		$rrule = [];
		switch ($type) {
			case 'daily':
				$rrule['FREQ'] = 'DAILY';
				break;
			case 'weekly':
				$rrule['FREQ'] = 'WEEKLY';
				$byday = self::byday($pattern['daysOfWeek'] ?? []);
				if ($byday !== []) {
					$rrule['BYDAY'] = $byday;
				}
				break;
			case 'absoluteMonthly':
				$rrule['FREQ'] = 'MONTHLY';
				if (!empty($pattern['dayOfMonth'])) {
					$rrule['BYMONTHDAY'] = [(int)$pattern['dayOfMonth']];
				}
				break;
			case 'relativeMonthly':
				$rrule['FREQ'] = 'MONTHLY';
				$rrule['BYDAY'] = self::byday($pattern['daysOfWeek'] ?? []);
				$rrule['BYSETPOS'] = [self::INDEX[$pattern['index'] ?? 'first'] ?? 1];
				break;
			case 'absoluteYearly':
				$rrule['FREQ'] = 'YEARLY';
				if (!empty($pattern['month'])) {
					$rrule['BYMONTH'] = [(int)$pattern['month']];
				}
				if (!empty($pattern['dayOfMonth'])) {
					$rrule['BYMONTHDAY'] = [(int)$pattern['dayOfMonth']];
				}
				break;
			case 'relativeYearly':
				$rrule['FREQ'] = 'YEARLY';
				if (!empty($pattern['month'])) {
					$rrule['BYMONTH'] = [(int)$pattern['month']];
				}
				$rrule['BYDAY'] = self::byday($pattern['daysOfWeek'] ?? []);
				$rrule['BYSETPOS'] = [self::INDEX[$pattern['index'] ?? 'first'] ?? 1];
				break;
			default:
				return null;
		}
		if ($interval !== 1) {
			$rrule['INTERVAL'] = $interval;
		}
		$fdow = $pattern['firstDayOfWeek'] ?? null;
		if ($type === 'weekly' && $fdow !== null && $fdow !== 'sunday' && in_array($fdow, self::DAYS, true)) {
			$rrule['WKST'] = self::ICAL_DAYS[array_search($fdow, self::DAYS, true)];
		}
		$rtype = $range['type'] ?? null;
		if ($rtype === 'numbered' && !empty($range['numberOfOccurrences'])) {
			$rrule['COUNT'] = (int)$range['numberOfOccurrences'];
		} elseif ($rtype === 'endDate' && !empty($range['endDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$range['endDate'])) {
			$rrule['UNTIL'] = (string)$range['endDate'];
		}
		return $rrule;
	}

	/** RRULE parts as an iCalendar string ("FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;COUNT=10"). */
	public static function rruleToString(array $rrule): string {
		$parts = [];
		foreach ($rrule as $k => $v) {
			if ($k === 'UNTIL') {
				$v = str_replace('-', '', (string)$v);
			}
			$parts[] = (string)$k . '=' . (is_array($v) ? implode(',', $v) : (string)$v);
		}
		return implode(';', $parts);
	}

	/**
	 * RRULE parts (as sabre's Recur::getParts() delivers them) -> Graph recurrence.
	 *
	 * @param string $startDate Y-m-d of DTSTART (Graph requires range.startDate)
	 */
	public static function rruleToGraph(?array $rrule, string $startDate): ?array {
		if (empty($rrule)) {
			return null;
		}
		$get = static fn (string $k) => $rrule[$k] ?? null;
		$list = static function ($v): array {
			if ($v === null) {
				return [];
			}
			return is_array($v) ? array_values($v) : explode(',', (string)$v);
		};
		$first = static fn ($v) => is_array($v) ? ($v[0] ?? null) : $v;

		$freq = strtoupper((string)($first($get('FREQ')) ?? ''));
		$interval = max(1, (int)($first($get('INTERVAL')) ?? 1));
		$byday = array_map('strtoupper', $list($get('BYDAY')));
		$bysetpos = array_map('intval', $list($get('BYSETPOS')));
		$start = new \DateTimeImmutable($startDate . ' 00:00:00', new \DateTimeZone('UTC'));
		$startDow = (int)$start->format('N') - 1; // 0 = Monday
		$pattern = ['interval' => $interval];

		$days = static function (array $codes): array {
			$out = [];
			foreach ($codes as $code) {
				$plain = ltrim($code, '+-0123456789');
				$i = array_search($plain, self::ICAL_DAYS, true);
				if ($i !== false) {
					$out[] = self::DAYS[$i];
				}
			}
			return $out;
		};
		$ordinal = static function (array $codes): ?int {
			$ords = [];
			foreach ($codes as $code) {
				$num = substr($code, 0, strlen($code) - 2);
				if ($num !== '' && $num !== '+') {
					$ords[(int)$num] = true;
				}
			}
			return count($ords) === 1 ? array_key_first($ords) : null;
		};
		$indexRev = array_flip(self::INDEX);

		switch ($freq) {
			case 'DAILY':
				$pattern['type'] = 'daily';
				break;
			case 'WEEKLY':
				$pattern['type'] = 'weekly';
				$dow = $days($byday);
				$pattern['daysOfWeek'] = $dow !== [] ? $dow : [self::DAYS[$startDow]];
				$wkst = strtoupper((string)($first($get('WKST')) ?? ''));
				if (in_array($wkst, self::ICAL_DAYS, true)) {
					$pattern['firstDayOfWeek'] = self::DAYS[array_search($wkst, self::ICAL_DAYS, true)];
				}
				break;
			case 'MONTHLY':
				$pos = count($bysetpos) === 1 ? $bysetpos[0] : $ordinal($byday);
				if ($byday !== [] && $pos !== null && isset($indexRev[$pos])) {
					$pattern['type'] = 'relativeMonthly';
					$pattern['daysOfWeek'] = $days($byday);
					$pattern['index'] = $indexRev[$pos];
				} elseif ($byday !== []) {
					return null;
				} else {
					$pattern['type'] = 'absoluteMonthly';
					$pattern['dayOfMonth'] = (int)($first($get('BYMONTHDAY')) ?? $start->format('j'));
				}
				break;
			case 'YEARLY':
				$month = (int)($first($get('BYMONTH')) ?? $start->format('n'));
				$pos = count($bysetpos) === 1 ? $bysetpos[0] : $ordinal($byday);
				if ($byday !== [] && $pos !== null && isset($indexRev[$pos])) {
					$pattern['type'] = 'relativeYearly';
					$pattern['daysOfWeek'] = $days($byday);
					$pattern['index'] = $indexRev[$pos];
					$pattern['month'] = $month;
				} elseif ($byday !== []) {
					return null;
				} else {
					$pattern['type'] = 'absoluteYearly';
					$pattern['month'] = $month;
					$pattern['dayOfMonth'] = (int)($first($get('BYMONTHDAY')) ?? $start->format('j'));
				}
				break;
			default:
				return null;
		}

		$range = ['startDate' => $startDate, 'recurrenceTimeZone' => 'UTC'];
		$count = $first($get('COUNT'));
		$until = $first($get('UNTIL'));
		if ($count) {
			$range['type'] = 'numbered';
			$range['numberOfOccurrences'] = (int)$count;
		} elseif ($until) {
			$u = (string)$until;
			if ($until instanceof \DateTimeInterface) {
				$u = $until->format('Ymd');
			}
			$range['type'] = 'endDate';
			$range['endDate'] = substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2);
		} else {
			$range['type'] = 'noEnd';
		}
		return ['pattern' => $pattern, 'range' => $range];
	}
}
