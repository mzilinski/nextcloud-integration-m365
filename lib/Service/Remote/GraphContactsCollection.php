<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

use OCA\IntegrationM365\Exception\GraphException;

/** Contacts need their photo fetched/pushed separately from the JSON. */
class GraphContactsCollection extends GraphRemoteCollection {
	/**
	 * Photos of the changed contacts. A photo that could not be fetched (throttled batch,
	 * server error) is reported as `unavailable` rather than as "no photo": otherwise the
	 * content hash would change and the local contact be rewritten without its picture.
	 */
	public function extrasFor(array $ids): array {
		// ids arrive as a list (interface contract)
		if ($ids === []) {
			return [];
		}
		$requests = [];
		foreach ($ids as $i => $id) {
			$requests[] = ['id' => (string)$i, 'method' => 'GET', 'url' => '/me/contacts/' . rawurlencode($id) . '/photo/$value'];
		}
		$out = [];
		try {
			$responses = $this->graph->batch($this->userId, $requests);
		} catch (GraphException) {
			foreach ($ids as $id) {
				$out[$id] = ['unavailable' => true];
			}
			return $out;
		}
		foreach ($ids as $i => $id) {
			$resp = $responses[(string)$i] ?? null;
			$status = $resp === null ? 0 : (int)($resp['status'] ?? 0);
			if ($status === 404) {
				continue; // no photo
			}
			if ($status !== 200 || empty($resp['body'])) {
				$out[$id] = ['unavailable' => true];
				continue;
			}
			// /$value inside a batch comes back as a base64 string body.
			$bytes = base64_decode((string)$resp['body'], true);
			if ($bytes === false || $bytes === '') {
				$out[$id] = ['unavailable' => true];
				continue;
			}
			$mime = (string)(($resp['headers'] ?? [])['Content-Type'] ?? 'image/jpeg');
			$out[$id] = ['photo' => [$bytes, $mime]];
		}
		return $out;
	}

	public function etagOf(array $json): string {
		return parent::etagOf($json);
	}

	public function afterWrite(string $id, ?array $photo): void {
		if ($photo === null || $photo[0] === '') {
			return; // never delete a server-side photo because the vCard has none
		}
		try {
			$this->graph->putRaw($this->userId, '/me/contacts/' . rawurlencode($id) . '/photo/$value', $photo[0], $photo[1]);
		} catch (GraphException) {
			// best effort: the contact itself is saved
		}
	}
}
