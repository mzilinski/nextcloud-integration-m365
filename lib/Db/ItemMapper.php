<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Item> */
class ItemMapper extends QBMapper implements ItemStoreInterface {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'm365_items', Item::class);
	}

	/** @return list<Item> */
	public function findByCollection(int $collectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function insertItem(Item $item): Item {
		/** @var Item $saved */
		$saved = $this->insert($item);
		return $saved;
	}

	public function updateItem(Item $item): Item {
		/** @var Item $saved */
		$saved = $this->update($item);
		return $saved;
	}

	public function deleteItem(Item $item): void {
		$this->delete($item);
	}

	public function deleteByCollection(int $collectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
