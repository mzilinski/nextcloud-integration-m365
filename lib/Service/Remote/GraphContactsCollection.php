<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Remote;

use OCA\IntegrationM365\Exception\GraphException;

/** Contacts need their photo fetched/pushed separately from the JSON. */
class GraphContactsCollection extends GraphRemoteCollection {
	public function extrasFor(array $ids): array {
		// ids arrive as a list (interface contract)
		if ($ids === []) {
			return [];
		}
		$requests = [];
		foreach ($ids as $i => $id) {
			$requests[] = ['id' => (string)$i, 'method' => 'GET', 'url' => '/me/contacts/' . rawurlencode($id) . '/photo/$value'];
		}
		try {
			$responses = $this->graph->batch($this->userId, $requests);
		} catch (GraphException) {
			return []; // photos are decoration: deliver the contacts without them
		}
		$out = [];
		foreach ($responses as $resp) {
			$i = (int)($resp['id'] ?? -1);
			if ($i < 0 || !isset($ids[$i]) || (int)($resp['status'] ?? 0) !== 200 || empty($resp['body'])) {
				continue; // 404 = no photo
			}
			// /$value inside a batch comes back as a base64 string body.
			$bytes = base64_decode((string)$resp['body'], true);
			if ($bytes === false || $bytes === '') {
				continue;
			}
			$mime = (string)(($resp['headers'] ?? [])['Content-Type'] ?? 'image/jpeg');
			$out[$ids[$i]] = ['photo' => [$bytes, $mime]];
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
