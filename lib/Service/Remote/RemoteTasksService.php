<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

use OCA\IntegrationM365\Service\GraphClient;

/** Microsoft To Do: /me/todo/lists/{id}/tasks (delta, CRUD). Etag = lastModifiedDateTime. */
class RemoteTasksService {
	public function __construct(
		private GraphClient $graph,
	) {
	}

	public function bind(string $userId, string $listId): RemoteCollectionInterface {
		$base = '/me/todo/lists/' . rawurlencode($listId) . '/tasks';
		return new GraphRemoteCollection(
			$this->graph,
			$userId,
			$base . '/delta',
			static fn (string $id) => $base . '/' . rawurlencode($id),
			$base,
			static fn (string $id) => $base . '/' . rawurlencode($id),
			'lastModifiedDateTime',
		);
	}
}
