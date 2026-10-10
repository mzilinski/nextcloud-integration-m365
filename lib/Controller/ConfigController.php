<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Controller;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\OAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private OAuthService $oauth,
		private CollectionMapper $collections,
		private IJobList $jobList,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Admin: store client id / secret / tenant.
	 *
	 * @param array<string,string> $values
	 */
	#[PasswordConfirmationRequired]
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			if (!in_array($key, ['client_id', 'client_secret', 'tenant'], true)) {
				continue;
			}
			if ($key === 'client_secret' && $value === 'dummySecret') {
				continue;
			}
			$this->appConfig->setAppValueString($key, trim((string)$value), lazy: true, sensitive: $key === 'client_secret');
		}
		return new DataResponse(['configured' => $this->oauth->isConfigured()]);
	}

	/** Personal: where to send the browser to connect a Microsoft account. */
	#[NoAdminRequired]
	public function getOauthUrl(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new DataResponse(['url' => $this->oauth->getAuthorizeUrl($this->userId)]);
		} catch (TokenException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Microsoft sends the browser back here. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 5, period: 60)]
	public function oauthRedirect(string $code = '', string $state = '', string $error = '', string $error_description = ''): RedirectResponse {
		$settingsUrl = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
		if ($this->userId === null) {
			return new RedirectResponse($settingsUrl . '?m365=error&message=' . urlencode($this->l->t('No logged in user')));
		}
		if ($error !== '') {
			return new RedirectResponse($settingsUrl . '?m365=error&message=' . urlencode($error_description !== '' ? $error_description : $error));
		}
		try {
			$this->oauth->handleRedirect($this->userId, $code, $state);
		} catch (TokenException|GraphException $e) {
			$this->logger->warning('Microsoft 365 OAuth redirect failed', ['app' => Application::APP_ID, 'exception' => $e]);
			return new RedirectResponse($settingsUrl . '?m365=error&message=' . urlencode($e->getMessage()));
		}
		return new RedirectResponse($settingsUrl . '?m365=connected#integration_m365');
	}

	/** Forget the tokens (and optionally the sync state) of the current user. */
	#[NoAdminRequired]
	public function disconnect(bool $purge = false): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$this->oauth->disconnect($this->userId);
		if ($purge) {
			$this->collections->deleteByUser($this->userId);
		} else {
			foreach ($this->collections->findByUser($this->userId) as $pair) {
				$pair->setEnabled(false);
				$this->collections->update($pair);
			}
		}
		return new DataResponse(['connected' => false]);
	}

	/** Queue a sync for the current user; cron picks it up within minutes. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 60)]
	public function syncNow(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$args = ['user_id' => $this->userId];
		if (!$this->jobList->has(UserSyncJob::class, $args)) {
			$this->jobList->add(UserSyncJob::class, $args);
		}
		return new DataResponse(['queued' => true]);
	}
}
