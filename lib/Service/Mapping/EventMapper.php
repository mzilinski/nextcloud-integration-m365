<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

use DateInterval;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;

/**
 * Graph ``event`` <-> VEVENT. Port of graphpim/events.py (see fixtures/README.md).
 *
 * UID = iCalUId (fallback: Graph id); text bodies only (HTML -> bodyPreview);
 * UTC times, all-day as DATE with Graph's exclusive end used as-is;
 * showAs free <-> TRANSP:TRANSPARENT; sensitivity <-> CLASS; attendee status <-> PARTSTAT.
 */
final class EventMapper {
	private const RESPONSE_TO_PARTSTAT = [
		'accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentativelyAccepted' => 'TENTATIVE',
		'notResponded' => 'NEEDS-ACTION', 'none' => 'NEEDS-ACTION', 'organizer' => 'ACCEPTED',
	];
	private const PARTSTAT_TO_RESPONSE = ['ACCEPTED' => 'accepted', 'DECLINED' => 'declined', 'TENTATIVE' => 'tentativelyAccepted'];

	/** Graph event JSON -> VCALENDAR text, or null when the JSON has no id. */
	public static function graphToIcal(array $json): ?string {
		$gid = (string)($json['id'] ?? '');
		if ($gid === '') {
			return null;
		}
		$cal = IcalHelper::newCalendar();
		$ev = $cal->createComponent('VEVENT', [], false);
		$cal->add($ev);
		$ev->add('UID', (string)(($json['iCalUId'] ?? '') ?: $gid));
		$ev->add('DTSTAMP', IcalHelper::stamp());
		$ev->add('SUMMARY', (string)($json['subject'] ?? ''));

		$body = $json['body'] ?? [];
		$description = ($body['contentType'] ?? '') === 'text' ? (string)($body['content'] ?? '') : (string)($json['bodyPreview'] ?? '');
		if ($description !== '') {
			$ev->add('DESCRIPTION', $description);
		}

		$allDay = (bool)($json['isAllDay'] ?? false);
		$start = GraphDateTime::parse($json['start'] ?? null, $allDay);
		$end = GraphDateTime::parse($json['end'] ?? null, $allDay);
		if ($start !== null) {
			$allDay ? IcalHelper::addDate($ev, 'DTSTART', $start->format('Y-m-d')) : $ev->add('DTSTART', $start);
		}
		if ($end !== null) {
			$allDay ? IcalHelper::addDate($ev, 'DTEND', $end->format('Y-m-d')) : $ev->add('DTEND', $end);
		}

		$location = (string)(($json['location'] ?? [])['displayName'] ?? '');
		if ($location !== '') {
			$ev->add('LOCATION', $location);
		}
		$organizer = ($json['organizer'] ?? [])['emailAddress'] ?? [];
		if (!empty($organizer['address'])) {
			$params = [];
			if (!empty($organizer['name'])) {
				$params['CN'] = (string)$organizer['name'];
			}
			$ev->add('ORGANIZER', 'mailto:' . $organizer['address'], $params);
		}
		foreach ($json['attendees'] ?? [] as $att) {
			$email = $att['emailAddress'] ?? [];
			if (empty($email['address'])) {
				continue;
			}
			$params = [];
			if (!empty($email['name'])) {
				$params['CN'] = (string)$email['name'];
			}
			$response = ($att['status'] ?? [])['response'] ?? null;
			if ($response !== null && isset(self::RESPONSE_TO_PARTSTAT[$response])) {
				$params['PARTSTAT'] = self::RESPONSE_TO_PARTSTAT[$response];
			}
			$type = $att['type'] ?? null;
			if ($type === 'optional') {
				$params['ROLE'] = 'OPT-PARTICIPANT';
			} elseif ($type === 'resource') {
				$params['CUTYPE'] = 'RESOURCE';
			}
			$ev->add('ATTENDEE', 'mailto:' . $email['address'], $params);
		}
		$cats = array_values(array_filter(array_map('strval', $json['categories'] ?? []), static fn ($c) => $c !== ''));
		if ($cats !== []) {
			$ev->add('CATEGORIES', $cats);
		}
		$ev->add('TRANSP', ($json['showAs'] ?? '') === 'free' ? 'TRANSPARENT' : 'OPAQUE');
		$sensitivity = $json['sensitivity'] ?? null;
		if ($sensitivity === 'private') {
			$ev->add('CLASS', 'PRIVATE');
		} elseif ($sensitivity === 'confidential') {
			$ev->add('CLASS', 'CONFIDENTIAL');
		}
		if (!empty($json['isCancelled'])) {
			$ev->add('STATUS', 'CANCELLED');
		}
		$rrule = RecurrenceMapper::graphToRrule($json['recurrence'] ?? null);
		if ($rrule !== null) {
			$ev->add('RRULE', RecurrenceMapper::rruleToString($rrule));
		}
		return $cal->serialize();
	}

