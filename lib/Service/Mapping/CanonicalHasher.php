<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

/**
 * Content hash over the mapped Graph projection of an item. Both sides are reduced
 * to the same lossy JSON, so equal hashes mean "nothing we can sync has changed".
 */
final class CanonicalHasher {
	public static function hash(array $graphBody, ?string $photoBytes = null): string {
		self::sortRecursive($graphBody);
		$json = json_encode($graphBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return hash('sha256', ($json === false ? '' : $json) . '|' . ($photoBytes !== null ? hash('sha1', $photoBytes) : ''));
	}

	private static function sortRecursive(array &$a): void {
		if (array_is_list($a)) {
			foreach ($a as &$v) {
				if (is_array($v)) {
					self::sortRecursive($v);
				}
			}
			return;
		}
		ksort($a);
		foreach ($a as &$v) {
			if (is_array($v)) {
				self::sortRecursive($v);
			}
		}
	}
}
