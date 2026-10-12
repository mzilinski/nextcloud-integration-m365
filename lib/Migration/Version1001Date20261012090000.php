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

/** Progress of a run that hit the write/time budget: objects still to be copied. */
class Version1001Date20261012090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('m365_collections');
		if (!$table->hasColumn('pending_items')) {
			$table->addColumn('pending_items', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		}
		return $schema;
	}
}
