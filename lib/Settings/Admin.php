<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Settings;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\BackgroundJob\SyncJob;
use OCA\IntegrationM365\Service\OAuthService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private OAuthService $oauth,
		private IAppConfig $appConfig,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin-config', [
			'client_id' => $this->oauth->getClientId(),
			'client_secret' => $this->oauth->getClientSecret() === '' ? '' : 'dummySecret',
			'tenant' => $this->oauth->getTenant(),
			'redirect_uri' => $this->oauth->getRedirectUri(),
			'secret_expires' => $this->oauth->getSecretExpires(),
			'sync_interval' => SyncJob::interval($this->appConfig),
			'sync_interval_min' => SyncJob::MIN_INTERVAL,
			'sync_interval_max' => SyncJob::MAX_INTERVAL,
			'scopes' => OAuthService::SCOPES,
		]);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 20;
	}
}
