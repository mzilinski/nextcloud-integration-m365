<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Local;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Exception\LocalCollectionMissingException;

/** Wraps Nextcloud's internal CardDavBackend (no public write API for address books). */
class LocalContactsService {
	public function __construct(
		private CardDavBackend $backend,
	) {
	}

	/**
	 * Find or create the local address book for a pair. Returns [addressBookId, uri].
	 *
	 * @return array{0:int,1:string}
	 * @throws LocalCollectionMissingException the address book the pair was bound to is deleted
	 */
	public function ensureAddressBook(string $userId, Collection $pair): array {
		$principal = LocalCalendarService::principal($userId);
		if ($pair->getLocalId() !== null) {
			$existing = $this->backend->getAddressBookById($pair->getLocalId());
			if ($existing === null || $existing['principaluri'] !== $principal) {
				throw new LocalCollectionMissingException("address book {$pair->getLocalId()} of pair {$pair->getId()} is gone");
			}
			return [(int)$existing['id'], (string)$existing['uri']];
		}
		$uri = $pair->getLocalUri() !== '' ? $pair->getLocalUri() : LocalCalendarService::slug($pair);
		$existing = $this->backend->getAddressBooksByUri($principal, $uri);
		if ($existing !== null) {
			return [(int)$existing['id'], $uri];
		}
		$id = $this->backend->createAddressBook($principal, $uri, [
			'{DAV:}displayname' => $pair->getLocalName() !== '' ? $pair->getLocalName() : $pair->getRemoteName(),
		]);
		return [(int)$id, $uri];
	}

	public function bind(int $addressBookId): LocalCollectionInterface {
		return new LocalContactsCollection($this->backend, $addressBookId);
	}
}
