<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Settings;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Service\OAuthService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function __construct(
		private OAuthService $oauth,
		private IInitialState $initialState,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? '';
		$connected = $userId !== '' && $this->oauth->isConnected($userId);
		$this->initialState->provideInitialState('user-config', [
			'configured' => $this->oauth->isConfigured(),
			'connected' => $connected,
			'account' => $connected ? $this->oauth->getAccountInfo($userId) : null,
			'types' => Application::TYPES,
		]);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 20;
	}
}
