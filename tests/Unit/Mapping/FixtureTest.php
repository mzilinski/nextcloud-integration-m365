<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Mapping;

use OCA\IntegrationM365\Service\Mapping\ContactMapper;
use OCA\IntegrationM365\Service\Mapping\EventMapper;
use OCA\IntegrationM365\Service\Mapping\TodoMapper;
use PHPUnit\Framework\TestCase;

/**
 * Runs the language-neutral conformance fixtures (tests/fixtures, copied from
 * vdirsyncer-graph) against the PHP mappers. Comparison rules: fixtures/README.md.
 */
class FixtureTest extends TestCase {
	private const IGNORED = ['DTSTAMP', 'PRODID', 'VERSION', 'CALSCALE'];

	public static function fixtures(): iterable {
		foreach (['events', 'contacts', 'todos'] as $kind) {
			foreach (glob(__DIR__ . '/../../fixtures/' . $kind . '/*.json') ?: [] as $file) {
				yield "$kind/" . basename($file, '.json') => [$kind, json_decode((string)file_get_contents($file), true)];
			}
		}
	}

	/** @dataProvider fixtures */
	public function testFixture(string $kind, array $fx): void {
		$direction = $fx['direction'];
		$item = $fx['item'] === null ? null : implode("\r\n", $fx['item']) . "\r\n";
		if (in_array($direction, ['read', 'both'], true)) {
			$produced = match ($kind) {
				'events' => EventMapper::graphToIcal($fx['graph']),
				'todos' => TodoMapper::graphToIcal($fx['graph']),
				'contacts' => ContactMapper::graphToVcard($fx['graph'], isset($fx['photo']) ? [base64_decode($fx['photo']['base64']), $fx['photo']['mime']] : null),
			};
			self::assertSame(self::normalized($item), self::normalized($produced), $fx['description']);
		}
		if (in_array($direction, ['write', 'both'], true)) {
			$produced = match ($kind) {
				'events' => EventMapper::icalToGraph($item),
				'todos' => TodoMapper::icalToGraph($item),
				'contacts' => ContactMapper::vcardToGraph($item),
			};
			self::assertTrue(self::isSubset($fx['expect_write'], $produced), $fx['description'] . "\nexpected ⊆ " . json_encode($fx['expect_write'], JSON_UNESCAPED_UNICODE) . "\nactual " . json_encode($produced, JSON_UNESCAPED_UNICODE));
			foreach ($fx['expect_absent'] ?? [] as $key) {
				self::assertArrayNotHasKey($key, $produced, "$key must not be written: " . $fx['description']);
			}
		}
	}

	/** Unfolded, sorted lines without volatile properties; RRULE parts compared as a set. */
	private static function normalized(?string $text): array {
		if ($text === null) {
			return [];
		}
		$unfolded = [];
		foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
			if ($line === '') {
				continue;
			}
			if (($line[0] === ' ' || $line[0] === "\t") && $unfolded !== []) {
				$unfolded[count($unfolded) - 1] .= substr($line, 1);
			} else {
				$unfolded[] = $line;
			}
		}
		$keep = [];
		foreach ($unfolded as $line) {
			$name = explode(';', explode(':', $line, 2)[0], 2)[0];
			if (in_array($name, self::IGNORED, true)) {
				continue;
			}
			if ($name === 'TRIGGER') {
				// RFC 5545 requires VALUE=DATE-TIME on absolute triggers; some libraries omit it.
				$line = str_replace('TRIGGER;VALUE=DATE-TIME:', 'TRIGGER:', $line);
			}
			if ($name === 'RRULE') {
				$parts = explode(';', explode(':', $line, 2)[1]);
				sort($parts);
				$line = 'RRULE:' . implode(';', $parts);
			}
			$keep[] = $line;
		}
		sort($keep);
		return $keep;
	}

	private static function isSubset(mixed $expected, mixed $actual): bool {
		if (is_array($expected) && !array_is_list($expected)) {
			if (!is_array($actual)) {
				return false;
			}
			foreach ($expected as $k => $v) {
				if (!array_key_exists($k, $actual) || !self::isSubset($v, $actual[$k])) {
					return false;
				}
			}
			return true;
		}
		if (is_array($expected)) {
			if (!is_array($actual) || count($expected) !== count($actual)) {
				return false;
			}
			foreach ($expected as $i => $v) {
				if (!self::isSubset($v, $actual[$i])) {
					return false;
				}
			}
			return true;
		}
		return $expected === $actual || (is_numeric($expected) && is_numeric($actual) && $expected == $actual);
	}
}
