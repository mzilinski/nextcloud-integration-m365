<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Service;

use OCA\IntegrationM365\Service\EntraErrors;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class EntraErrorsTest extends TestCase {
	private IL10N $l;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $t, $p = []) => $p === [] ? $t : vsprintf($t, (array)$p));
		$this->l = $l;
	}

	/** @return list<array{string,string,string}> error, description, expected fragment */
	public static function cases(): array {
		return [
			['invalid_request', 'AADSTS50011: The redirect URI https://x does not match. Trace ID: 1 Correlation ID: 2 Timestamp: 3', 'https://nc/redirect under "Authentication'],
			['invalid_client', 'AADSTS7000215: Invalid client secret provided.', 'secret is wrong'],
			['invalid_client', 'AADSTS7000222: The provided client secret keys are expired.', 'has expired'],
			['unauthorized_client', "AADSTS700016: Application with identifier 'x' was not found in the directory", 'not found in this tenant'],
			['invalid_grant', 'AADSTS65001: The user or administrator has not consented', 'Consent is missing'],
			['invalid_grant', 'AADSTS50173: The provided grant has expired due to it being revoked', 'Connect the account again'],
			['invalid_request', 'AADSTS90002: Tenant not found', 'tenant was not found'],
			['access_denied', '', 'cancelled'],
			['invalid_client', '', 'rejected the client ID or secret'],
		];
	}

	/** @dataProvider cases */
	public function testKnownCodesGetAnActionableHintWithTheNumberKept(string $error, string $description, string $expected): void {
		$text = EntraErrors::describe($error, $description, $this->l, 'https://nc/redirect');
		self::assertStringContainsString($expected, $text);
		$code = EntraErrors::code($description);
		if ($code !== null) {
			self::assertStringContainsString("AADSTS$code", $text);
		}
		self::assertStringNotContainsString('Trace ID', $text);
	}

	public function testUnknownErrorsPassTheDescriptionThroughWithoutTraceNoise(): void {
		self::assertSame('AADSTS99999: something new', EntraErrors::describe('server_error', 'AADSTS99999: something new Trace ID: abc', $this->l));
		self::assertSame('temporarily_unavailable', EntraErrors::describe('temporarily_unavailable', '', $this->l));
		self::assertNull(EntraErrors::code('no code here'));
	}
}
