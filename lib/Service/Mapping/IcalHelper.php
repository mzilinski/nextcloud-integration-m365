<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/** Shared iCalendar helpers on top of sabre/vobject (bundled with Nextcloud). */
final class IcalHelper {
	public const PRODID = '-//integration_m365//Microsoft Graph mapping//EN';

	public static function newCalendar(): VCalendar {
		$cal = new VCalendar([], false);
		$cal->add('VERSION', '2.0');
		$cal->add('PRODID', self::PRODID);
		return $cal;
	}

	/** Parse text and return the first component of the given name (VEVENT/VTODO). */
	public static function firstComponent(string $raw, string $name): Component {
		$doc = Reader::read($raw, Reader::OPTION_FORGIVING);
		foreach ($doc->select($name) as $comp) {
			return $comp;
		}
		throw new \InvalidArgumentException("no $name component in item");
	}

	/** @return list<string> */
	public static function categories(Component $comp): array {
		$out = [];
		foreach ($comp->select('CATEGORIES') as $prop) {
			foreach ($prop->getParts() as $part) {
				$part = trim((string)$part);
				if ($part !== '') {
					$out[] = $part;
				}
			}
		}
		return $out;
	}

	/** First property with this name, typed (avoids sabre's magic __get). */
	public static function prop(Component $comp, string $name): ?Property {
		foreach ($comp->select($name) as $p) {
			return $p instanceof Property ? $p : null;
		}
		return null;
	}

	public static function text(Component $comp, string $name): string {
		$prop = self::prop($comp, $name);
		return $prop === null ? '' : (string)$prop->getValue();
	}

	/**
	 * Decode DTSTART/DTEND/DUE/COMPLETED.
	 *
	 * @return array{0: DateTimeImmutable, 1: bool}|null [datetime, isDateOnly]; floating times count as UTC
	 */
	public static function decoded(Component $comp, string $name): ?array {
		$prop = self::prop($comp, $name);
		if (!$prop instanceof Property\ICalendar\DateTime) {
			return null;
		}
		$dt = $prop->getDateTime(new DateTimeZone('UTC'));
		if ($dt === null) {
			return null;
		}
		$dateOnly = !$prop->hasTime();
		return [DateTimeImmutable::createFromInterface($dt), $dateOnly];
	}

	public static function asUtc(DateTimeImmutable $dt): DateTimeImmutable {
		return $dt->setTimezone(new DateTimeZone('UTC'));
	}

	public static function stamp(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}

	/** Add a DATE value (all-day) property. */
	public static function addDate(Component $comp, string $name, string $ymd): void {
		$comp->add($name, new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('UTC')), ['VALUE' => 'DATE']);
	}
}
