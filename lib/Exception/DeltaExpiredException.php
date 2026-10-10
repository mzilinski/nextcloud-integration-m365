<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Exception;

/** The stored delta link is no longer accepted (HTTP 410 / ErrorInvalidSyncStateData): resync from scratch. */
class DeltaExpiredException extends GraphException {
}
