<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service;

use OCP\IL10N;

/**
 * Turns Entra ID / OAuth error responses into something a user or admin can act on.
 * Microsoft's own descriptions are long, English and end with a trace id; the AADSTS
 * number inside is the stable part.
 */
final class EntraErrors {
	/** The AADSTS number in an error description, or null. */
	public static function code(string $description): ?int {
		return preg_match('/AADSTS(\d+)/', $description, $m) === 1 ? (int)$m[1] : null;
	}

	/**
	 * @param string $error the OAuth `error` parameter / token endpoint `error` (may be empty)
	 * @param string $description the `error_description` (may be empty)
	 * @param string $redirectUri shown in the redirect-mismatch hint
	 */
	public static function describe(string $error, string $description, IL10N $l, string $redirectUri = ''): string {
		$code = self::code($description);
		$hint = match ($code) {
			50011 => $l->t('The redirect URI is not registered in the Entra ID app. Add %s under "Authentication → Web", exactly as shown.', [$redirectUri]),
			65001, 65004, 90094 => $l->t('Consent is missing: the user declined, or the tenant requires an administrator to grant consent for the application\'s Graph permissions first.'),
			700016 => $l->t('The application was not found in this tenant: check the client ID and the tenant setting (a single-tenant registration must use its directory ID, not "common").'),
			7000215 => $l->t('The client secret is wrong. Paste the secret\'s *value*, not its ID.'),
			7000222 => $l->t('The client secret has expired. Create a new secret in the Entra ID app and save it here.'),
			50020, 50194, 50059 => $l->t('This account type is not allowed by the registration\'s "Supported account types" or the tenant setting (personal account vs. organisation, single vs. multi tenant).'),
			53003 => $l->t('Blocked by a Conditional Access policy of the organisation.'),
			90002 => $l->t('The tenant was not found: check the tenant setting.'),
			650053, 650057, 70011 => $l->t('A requested permission (scope) is not configured on the application. Add the delegated Graph permissions listed in the admin documentation.'),
			50173, 50076, 50078 => $l->t('Microsoft rejected the stored login (password change, revoked session or multi-factor requirement). Connect the account again.'),
			default => null,
		};
		if ($hint === null && $error === 'access_denied') {
			$hint = $l->t('The login was cancelled or consent was declined.');
		}
		if ($hint === null && $error === 'invalid_client') {
			$hint = $l->t('Entra ID rejected the client ID or secret.');
		}
		$raw = $description !== '' ? $description : $error;
		// keep the AADSTS number for the admin, drop the trace/correlation id noise behind it
		$raw = preg_replace('/\s*(Trace ID|Correlation ID|Timestamp):.*$/s', '', $raw) ?? $raw;
		if ($hint === null) {
			return $raw;
		}
		return $code !== null ? sprintf('%s (AADSTS%d)', $hint, $code) : $hint;
	}
}
