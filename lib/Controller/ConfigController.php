<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Controller;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\ConfigurationException;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\EntraErrors;
use OCA\IntegrationM365\Service\LifecycleService;
use OCA\IntegrationM365\Service\OAuthService;
use OCA\IntegrationM365\Sync\SyncError;
use OCA\IntegrationM365\Sync\UserSyncService;
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
	/** Seconds "Sync now" may work inside the request before handing over to the background job. */
	public const INLINE_BUDGET_SECONDS = 20;

	public function __construct(
		string $appName,
		IRequest $request,
		private IAppConfig $appConfig,
		private OAuthService $oauth,
		private CollectionMapper $collections,
		private UserSyncService $userSync,
		private LifecycleService $lifecycle,
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
		$before = [$this->oauth->getClientId(), $this->oauth->getTenant()];
		foreach ($values as $key => $value) {
			if (!in_array($key, ['client_id', 'client_secret', 'tenant', 'secret_expires'], true)) {
				continue;
			}
			if ($key === 'client_secret' && $value === 'dummySecret') {
				continue;
			}
			$value = trim((string)$value);
			if ($key === 'secret_expires' && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
				return new DataResponse(['error' => 'invalid date'], Http::STATUS_BAD_REQUEST);
			}
			if (($key === 'client_id' && strlen($value) > 64) || ($key === 'tenant' && strlen($value) > 255) || ($key === 'client_secret' && strlen($value) > 512)) {
				return new DataResponse(['error' => 'invalid parameters'], Http::STATUS_BAD_REQUEST);
			}
			$this->appConfig->setAppValueString($key, $value, lazy: true, sensitive: $key === 'client_secret');
		}
		$invalidated = 0;
		if ($before[0] !== '' && $before !== [$this->oauth->getClientId(), $this->oauth->getTenant()]) {
			// tokens were issued for the old registration; a new secret alone keeps them valid
			$invalidated = $this->lifecycle->invalidateAllTokens('client id or tenant changed');
		}
		return new DataResponse(['configured' => $this->oauth->isConfigured(), 'invalidatedUsers' => $invalidated]);
	}

	/** Admin: verify the saved registration against Entra ID (client-credentials grant, no user involved). */
	public function testAdminConfig(): DataResponse {
		return new DataResponse($this->oauth->testRegistration());
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
			$this->logger->info('Microsoft 365 login returned an error', ['app' => Application::APP_ID, 'uid' => $this->userId, 'error' => $error, 'aadsts' => EntraErrors::code($error_description)]);
			return new RedirectResponse($settingsUrl . '?m365=error&message=' . urlencode(EntraErrors::describe($error, $error_description, $this->l, $this->oauth->getRedirectUri())));
		}
		$previous = $this->oauth->getAccountInfo($this->userId)['principal'];
		try {
			$me = $this->oauth->handleRedirect($this->userId, $code, $state);
		} catch (TokenException $e) {
			return new RedirectResponse($settingsUrl . '?m365=error&message=' . urlencode($e->getMessage()));
		} catch (GraphException $e) {
			$this->logger->warning('Microsoft 365 OAuth redirect failed', ['app' => Application::APP_ID, 'uid' => $this->userId, 'error' => $e->graphCode, 'aadsts' => EntraErrors::code($e->getMessage())]);
			return new RedirectResponse($settingsUrl . '?m365=error&message=' . urlencode(EntraErrors::describe($e->graphCode, $e->getMessage(), $this->l, $this->oauth->getRedirectUri())));
		}
		$principal = (string)($me['userPrincipalName'] ?? $me['mail'] ?? '');
		if ($previous !== '' && $principal !== '' && strcasecmp($previous, $principal) !== 0) {
			// a different mailbox: the stored remote ids, delta links and correlations are meaningless
			$this->lifecycle->forgetPairs($this->userId);
			$this->logger->info('Microsoft 365 account changed, pairs reset', ['app' => Application::APP_ID, 'uid' => $this->userId]);
		}
		return new RedirectResponse($settingsUrl . '?m365=connected#m365_personal');
	}

	/** Forget the tokens (and optionally the sync state) of the current user. */
	#[NoAdminRequired]
	public function disconnect(bool $purge = false): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$this->lifecycle->disconnect($this->userId, $purge);
		return new DataResponse(['connected' => false]);
	}

	/**
	 * "Sync now": run inline with a time budget so the common case (small delta) finishes
	 * within the request; whatever is left over is queued for the background job.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 6, period: 60)]
	public function syncNow(bool $inline = true): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if ($this->oauth->getAccountInfo($this->userId)['reauthRequired']) {
			return new DataResponse(['error' => SyncError::REAUTH_REQUIRED], Http::STATUS_BAD_REQUEST);
		}
		$complete = false;
		$pairs = [];
		if ($inline) {
			@set_time_limit(self::INLINE_BUDGET_SECONDS + 60);
			try {
				$results = $this->userSync->syncUser($this->userId, false, null, null, microtime(true) + (float)self::INLINE_BUDGET_SECONDS);
				$complete = true;
				foreach ($results as $pairId => $result) {
					$complete = $complete && !$result->truncated;
					$pairs[] = ['id' => $pairId, 'truncated' => $result->truncated, 'writes' => $result->writes(), 'errors' => $result->errors, 'conflicts' => $result->conflicts];
				}
			} catch (ConfigurationException) {
				return new DataResponse(['error' => SyncError::CONFIG_ERROR], Http::STATUS_BAD_REQUEST);
			} catch (\Throwable $e) {
				// not "queued": the user would wait 15 minutes for a job that fails the same way
				$this->logger->error('inline sync failed', ['app' => Application::APP_ID, 'uid' => $this->userId, 'exception' => $e]);
				return new DataResponse(['error' => 'failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		}
		$queued = false;
		if (!$complete) {
			$args = ['user_id' => $this->userId];
			if (!$this->jobList->has(UserSyncJob::class, $args)) {
				$this->jobList->add(UserSyncJob::class, $args);
			}
			$queued = true;
		}
		return new DataResponse(['complete' => $complete, 'queued' => $queued, 'pairs' => $pairs]);
	}

	/**
	 * Cheap status for polling after "Sync now": no Graph call, only our own tables and the job list.
	 */
	#[NoAdminRequired]
	public function syncStatus(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}
		$pairs = [];
		$running = false;
		foreach ($this->collections->findEnabledByUser($this->userId) as $pair) {
			$locked = ($pair->getLockedUntil() ?? 0) > time();
			$running = $running || $locked;
			$pairs[] = [
				'id' => $pair->getId(),
				'type' => $pair->getType(),
				'remoteId' => $pair->getRemoteId(),
				'running' => $locked,
				'lastSync' => $pair->getLastSync(),
				'lastError' => $pair->getLastError(),
				'pending' => $pair->getPendingItems(),
			];
		}
		return new DataResponse([
			'queued' => $this->jobList->has(UserSyncJob::class, ['user_id' => $this->userId]),
			'running' => $running,
			'pairs' => $pairs,
		]);
	}
}
