<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

use OCA\IntegrationM365\Service\GraphClient;
use OCA\IntegrationM365\Service\RemoteCollectionsService;

/**
 * Graph contacts: the default folder (/me/contacts) or a contact folder
 * (/me/contactFolders/{id}/contacts); photos via /me/contacts/{id}/photo/$value.
 */
class RemoteContactsService {
	public function __construct(
		private GraphClient $graph,
	) {
	}

	public function bind(string $userId, string $folderId): RemoteCollectionInterface {
		$base = $folderId === RemoteCollectionsService::DEFAULT_CONTACTS
			? '/me/contacts'
			: '/me/contactFolders/' . rawurlencode($folderId) . '/contacts';
		return new GraphContactsCollection(
			$this->graph,
			$userId,
			$base . '/delta',
			static fn (string $id) => '/me/contacts/' . rawurlencode($id),
			$base,
			static fn (string $id) => '/me/contacts/' . rawurlencode($id),
			'changeKey',
		);
	}
}
