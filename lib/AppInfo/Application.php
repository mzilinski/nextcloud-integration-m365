<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\AppInfo;

use OCA\IntegrationM365\Db\ItemMapper;
use OCA\IntegrationM365\Db\ItemStoreInterface;
use OCA\IntegrationM365\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_m365';

	/** Collection pair types (column m365_collections.type). */
	public const TYPE_CALENDAR = 'calendar';
	public const TYPE_CONTACTS = 'contacts';
	public const TYPE_TASKS = 'tasks';
	public const TYPES = [self::TYPE_CALENDAR, self::TYPE_CONTACTS, self::TYPE_TASKS];

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ItemStoreInterface::class, ItemMapper::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
