<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Local;

use OCA\DAV\CardDAV\CardDavBackend;

class LocalContactsCollection implements LocalCollectionInterface {
	public function __construct(
		private CardDavBackend $backend,
		private int $addressBookId,
	) {
	}

	public function changes(?int $syncToken): array {
		// Nextcloud paginates the initial listing (default 2500 cards) through "init_<lastId>_<token>"
		// continuation tokens and may truncate incremental results too: loop until complete.
		$token = $syncToken === null ? '' : (string)$syncToken;
		$added = [];
		$modified = [];
		$deleted = [];
		$current = null;
		for ($i = 0; $i < 100; $i++) {
			$result = $this->backend->getChangesForAddressBook((string)$this->addressBookId, $token, 1);
			if ($result === null || $result === []) {
				throw new \RuntimeException('address book ' . $this->addressBookId . ' no longer exists');
			}
			$added = array_merge($added, array_map('strval', $result['added'] ?? []));
			$modified = array_merge($modified, array_map('strval', $result['modified'] ?? []));
			$deleted = array_merge($deleted, array_map('strval', $result['deleted'] ?? []));
			$current = (string)$result['syncToken'];
			if (str_starts_with($current, 'init_') || !empty($result['result_truncated'])) {
				$token = $current;
				continue;
			}
			break;
		}
		return [
			'token' => (int)$current,
			'added' => array_values(array_unique($added)),
			'modified' => array_values(array_unique($modified)),
			'deleted' => array_values(array_unique($deleted)),
			'full' => $syncToken === null,
		];
	}

	public function get(string $uri): ?array {
		$card = $this->backend->getCard((string)$this->addressBookId, $uri);
		if ($card === false || $card === null) {
			return null;
		}
		$data = $card['carddata'];
		if (is_resource($data)) {
			$data = (string)stream_get_contents($data);
		}
		return ['data' => (string)$data, 'etag' => LocalCalendarCollection::etag((string)($card['etag'] ?? ''))];
	}

	public function create(string $uri, string $data): string {
		return LocalCalendarCollection::etag((string)$this->backend->createCard((string)$this->addressBookId, $uri, $data));
	}

	public function update(string $uri, string $data): string {
		return LocalCalendarCollection::etag((string)$this->backend->updateCard((string)$this->addressBookId, $uri, $data));
	}

	public function delete(string $uri): void {
		$this->backend->deleteCard((string)$this->addressBookId, $uri);
	}

	public function findUriByUid(string $uid): ?string {
		$card = $this->backend->getCardByUid($this->addressBookId, $uid);
		return $card === null ? null : (string)$card['uri'];
	}
}
