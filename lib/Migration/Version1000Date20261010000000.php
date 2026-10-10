<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * m365_collections: one row per synchronised collection pair (user × type × remote collection).
 * m365_items: correlation between a local CalDAV/CardDAV object and a Graph object.
 *
 * Table names must stay <= 27 characters (without the oc_ prefix), column names <= 30.
 */
class Version1000Date20261010000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('m365_collections')) {
			$table = $schema->createTable('m365_collections');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('remote_id', Types::STRING, ['notnull' => true, 'length' => 512]);
			$table->addColumn('remote_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('local_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('local_uri', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('local_name', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('delta_link', Types::TEXT, ['notnull' => false]);
			$table->addColumn('local_sync_token', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('conflict_policy', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'remote']);
			$table->addColumn('last_sync', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('locked_until', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'm365_coll_user');
			$table->addUniqueIndex(['user_id', 'type', 'remote_id'], 'm365_coll_pair');
		}

		if (!$schema->hasTable('m365_items')) {
			$table = $schema->createTable('m365_items');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('collection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('local_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('local_etag', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('remote_id', Types::STRING, ['notnull' => true, 'length' => 512]);
			$table->addColumn('remote_etag', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('last_synced', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['collection_id', 'local_uri'], 'm365_item_loc');
			$table->addUniqueIndex(['collection_id', 'remote_id'], 'm365_item_rem');
			$table->addIndex(['collection_id', 'uid'], 'm365_item_uid');
		}

		return $schema;
	}
}
