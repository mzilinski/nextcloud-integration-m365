<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Exception;

/** No usable token: the user has to (re)connect their Microsoft account. */
class TokenException extends \RuntimeException {
}
