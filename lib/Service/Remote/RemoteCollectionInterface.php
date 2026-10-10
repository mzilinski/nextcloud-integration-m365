<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

/** One Graph collection (calendar, contact folder, To Do list), bound to a user. */
interface RemoteCollectionInterface {
	/**
	 * Delta since a stored deltaLink. An expired link triggers a full listing (full = true):
	 * then everything not in `items` is gone.
	 *
	 * @return array{items:array<string,array>, removed:list<string>, deltaLink:?string, full:bool}
	 */
	public function changes(?string $deltaLink): array;

	public function get(string $id): ?array;

	/** @return array the created object as returned by Graph (id, changeKey, …) */
	public function create(array $body): array;

	/** @return array the updated object */
	public function update(string $id, array $body): array;

	/** Deleting something that is already gone is not an error. */
	public function delete(string $id): void;

	public function etagOf(array $json): string;

	/**
	 * Fetch decoration for changed objects (contact photos). Returns extra per id,
	 * e.g. ['photo' => [bytes, mime]].
	 *
	 * @param list<string> $ids
	 * @return array<string,array>
	 */
	public function extrasFor(array $ids): array;

	/** Push decoration after a local->remote write (contact photo). */
	public function afterWrite(string $id, ?array $photo): void;
}
