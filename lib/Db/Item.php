<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Correlation between one local object and one Graph object.
 *
 * @method int getCollectionId()
 * @method void setCollectionId(int $id)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getLocalUri()
 * @method void setLocalUri(string $uri)
 * @method string getLocalEtag()
 * @method void setLocalEtag(string $etag)
 * @method string getRemoteId()
 * @method void setRemoteId(string $id)
 * @method string getRemoteEtag()
 * @method void setRemoteEtag(string $etag)
 * @method string getContentHash()
 * @method void setContentHash(string $hash)
 * @method int|null getLastSynced()
 * @method void setLastSynced(?int $ts)
 */
class Item extends Entity {
	protected int $collectionId = 0;
	protected string $uid = '';
	protected string $localUri = '';
	protected string $localEtag = '';
	protected string $remoteId = '';
	protected string $remoteEtag = '';
	protected string $contentHash = '';
	protected ?int $lastSynced = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('collectionId', 'integer');
		$this->addType('lastSynced', 'integer');
	}
}