	/** VEVENT component -> Graph event body for POST/PATCH. */
	public static function eventToGraph(Component $ev): array {
		$json = ['subject' => IcalHelper::text($ev, 'SUMMARY')];
		$json['body'] = ['contentType' => 'text', 'content' => IcalHelper::text($ev, 'DESCRIPTION')];

		$start = IcalHelper::decoded($ev, 'DTSTART');
		$end = IcalHelper::decoded($ev, 'DTEND');
		$allDay = $start !== null && $start[1];
		$json['isAllDay'] = $allDay;
		if ($start !== null) {
			[$startDt] = $start;
			$endDt = $end[0] ?? null;
			if ($endDt === null) {
				$duration = IcalHelper::prop($ev, 'DURATION');
				if ($duration !== null) {
					$endDt = $startDt->add(DateTimeParser::parseDuration((string)$duration));
				} elseif ($allDay) {
					$endDt = $startDt->add(new DateInterval('P1D'));
				} else {
					$endDt = $startDt;
				}
			}
			if ($allDay) {
				$json['start'] = GraphDateTime::formatDate($startDt->format('Y-m-d'));
				$json['end'] = GraphDateTime::formatDate($endDt->format('Y-m-d'));
			} else {
				$json['start'] = GraphDateTime::format($startDt);
				$json['end'] = GraphDateTime::format($endDt);
			}
		}
		$location = IcalHelper::text($ev, 'LOCATION');
		if ($location !== '') {
			$json['location'] = ['displayName' => $location];
		}
		$cats = IcalHelper::categories($ev);
		if ($cats !== []) {
			$json['categories'] = $cats;
		}
		$json['showAs'] = strtoupper(IcalHelper::text($ev, 'TRANSP')) === 'TRANSPARENT' ? 'free' : 'busy';
		$class = strtoupper(IcalHelper::text($ev, 'CLASS'));
		if ($class === 'PRIVATE') {
			$json['sensitivity'] = 'private';
		} elseif ($class === 'CONFIDENTIAL') {
			$json['sensitivity'] = 'confidential';
		} elseif ($class !== '') {
			$json['sensitivity'] = 'normal';
		}
		$attendees = [];
		foreach ($ev->select('ATTENDEE') as $a) {
			$address = (string)$a->getValue();
			if (stripos($address, 'mailto:') === 0) {
				$address = substr($address, 7);
			}
			if ($address === '') {
				continue;
			}
			$entry = ['emailAddress' => ['address' => $address]];
			if (isset($a['CN'])) {
				$entry['emailAddress']['name'] = (string)$a['CN'];
			}
			$partstat = strtoupper((string)($a['PARTSTAT'] ?? ''));
			if (isset(self::PARTSTAT_TO_RESPONSE[$partstat])) {
				$entry['status'] = ['response' => self::PARTSTAT_TO_RESPONSE[$partstat], 'time' => '0001-01-01T00:00:00Z'];
			}
			$role = strtoupper((string)($a['ROLE'] ?? ''));
			$cutype = strtoupper((string)($a['CUTYPE'] ?? ''));
			$entry['type'] = $cutype === 'RESOURCE' ? 'resource' : ($role === 'OPT-PARTICIPANT' ? 'optional' : 'required');
			$attendees[] = $entry;
		}
		if ($attendees !== []) {
			$json['attendees'] = $attendees;
		}
		if ($start !== null && IcalHelper::prop($ev, 'RRULE') !== null) {
			$recurrence = RecurrenceMapper::rruleToGraph(IcalHelper::prop($ev, 'RRULE')->getParts(), $start[0]->format('Y-m-d'));
			if ($recurrence !== null) {
				$json['recurrence'] = $recurrence;
			}
		}
		return $json;
	}

	public static function icalToGraph(string $raw): array {
		return self::eventToGraph(IcalHelper::firstComponent($raw, 'VEVENT'));
	}
}
