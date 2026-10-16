<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Exception;

/**
 * The local calendar / address book of a pair is gone (deleted or in the trash bin).
 * The pair is disabled instead of silently recreating the collection and replaying
 * old cursors against it.
 */
class LocalCollectionMissingException extends \RuntimeException {
}
