<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

use Closure;
use OCA\IntegrationM365\Exception\DeltaExpiredException;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Service\GraphClient;

/** Generic Graph collection: delta + CRUD on a set of URL templates. */
class GraphRemoteCollection implements RemoteCollectionInterface {
	/**
	 * @param Closure(string):string $itemUrl
	 * @param Closure(string):string $deleteUrl
	 */
	public function __construct(
		protected GraphClient $graph,
		protected string $userId,
		protected string $deltaUrl,
		protected Closure $itemUrl,
		protected string $createUrl,
		protected Closure $deleteUrl,
		protected string $etagField,
	) {
	}

	public function changes(?string $deltaLink): array {
		$full = $deltaLink === null;
		try {
			$result = $this->graph->delta($this->userId, $deltaLink ?? $this->deltaUrl);
		} catch (DeltaExpiredException) {
			$full = true;
			$result = $this->graph->delta($this->userId, $this->deltaUrl);
		}
		$items = [];
		$removed = [];
		foreach ($result['items'] as $obj) {
			$id = (string)($obj['id'] ?? '');
			if ($id === '') {
				continue;
			}
			if (isset($obj['@removed'])) {
				$removed[] = $id;
			} else {
				$items[$id] = $obj;
			}
		}
		return ['items' => $items, 'removed' => $removed, 'deltaLink' => $result['deltaLink'], 'full' => $full];
	}

	public function get(string $id): ?array {
		try {
			$obj = $this->graph->get($this->userId, ($this->itemUrl)($id));
		} catch (GraphException $e) {
			if ($e->httpStatus === 404) {
				return null;
			}
			throw $e;
		}
		return $obj === [] ? null : $obj;
	}

	public function create(array $body): array {
		return $this->graph->post($this->userId, $this->createUrl, $body);
	}

	public function update(string $id, array $body): array {
		$obj = $this->graph->patch($this->userId, ($this->itemUrl)($id), $body);
		if (empty($obj['id'])) {
			$obj = $this->get($id) ?? ['id' => $id];
		}
		return $obj;
	}

	public function delete(string $id): void {
		try {
			$this->graph->delete($this->userId, ($this->deleteUrl)($id));
		} catch (GraphException $e) {
			if ($e->httpStatus !== 404) {
				throw $e;
			}
		}
	}

	public function etagOf(array $json): string {
		$etag = (string)($json[$this->etagField] ?? '');
		return $etag !== '' ? $etag : hash('sha1', (string)json_encode($json));
	}

	public function extrasFor(array $ids): array {
		return [];
	}

	public function afterWrite(string $id, ?array $photo): void {
	}
}
