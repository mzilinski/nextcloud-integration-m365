<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service;

use OCA\IntegrationM365\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Security\ICrypto;

/**
 * Per-user secrets (OAuth tokens) in the user config, ICrypto-encrypted and lazy-loaded.
 * Mirrors the pattern of nextcloud/integration_google.
 */
class SecretService {
	public function __construct(
		private IUserConfig $userConfig,
		private ICrypto $crypto,
	) {
	}

	public function setEncrypted(string $userId, string $key, string $value): void {
		if ($value === '') {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
			return;
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, $key, $this->crypto->encrypt($value), lazy: true);
	}

	public function getEncrypted(string $userId, string $key): string {
		$stored = $this->userConfig->getValueString($userId, Application::APP_ID, $key, lazy: true);
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Exception) {
			return '';
		}
	}

	public function setPlain(string $userId, string $key, string $value): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, $key, $value, lazy: true);
	}

	public function getPlain(string $userId, string $key, string $default = ''): string {
		return $this->userConfig->getValueString($userId, Application::APP_ID, $key, $default, lazy: true);
	}

	public function setInt(string $userId, string $key, int $value): void {
		$this->userConfig->setValueInt($userId, Application::APP_ID, $key, $value, lazy: true);
	}

	public function getInt(string $userId, string $key, int $default = 0): int {
		return $this->userConfig->getValueInt($userId, Application::APP_ID, $key, $default, lazy: true);
	}

	public function delete(string $userId, string $key): void {
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, $key);
	}
}
