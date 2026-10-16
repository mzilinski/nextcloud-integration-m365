<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The German translation is maintained by hand (tools/l10n-de.py) until the app is on
 * Transifex: every source string in lib/ and src/ must be translated, no translation may
 * refer to a string that no longer exists, and placeholders must survive the translation.
 */
class L10nTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';

	/** @return array<string,true> source strings keyed like the l10n files */
	private static function sourceStrings(): array {
		$keys = [];
		$files = array_merge(self::files(self::ROOT . '/lib', 'php'), self::files(self::ROOT . '/src', 'vue'), self::files(self::ROOT . '/src', 'js'));
		$str = "'((?:[^'\\\\]|\\\\.)*)'";
		foreach ($files as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all("/(?:\\bt\\(\\s*'integration_m365',\\s*|->t\\(\\s*)$str/", $code, $m);
			foreach ($m[1] as $s) {
				$keys[stripslashes($s)] = true;
			}
			preg_match_all("/(?:\\bn\\(\\s*'integration_m365',\\s*|->n\\(\\s*)$str,\\s*$str/", $code, $m);
			foreach ($m[1] as $i => $s) {
				$keys['_' . stripslashes($s) . '_::_' . stripslashes($m[2][$i]) . '_'] = true;
			}
		}
		return $keys;
	}

	/** @return list<string> */
	private static function files(string $dir, string $ext): array {
		$out = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
			if ($f->isFile() && $f->getExtension() === $ext) {
				$out[] = $f->getPathname();
			}
		}
		return $out;
	}

	/** @return list<array{string}> */
	public static function languages(): array {
		$out = [];
		foreach (glob(self::ROOT . '/l10n/*.json') ?: [] as $f) {
			$out[basename($f, '.json')] = [basename($f, '.json')];
		}
		return array_values($out);
	}

	/** @dataProvider languages */
	public function testTranslationIsCompleteAndConsistent(string $lang): void {
		$json = json_decode((string)file_get_contents(self::ROOT . "/l10n/$lang.json"), true);
		self::assertIsArray($json);
		self::assertArrayHasKey('pluralForm', $json);
		$translations = $json['translations'];
		$source = self::sourceStrings();
		self::assertNotEmpty($source);

		$missing = array_diff_key($source, $translations);
		self::assertSame([], array_keys($missing), "$lang: untranslated strings");
		$stale = array_diff_key($translations, $source);
		self::assertSame([], array_keys($stale), "$lang: translations without a source string");

		foreach ($translations as $key => $value) {
			$sources = str_starts_with($key, '_') && str_contains($key, '_::_') ? explode('_::_', trim($key, '_')) : [$key];
			$targets = is_array($value) ? $value : [$value];
			self::assertCount(count($sources), $targets, "$lang: plural form count for $key");
			foreach ($targets as $i => $target) {
				self::assertNotSame('', $target, "$lang: empty translation for $key");
				self::assertSame(self::placeholders($sources[$i] ?? $sources[0]), self::placeholders($target), "$lang: placeholders differ in \"$key\"");
			}
		}

		// the .js twin must carry the same table
		$js = (string)file_get_contents(self::ROOT . "/l10n/$lang.js");
		self::assertStringContainsString('OC.L10N.register(', $js);
		self::assertStringContainsString(json_encode(array_key_first($translations), JSON_UNESCAPED_UNICODE), $js);
	}

	/** @return list<string> sorted placeholders like {name}, %s, %n */
	private static function placeholders(string $s): array {
		preg_match_all('/\{[a-zA-Z0-9_]+\}|%[sdn]/', $s, $m);
		$p = $m[0];
		sort($p);
		return $p;
	}
}
