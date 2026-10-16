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

	/** @throws DoesNotExistException */
	public function find(int $id): Collection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<string> user ids that have at least one pair, enabled or not */
	public function findUsersWithPairs(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from($this->getTableName());
		$result = $qb->executeQuery();
		$users = [];
		while (($row = $result->fetch()) !== false) {
			$users[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $users;
	}

	/**
	 * Take the pair's lock if nobody holds it: one conditional UPDATE, so cron and
	 * "Sync now" cannot both pass a read-then-write check.
	 */
	public function tryLock(int $id, int $now, int $until): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('locked_until', $qb->createNamedParameter($until, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('locked_until'),
				$qb->expr()->lt('locked_until', $qb->createNamedParameter($now, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)),
			));
		return $qb->executeStatement() === 1;
	}

	/**
	 * @return string[] user ids that have at least one enabled pair, least recently synced
	 *                  first so that a run cut short by its time budget does not starve the
	 *                  same users every time
	 */
	public function findUsersWithEnabledPairs(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->createFunction('COALESCE(MIN(' . $qb->getColumnName('last_sync') . '), 0)'), 'oldest')
			->from($this->getTableName())
			->where($qb->expr()->eq('enabled', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
			->groupBy('user_id')
			->orderBy('oldest', 'ASC')
			->addOrderBy('user_id', 'ASC');
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
