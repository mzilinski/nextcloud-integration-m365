<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

class CalendarItemMapper implements ItemMapperInterface {
	public function toLocal(array $json, array $extra = []): ?string {
		return EventMapper::graphToIcal($json);
	}

	public function toGraph(string $data): array {
		return EventMapper::icalToGraph($data);
	}

	public function uidOf(string $data): ?string {
		return VObjectUid::uidOf($data);
	}

	public function withUid(string $data, string $uid): string {
		return VObjectUid::withUid($data, $uid);
	}

	public function remoteUid(array $json): string {
		return (string)(($json['iCalUId'] ?? '') ?: ($json['id'] ?? ''));
	}

	public function skip(array $json): bool {
		// Instances of a series: the master carries the rule; exceptions are a v2 topic.
		return in_array($json['type'] ?? '', ['occurrence', 'exception'], true);
	}

	public function extension(): string {
		return 'ics';
	}

	public function photoOf(string $data): ?array {
		return null;
	}
}
