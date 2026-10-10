<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service\Mapping;

/** What the sync engine needs from a mapping: both directions, UID handling, skip rules. */
interface ItemMapperInterface {
	/** Graph JSON (+ extra such as a photo) -> local object text, or null when not mappable. */
	public function toLocal(array $json, array $extra = []): ?string;

	/** Local object text -> Graph body for POST/PATCH. */
	public function toGraph(string $data): array;

	/** UID of a local object, null when absent. */
	public function uidOf(string $data): ?string;

	/** Same object with its UID replaced (keeps the local identity across round trips). */
	public function withUid(string $data, string $uid): string;

	/** The UID a remote object gets when it first appears locally. */
	public function remoteUid(array $json): string;

	/** Remote objects the engine must ignore (series instances, …). */
	public function skip(array $json): bool;

	/** File extension of local objects: ics or vcf. */
	public function extension(): string;

	/** Inline photo of a local object as [bytes, mime] (contacts only). */
	public function photoOf(string $data): ?array;
}
