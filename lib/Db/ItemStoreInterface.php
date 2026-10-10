<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Db;

/** Persistence of item correlations, abstracted so the engine can run on in-memory doubles. */
interface ItemStoreInterface {
	/** @return list<Item> */
	public function findByCollection(int $collectionId): array;

	public function insertItem(Item $item): Item;

	public function updateItem(Item $item): Item;

	public function deleteItem(Item $item): void;

	public function deleteByCollection(int $collectionId): void;
}
