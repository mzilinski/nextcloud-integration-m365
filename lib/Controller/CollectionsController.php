<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Controller;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\RemoteCollectionsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Lists the user's Graph collections merged with the stored pairs, and stores
 * per-collection choices (enabled, target name, conflict policy).
 */
class CollectionsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private CollectionMapper $collections,
		private RemoteCollectionsService $remote,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$remote = $this->remote->listAll($this->userId);
		} catch (TokenException $e) {
			return new DataResponse(['error' => $e->getMessage(), 'reauthRequired' => true], Http::STATUS_BAD_REQUEST);
		} catch (GraphException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
		$stored = [];
		foreach ($this->collections->findByUser($this->userId) as $pair) {
			$stored[$pair->getType()][$pair->getRemoteId()] = $pair;
		}
		$out = [];
		foreach (Application::TYPES as $type) {
			$out[$type] = [];
			foreach ($remote[$type] as $col) {
				/** @var Collection|null $pair */
				$pair = $stored[$type][$col['id']] ?? null;
				$out[$type][] = [
					'remoteId' => $col['id'],
					'remoteName' => $col['name'],
					'readOnly' => $col['readOnly'],
					'isDefault' => $col['isDefault'],
					'id' => $pair?->getId(),
					'enabled' => $pair?->getEnabled() ?? false,
					'localName' => $pair?->getLocalName() ?: ('M365 ' . $col['name']),
					'conflictPolicy' => $pair?->getConflictPolicy() ?? 'remote',
					'lastSync' => $pair?->getLastSync(),
					'lastError' => $pair?->getLastError(),
					'pending' => $pair?->getPendingItems(),
				];
			}
		}
		return new DataResponse($out);
	}

	/**
	 * Create or update the pair for one remote collection.
	 */
	#[NoAdminRequired]
	public function update(string $type, string $remoteId, string $remoteName = '', bool $enabled = false, string $localName = '', string $conflictPolicy = 'remote'): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if (!in_array($type, Application::TYPES, true) || !in_array($conflictPolicy, ['remote', 'local', 'import'], true) || $remoteId === '') {
			return new DataResponse(['error' => 'invalid parameters'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$pair = $this->collections->findByRemote($this->userId, $type, $remoteId);
		} catch (DoesNotExistException) {
			$pair = new Collection();
			$pair->setUserId($this->userId);
			$pair->setType($type);
			$pair->setRemoteId($remoteId);
		}
		if ($remoteName !== '') {
			$pair->setRemoteName($remoteName);
		}
		if ($enabled && !$pair->getEnabled()) {
			$pair->setLastError(null); // a fresh start after "local collection gone" or a config fix
		}
		$pair->setEnabled($enabled);
		$pair->setLocalName(trim($localName) !== '' ? trim($localName) : ('M365 ' . $pair->getRemoteName()));
		if ($this->remote->isReadOnly($this->userId, $type, $remoteId)) {
			$conflictPolicy = 'import';
		}
		$pair->setConflictPolicy($conflictPolicy);
		if ($pair->getId() === null) {
			$pair = $this->collections->insert($pair);
		} else {
			$pair = $this->collections->update($pair);
		}
		return new DataResponse($pair);
	}
}
