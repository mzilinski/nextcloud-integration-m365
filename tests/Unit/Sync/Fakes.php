<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Sync;

use OCA\IntegrationM365\Db\Item;
use OCA\IntegrationM365\Db\ItemStoreInterface;
use OCA\IntegrationM365\Service\Local\LocalCollectionInterface;
use OCA\IntegrationM365\Service\Mapping\VObjectUid;
use OCA\IntegrationM365\Service\Remote\RemoteCollectionInterface;

/** In-memory CalDAV calendar with sync-token bookkeeping like Nextcloud's. */
class FakeLocal implements LocalCollectionInterface {
	/** @var array<string,array{data:string,etag:string}> */
	public array $objects = [];
	/** @var list<array{int,string,int}> [token, uri, op] */
	public array $changes = [];
	public int $token = 1;
	public array $calls = [];

	public function put(string $uri, string $data, bool $record = true): string {
		$etag = md5($data);
		$op = isset($this->objects[$uri]) ? 2 : 1;
		$this->objects[$uri] = ['data' => $data, 'etag' => $etag];
		if ($record) {
			$this->changes[] = [$this->token++, $uri, $op];
		}
		return $etag;
	}

	public function remove(string $uri): void {
		unset($this->objects[$uri]);
		$this->changes[] = [$this->token++, $uri, 3];
	}

	public function changes(?int $syncToken): array {
		$this->calls[] = ['changes', $syncToken];
		if ($syncToken === null) {
			return ['token' => $this->token, 'added' => array_keys($this->objects), 'modified' => [], 'deleted' => [], 'full' => true];
		}
		$ops = [];
		foreach ($this->changes as [$t, $uri, $op]) {
			if ($t >= $syncToken) {
				$ops[$uri] = max($ops[$uri] ?? 0, $op);
			}
		}
		$r = ['token' => $this->token, 'added' => [], 'modified' => [], 'deleted' => [], 'full' => false];
		foreach ($ops as $uri => $op) {
			$r[[1 => 'added', 2 => 'modified', 3 => 'deleted'][$op]][] = $uri;
		}
		return $r;
	}

	public function get(string $uri): ?array {
		return $this->objects[$uri] ?? null;
	}

	public function create(string $uri, string $data): string {
		$this->calls[] = ['create', $uri];
		return $this->put($uri, $data);
	}

	public function update(string $uri, string $data): string {
		$this->calls[] = ['update', $uri];
		return $this->put($uri, $data);
	}

	public function delete(string $uri): void {
		$this->calls[] = ['delete', $uri];
		$this->remove($uri);
	}

	public function findUriByUid(string $uid): ?string {
		foreach ($this->objects as $uri => $o) {
			if (VObjectUid::uidOf($o['data']) === $uid) {
				return $uri;
			}
		}
		return null;
	}
}

/** In-memory Graph collection with delta semantics and changeKey bumps. */
class FakeRemote implements RemoteCollectionInterface {
	/** @var array<string,array> */
	public array $objects = [];
	/** @var list<array{string,bool}> [id, removed] in change order */
	public array $journal = [];
	public int $counter = 0;
	public bool $expireNextDelta = false;
	/** Emulate Exchange: bump the changeKey once more shortly after a write. */
	public bool $bumpAfterWrite = true;
	public array $calls = [];
	public array $photos = [];
	/** @var list<string> ids whose changeKey Exchange will bump "a little later" */
	public array $pendingBumps = [];

	public function seed(array $json): string {
		$id = $json['id'] ?? ('R' . ++$this->counter);
		$json['id'] = $id;
		$json['changeKey'] = 'ck' . ++$this->counter;
		$json['iCalUId'] ??= 'ICAL-' . $id;
		$this->objects[$id] = $json;
		$this->journal[] = [$id, false];
		return $id;
	}

	public function removeRemote(string $id): void {
		unset($this->objects[$id]);
		$this->journal[] = [$id, true];
	}

