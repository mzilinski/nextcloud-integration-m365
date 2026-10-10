<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Exception\TokenException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * OAuth 2.0 authorization-code flow (with PKCE) against Microsoft Entra ID.
 *
 * One Azure app registration per Nextcloud instance (admin settings); each user
 * connects their own Microsoft account. Tokens live in SecretService.
 */
class OAuthService {
	public const SCOPES = 'offline_access User.Read Calendars.ReadWrite Contacts.ReadWrite Tasks.ReadWrite';

	/** Refresh the access token when it expires within this many seconds. */
	private const REFRESH_MARGIN = 300;

	public function __construct(
		private IAppConfig $appConfig,
		private SecretService $secrets,
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	public function getClientId(): string {
		return $this->appConfig->getAppValueString('client_id', lazy: true);
	}

	public function getClientSecret(): string {
		return $this->appConfig->getAppValueString('client_secret', lazy: true);
	}

	public function getTenant(): string {
		$tenant = trim($this->appConfig->getAppValueString('tenant', lazy: true));
		return $tenant === '' ? 'common' : $tenant;
	}

	public function isConfigured(): bool {
		return $this->getClientId() !== '' && $this->getClientSecret() !== '';
	}

	/** The redirect URI the admin must register in Azure ("Web" platform). */
	public function getRedirectUri(): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.config.oauthRedirect');
	}

	private function authority(): string {
		return 'https://login.microsoftonline.com/' . rawurlencode($this->getTenant()) . '/oauth2/v2.0';
	}

	/** Build the authorize URL and remember state + PKCE verifier for this user. */
	public function getAuthorizeUrl(string $userId): string {
		if (!$this->isConfigured()) {
			throw new TokenException('Microsoft 365 integration is not configured by the administrator');
		}
		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
		$this->secrets->setPlain($userId, 'oauth_state', $state);
		$this->secrets->setEncrypted($userId, 'oauth_verifier', $verifier);

		return $this->authority() . '/authorize?' . http_build_query([
			'client_id' => $this->getClientId(),
			'response_type' => 'code',
			'redirect_uri' => $this->getRedirectUri(),
			'response_mode' => 'query',
			'scope' => self::SCOPES,
			'state' => $state,
			'code_challenge' => $challenge,
			'code_challenge_method' => 'S256',
			'prompt' => 'select_account',
		]);
	}

	/**
	 * Exchange the authorization code; verifies the state. Returns the Graph /me profile.
	 *
	 * @throws TokenException|GraphException
	 */
	public function handleRedirect(string $userId, string $code, string $state): array {
		$expected = $this->secrets->getPlain($userId, 'oauth_state');
		$verifier = $this->secrets->getEncrypted($userId, 'oauth_verifier');
		$this->secrets->delete($userId, 'oauth_state');
		$this->secrets->delete($userId, 'oauth_verifier');
		if ($expected === '' || !hash_equals($expected, $state)) {
			throw new TokenException('OAuth state mismatch');
		}
		if ($code === '') {
			throw new TokenException('No authorization code received');
		}
		$token = $this->tokenRequest([
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $this->getRedirectUri(),
			'code_verifier' => $verifier,
			'scope' => self::SCOPES,
		]);
		$this->storeToken($userId, $token);

		$client = $this->clientService->newClient();
		$response = $client->get('https://graph.microsoft.com/v1.0/me?$select=displayName,userPrincipalName,mail', [
			'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
			'timeout' => 30,
		]);
		$me = json_decode((string)$response->getBody(), true) ?: [];
		$this->secrets->setPlain($userId, 'user_name', (string)($me['displayName'] ?? ''));
		$this->secrets->setPlain($userId, 'user_principal', (string)($me['userPrincipalName'] ?? $me['mail'] ?? ''));
		$this->secrets->delete($userId, 'reauth_required');
		return $me;
	}

	public function isConnected(string $userId): bool {
		return $this->secrets->getEncrypted($userId, 'refresh_token') !== '';
	}

	/** @return array{name: string, principal: string, reauthRequired: bool} */
	public function getAccountInfo(string $userId): array {
		return [
			'name' => $this->secrets->getPlain($userId, 'user_name'),
			'principal' => $this->secrets->getPlain($userId, 'user_principal'),
			'reauthRequired' => $this->secrets->getPlain($userId, 'reauth_required') === '1',
		];
	}

	/**
	 * A valid access token, refreshed if it expires soon.
	 *
	 * @throws TokenException when the user is not connected or the refresh token is dead
	 */
	public function getAccessToken(string $userId, bool $forceRefresh = false): string {
		$refreshToken = $this->secrets->getEncrypted($userId, 'refresh_token');
		if ($refreshToken === '') {
			throw new TokenException('Microsoft account not connected');
		}
		$expiresAt = $this->secrets->getInt($userId, 'token_expires_at');
		$accessToken = $this->secrets->getEncrypted($userId, 'access_token');
		if (!$forceRefresh && $accessToken !== '' && $expiresAt - self::REFRESH_MARGIN > time()) {
			return $accessToken;
		}
		try {
			$token = $this->tokenRequest([
				'grant_type' => 'refresh_token',
				'refresh_token' => $refreshToken,
				'scope' => self::SCOPES,
			]);
		} catch (GraphException $e) {
			if (in_array($e->graphCode, ['invalid_grant', 'interaction_required', 'invalid_client'], true)) {
				// Dead refresh token (revoked, > 90 days idle, consent withdrawn): needs a new login.
				$this->secrets->setPlain($userId, 'reauth_required', '1');
				$this->logger->warning('Microsoft 365 refresh token rejected for user, reconnect required', ['app' => Application::APP_ID, 'uid' => $userId]);
				throw new TokenException('Reconnect your Microsoft account', 0, $e);
			}
			throw $e;
		}
		$this->storeToken($userId, $token);
		return (string)$token['access_token'];
	}

	public function disconnect(string $userId): void {
		foreach (['access_token', 'refresh_token', 'token_expires_at', 'user_name', 'user_principal', 'reauth_required', 'oauth_state', 'oauth_verifier'] as $key) {
			$this->secrets->delete($userId, $key);
		}
	}

	private function storeToken(string $userId, array $token): void {
		$this->secrets->setEncrypted($userId, 'access_token', (string)$token['access_token']);
		if (!empty($token['refresh_token'])) {
			$this->secrets->setEncrypted($userId, 'refresh_token', (string)$token['refresh_token']);
		}
		$this->secrets->setInt($userId, 'token_expires_at', time() + (int)($token['expires_in'] ?? 3600));
	}

	/** @throws GraphException */
	private function tokenRequest(array $params): array {
		$client = $this->clientService->newClient();
		try {
			$response = $client->post($this->authority() . '/token', [
				'body' => array_merge(['client_id' => $this->getClientId(), 'client_secret' => $this->getClientSecret()], $params),
				'timeout' => 30,
			]);
			$body = json_decode((string)$response->getBody(), true);
		} catch (\Exception $e) {
			// Guzzle throws on 4xx; the body carries the OAuth error.
			$body = null;
			$status = 0;
			if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
				$status = $e->getResponse()->getStatusCode();
				$body = json_decode((string)$e->getResponse()->getBody(), true);
			}
			throw new GraphException(
				(string)($body['error_description'] ?? $e->getMessage()),
				$status,
				(string)($body['error'] ?? 'oauth'),
				$e,
			);
		}
		if (!is_array($body) || empty($body['access_token'])) {
			throw new GraphException('Token endpoint returned no access token', 0, 'oauth');
		}
		return $body;
	}
}
