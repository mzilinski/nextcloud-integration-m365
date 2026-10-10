<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A synchronised collection pair: one Graph calendar / contact folder / To Do list
 * mapped to one local CalDAV calendar or CardDAV address book.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getRemoteId()
 * @method void setRemoteId(string $remoteId)
 * @method string getRemoteName()
 * @method void setRemoteName(string $remoteName)
 * @method int|null getLocalId()
 * @method void setLocalId(?int $localId)
 * @method string getLocalUri()
 * @method void setLocalUri(string $localUri)
 * @method string getLocalName()
 * @method void setLocalName(string $localName)
 * @method string|null getDeltaLink()
 * @method void setDeltaLink(?string $deltaLink)
 * @method int|null getLocalSyncToken()
 * @method void setLocalSyncToken(?int $token)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string getConflictPolicy()
 * @method void setConflictPolicy(string $policy)
 * @method int|null getLastSync()
 * @method void setLastSync(?int $ts)
 * @method string|null getLastError()
 * @method void setLastError(?string $error)
 * @method int|null getLockedUntil()
 * @method void setLockedUntil(?int $ts)
 */
class Collection extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected string $type = '';
	protected string $remoteId = '';
	protected string $remoteName = '';
	protected ?int $localId = null;
	protected string $localUri = '';
	protected string $localName = '';
	protected ?string $deltaLink = null;
	protected ?int $localSyncToken = null;
	protected bool $enabled = false;
	protected string $conflictPolicy = 'remote';
	protected ?int $lastSync = null;
	protected ?string $lastError = null;
	protected ?int $lockedUntil = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('localId', 'integer');
		$this->addType('localSyncToken', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('lastSync', 'integer');
		$this->addType('lockedUntil', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'type' => $this->type,
			'remoteId' => $this->remoteId,
			'remoteName' => $this->remoteName,
			'localId' => $this->localId,
			'localUri' => $this->localUri,
			'localName' => $this->localName,
			'enabled' => $this->enabled,
			'conflictPolicy' => $this->conflictPolicy,
			'lastSync' => $this->lastSync,
			'lastError' => $this->lastError,
		];
	}
}
