<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Exception;

/** A Microsoft Graph request failed. */
class GraphException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $httpStatus = 0,
		public readonly string $graphCode = '',
		?\Throwable $previous = null,
	) {
		parent::__construct($message, $httpStatus, $previous);
	}
}