	public function changes(?string $deltaLink): array {
		$this->calls[] = ['changes', $deltaLink];
		foreach ($this->pendingBumps as $id) { // the asynchronous post-write changeKey bump
			if (isset($this->objects[$id])) {
				$this->objects[$id]['changeKey'] = 'ck' . ++$this->counter;
				$this->journal[] = [$id, false];
			}
		}
		$this->pendingBumps = [];
		if ($deltaLink === null || $this->expireNextDelta) {
			$this->expireNextDelta = false;
			return ['items' => $this->objects, 'removed' => [], 'deltaLink' => 'delta:' . count($this->journal), 'full' => true];
		}
		$from = (int)substr($deltaLink, 6);
		$items = [];
		$removed = [];
		foreach (array_slice($this->journal, $from) as [$id, $isRemoved]) {
			if ($isRemoved) {
				unset($items[$id]);
				$removed[] = $id;
			} elseif (isset($this->objects[$id])) {
				$items[$id] = $this->objects[$id];
			}
		}
		return ['items' => $items, 'removed' => array_values(array_unique($removed)), 'deltaLink' => 'delta:' . count($this->journal), 'full' => false];
	}

	public function get(string $id): ?array {
		return $this->objects[$id] ?? null;
	}

	public function create(array $body): array {
		$this->calls[] = ['create', $body['subject'] ?? $body['title'] ?? ''];
		$id = 'R' . ++$this->counter;
		$obj = $body + ['id' => $id, 'iCalUId' => 'ICAL-' . $id];
		$obj['changeKey'] = 'ck' . ++$this->counter;
		$this->objects[$id] = $obj;
		$this->journal[] = [$id, false];
		if ($this->bumpAfterWrite) {
			$this->pendingBumps[] = $id;
		}
		return $obj;
	}

	public function update(string $id, array $body): array {
		$this->calls[] = ['update', $id];
		$this->objects[$id] = array_merge($this->objects[$id], $body, ['changeKey' => 'ck' . ++$this->counter]);
		$this->journal[] = [$id, false];
		$obj = $this->objects[$id];
		if ($this->bumpAfterWrite) {
			$this->pendingBumps[] = $id;
		}
		return $obj;
	}

	public function delete(string $id): void {
		$this->calls[] = ['delete', $id];
		$this->removeRemote($id);
	}

	public function etagOf(array $json): string {
		return (string)($json['changeKey'] ?? '');
	}

	/** @var array<string,true> ids whose photo the next extrasFor() reports as unavailable */
	public array $photoUnavailable = [];

	public function extrasFor(array $ids): array {
		$out = [];
		foreach ($ids as $id) {
			if (isset($this->photoUnavailable[$id])) {
				$out[$id] = ['unavailable' => true];
			} elseif (isset($this->photos[$id])) {
				$out[$id] = ['photo' => $this->photos[$id]];
			}
		}
		$this->photoUnavailable = [];
		return $out;
	}

	public function afterWrite(string $id, ?array $photo): void {
		if ($photo !== null) {
			$this->photos[$id] = $photo;
		}
	}
}

/** ItemStore replacement that keeps correlations in memory. */
class FakeItemMapper implements ItemStoreInterface {
	/** @var array<int,Item> */
	public array $rows = [];
	private int $nextId = 1;

	public function findByCollection(int $collectionId): array {
		return array_values(array_filter($this->rows, static fn (Item $i) => $i->getCollectionId() === $collectionId));
	}

	public function insertItem(Item $item): Item {
		$item->setId($this->nextId++);
		$this->rows[(int)$item->getId()] = $item;
		return $item;
	}

	public function updateItem(Item $item): Item {
		$this->rows[(int)$item->getId()] = $item;
		return $item;
	}

	public function deleteItem(Item $item): void {
		unset($this->rows[(int)$item->getId()]);
	}

	public function deleteByCollection(int $collectionId): void {
		foreach ($this->findByCollection($collectionId) as $i) {
			unset($this->rows[(int)$i->getId()]);
		}
	}
}
