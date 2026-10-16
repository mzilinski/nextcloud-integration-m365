<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Exception;

/**
 * Entra ID rejected the app registration itself (bad secret, unknown client id, wrong
 * tenant). Only the administrator can fix this; users must not be asked to reconnect.
 */
class ConfigurationException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly string $graphCode,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
