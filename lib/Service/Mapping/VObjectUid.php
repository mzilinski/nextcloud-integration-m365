<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

use Sabre\VObject\Component;
use Sabre\VObject\Document;
use Sabre\VObject\Reader;

/** UID helpers shared by the item mappers. */
final class VObjectUid {
	public static function uidOf(string $data): ?string {
		try {
			$doc = Reader::read($data, Reader::OPTION_FORGIVING);
		} catch (\Exception) {
			return null;
		}
		foreach (self::entities($doc) as $comp) {
			$uid = IcalHelper::prop($comp, 'UID');
			if ($uid !== null && trim((string)$uid->getValue()) !== '') {
				return trim((string)$uid->getValue());
			}
		}
		return null;
	}

	public static function withUid(string $data, string $uid): string {
		$doc = Reader::read($data, Reader::OPTION_FORGIVING);
		foreach (self::entities($doc) as $comp) {
			$comp->remove('UID');
			$comp->add('UID', $uid);
		}
		return $doc->serialize();
	}

	/** @return list<Component> */
	private static function entities(Document $doc): array {
		if ($doc->name === 'VCARD') {
			return [$doc];
		}
		$out = [];
		foreach (['VEVENT', 'VTODO', 'VJOURNAL'] as $name) {
			foreach ($doc->select($name) as $comp) {
				$out[] = $comp;
			}
		}
		return $out;
	}
}
