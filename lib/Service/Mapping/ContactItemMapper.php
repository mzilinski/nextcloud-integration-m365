<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

class ContactItemMapper implements ItemMapperInterface {
	public function toLocal(array $json, array $extra = []): ?string {
		if (empty($json['id'])) {
			return null;
		}
		$photo = $extra['photo'] ?? null; // [bytes, mime]
		return ContactMapper::graphToVcard($json, is_array($photo) ? $photo : null);
	}

	public function toGraph(string $data): array {
		return ContactMapper::vcardToGraph($data);
	}

	public function uidOf(string $data): ?string {
		return VObjectUid::uidOf($data);
	}

	public function withUid(string $data, string $uid): string {
		return VObjectUid::withUid($data, $uid);
	}

	public function remoteUid(array $json): string {
		return (string)($json['id'] ?? '');
	}

	public function skip(array $json): bool {
		return false;
	}

	public function extension(): string {
		return 'vcf';
	}

	public function photoOf(string $data): ?array {
		return ContactMapper::vcardPhoto($data);
	}
}
