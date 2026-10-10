<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

use DateTimeImmutable;
use Sabre\VObject\Component;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Property;

/**
 * Graph ``todoTask`` (Microsoft To Do) <-> VTODO. Port of graphpim/todos.py.
 *
 * UID = Graph id; naive timestamps with their own zone (IANA or Windows); To Do keeps
 * only the date of start/due (midnight values become DATE); importance <-> PRIORITY;
 * reminder <-> one absolute DISPLAY VALARM; body only written when a DESCRIPTION exists.
 */
final class TodoMapper {
	private static function dateIfMidnight(?DateTimeImmutable $dt): ?array {
		if ($dt === null) {
			return null;
		}
		return [$dt, $dt->format('H:i:s') === '00:00:00'];
	}

	public static function graphToIcal(array $json): ?string {
		$gid = (string)($json['id'] ?? '');
		if ($gid === '') {
			return null;
		}
		$cal = IcalHelper::newCalendar();
		$todo = $cal->createComponent('VTODO', [], false);
		$cal->add($todo);
		$todo->add('UID', $gid);
		$todo->add('DTSTAMP', IcalHelper::stamp());
		$todo->add('SUMMARY', (string)($json['title'] ?? ''));
		$body = $json['body'] ?? [];
		if (($body['contentType'] ?? '') === 'text' && ($body['content'] ?? '') !== '') {
			$todo->add('DESCRIPTION', (string)$body['content']);
		}
		foreach (['startDateTime' => 'DTSTART', 'dueDateTime' => 'DUE'] as $key => $prop) {
			$v = self::dateIfMidnight(GraphDateTime::parse($json[$key] ?? null));
			if ($v === null) {
				continue;
			}
			$v[1] ? IcalHelper::addDate($todo, $prop, $v[0]->format('Y-m-d')) : $todo->add($prop, $v[0]);
		}
		$status = $json['status'] ?? null;
		if ($status === 'completed') {
			$todo->add('STATUS', 'COMPLETED');
			$completed = GraphDateTime::parse($json['completedDateTime'] ?? null);
			if ($completed !== null) {
				$todo->add('COMPLETED', IcalHelper::asUtc($completed));
			}
			$todo->add('PERCENT-COMPLETE', 100);
		} elseif ($status === 'inProgress') {
			$todo->add('STATUS', 'IN-PROCESS');
		} else {
			$todo->add('STATUS', 'NEEDS-ACTION');
		}
		$importance = $json['importance'] ?? null;
		if ($importance === 'high') {
			$todo->add('PRIORITY', 1);
		} elseif ($importance === 'low') {
			$todo->add('PRIORITY', 9);
		}
		if (!empty($json['isReminderOn'])) {
			$reminder = GraphDateTime::parse($json['reminderDateTime'] ?? null);
			if ($reminder !== null) {
				$alarm = $cal->createComponent('VALARM');
				$alarm->add('ACTION', 'DISPLAY');
				$alarm->add('DESCRIPTION', (string)($json['title'] ?? ''));
				$alarm->add('TRIGGER', IcalHelper::asUtc($reminder), ['VALUE' => 'DATE-TIME']);
				$todo->add($alarm);
			}
		}
		$cats = array_values(array_filter(array_map('strval', $json['categories'] ?? []), static fn ($c) => $c !== ''));
		if ($cats !== []) {
			$todo->add('CATEGORIES', $cats);
		}
		$rrule = RecurrenceMapper::graphToRrule($json['recurrence'] ?? null);
		if ($rrule !== null) {
			$todo->add('RRULE', RecurrenceMapper::rruleToString($rrule));
		}
		return $cal->serialize();
	}

	public static function todoToGraph(Component $todo): array {
		$json = ['title' => IcalHelper::text($todo, 'SUMMARY')];
		$description = IcalHelper::text($todo, 'DESCRIPTION');
		if ($description !== '') {
			$json['body'] = ['contentType' => 'text', 'content' => $description];
		}
		$start = IcalHelper::decoded($todo, 'DTSTART');
		$due = IcalHelper::decoded($todo, 'DUE');
		if ($start !== null) {
			$json['startDateTime'] = $start[1] ? GraphDateTime::formatDate($start[0]->format('Y-m-d')) : GraphDateTime::format($start[0]);
		}
		if ($due !== null) {
			$json['dueDateTime'] = $due[1] ? GraphDateTime::formatDate($due[0]->format('Y-m-d')) : GraphDateTime::format($due[0]);
		}
		$status = strtoupper(IcalHelper::text($todo, 'STATUS'));
		$completed = IcalHelper::decoded($todo, 'COMPLETED');
		$percent = IcalHelper::prop($todo, 'PERCENT-COMPLETE');
		if ($status === 'COMPLETED' || $completed !== null) {
			$json['status'] = 'completed';
		} elseif ($status === 'IN-PROCESS' || ($percent !== null && (int)$percent->getValue() > 0)) {
			$json['status'] = 'inProgress';
		} else {
			$json['status'] = 'notStarted';
		}
		$priority = IcalHelper::prop($todo, 'PRIORITY') !== null ? (int)IcalHelper::prop($todo, 'PRIORITY')->getValue() : 0;
		$json['importance'] = ($priority >= 1 && $priority <= 3) ? 'high' : ($priority >= 7 ? 'low' : 'normal');

		$reminder = null;
		foreach ($todo->select('VALARM') as $alarm) {
			$trigger = IcalHelper::prop($alarm, 'TRIGGER');
			if ($trigger === null) {
				continue;
			}
			if ($trigger instanceof Property\ICalendar\DateTime) {
				$reminder = DateTimeImmutable::createFromInterface($trigger->getDateTime(new \DateTimeZone('UTC')));
				break;
			}
			$base = $due[0] ?? $start[0] ?? null;
			if ($base !== null) {
				$reminder = $base->add(DateTimeParser::parseDuration((string)$trigger));
				break;
			}
		}
		if ($reminder !== null) {
			$json['isReminderOn'] = true;
			$json['reminderDateTime'] = GraphDateTime::format($reminder);
		} else {
			$json['isReminderOn'] = false;
		}
		$cats = IcalHelper::categories($todo);
		if ($cats !== []) {
			$json['categories'] = $cats;
		}
		$base = $start ?? $due;
		if ($base !== null && IcalHelper::prop($todo, 'RRULE') !== null) {
			$recurrence = RecurrenceMapper::rruleToGraph(IcalHelper::prop($todo, 'RRULE')->getParts(), $base[0]->format('Y-m-d'));
			if ($recurrence !== null) {
				$json['recurrence'] = $recurrence;
			}
		}
		return $json;
	}

	public static function icalToGraph(string $raw): array {
		return self::todoToGraph(IcalHelper::firstComponent($raw, 'VTODO'));
	}
}
