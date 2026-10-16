<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Sync;

/**
 * Machine-readable values of m365_collections.last_error. The UI translates them;
 * anything else in that column is a raw exception message for the admin.
 */
final class SyncError {
	/** Microsoft rejected the refresh token: the user has to connect again. */
	public const REAUTH_REQUIRED = 'reauth_required';
	/** Entra ID rejects the client id / secret / tenant: the admin has to fix the settings. */
	public const CONFIG_ERROR = 'config_error';
	/** The local calendar / address book was deleted; the pair was disabled. */
	public const LOCAL_MISSING = 'local_missing';
	/** Prefix: "<n> item(s) failed", details in the log. */
	public const ITEMS_FAILED = 'items_failed:';
}
