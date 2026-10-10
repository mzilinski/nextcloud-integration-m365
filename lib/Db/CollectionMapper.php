<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<Collection> */
class CollectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'm365_collections', Collection::class);
	}

	/** @return Collection[] */
	public function findByUser(string $userId, ?string $type = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($type !== null) {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)));
		}
		$qb->orderBy('type')->addOrderBy('remote_name');
		return $this->findEntities($qb);
	}

	/** @return Collection[] */
	public function findEnabledByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function findByRemote(string $userId, string $type, string $remoteId): Collection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('remote_id', $qb->createNamedParameter($remoteId)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findForUser(string $userId, int $id): Collection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @return string[] user ids that have at least one enabled pair */
	public function findUsersWithEnabledPairs(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$users = [];
		while (($row = $result->fetch()) !== false) {
			$users[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $users;
	}

	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
