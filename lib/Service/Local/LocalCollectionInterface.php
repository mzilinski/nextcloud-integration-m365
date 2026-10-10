<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Local;

/** One local CalDAV calendar or CardDAV address book, bound to a user and collection id. */
interface LocalCollectionInterface {
	/**
	 * Changes since a sync token. A null token lists everything as "added" (full = true).
	 *
	 * @return array{token:int, added:list<string>, modified:list<string>, deleted:list<string>, full:bool}
	 */
	public function changes(?int $syncToken): array;

	/** @return array{data:string, etag:string}|null */
	public function get(string $uri): ?array;

	/** @return string etag */
	public function create(string $uri, string $data): string;

	/** @return string etag */
	public function update(string $uri, string $data): string;

	public function delete(string $uri): void;

	/** URI of the object carrying this UID inside this collection, if any. */
	public function findUriByUid(string $uid): ?string;
}
