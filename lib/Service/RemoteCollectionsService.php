<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service;

use OCA\IntegrationM365\AppInfo\Application;
use OCP\IL10N;

/**
 * Discovery of the user's Graph collections: calendars, contact folders, To Do lists.
 */
class RemoteCollectionsService {
	/** Graph has no id for the default contacts folder; this marker selects /me/contacts. */
	public const DEFAULT_CONTACTS = 'contacts';

	public function __construct(
		private GraphClient $graph,
		private IL10N $l,
	) {
	}

	/**
	 * @return array<string, list<array{id:string,name:string,readOnly:bool,isDefault:bool}>> keyed by type
	 */
	public function listAll(string $userId): array {
		$out = [Application::TYPE_CALENDAR => [], Application::TYPE_CONTACTS => [], Application::TYPE_TASKS => []];
		foreach ($this->graph->listAll($userId, '/me/calendars?$select=id,name,canEdit,isDefaultCalendar') as $c) {
			$out[Application::TYPE_CALENDAR][] = [
				'id' => (string)$c['id'], 'name' => (string)($c['name'] ?? ''),
				'readOnly' => !($c['canEdit'] ?? true), 'isDefault' => (bool)($c['isDefaultCalendar'] ?? false),
			];
		}
		$out[Application::TYPE_CONTACTS][] = ['id' => self::DEFAULT_CONTACTS, 'name' => $this->l->t('Contacts'), 'readOnly' => false, 'isDefault' => true];
		foreach ($this->graph->listAll($userId, '/me/contactFolders?$select=id,displayName') as $f) {
			$out[Application::TYPE_CONTACTS][] = ['id' => (string)$f['id'], 'name' => (string)($f['displayName'] ?? ''), 'readOnly' => false, 'isDefault' => false];
		}
		foreach ($this->graph->listAll($userId, '/me/todo/lists') as $l) {
			$out[Application::TYPE_TASKS][] = [
				'id' => (string)$l['id'], 'name' => (string)($l['displayName'] ?? ''),
				'readOnly' => false, 'isDefault' => ($l['wellknownListName'] ?? '') === 'defaultList',
			];
		}
		return $out;
	}

	/** Whether Graph reports the collection as not editable (subscribed/holiday/birthday calendars). */
	public function isReadOnly(string $userId, string $type, string $remoteId): bool {
		foreach ($this->listAll($userId)[$type] ?? [] as $col) {
			if ($col['id'] === $remoteId) {
				return $col['readOnly'];
			}
		}
		return false;
	}
}
