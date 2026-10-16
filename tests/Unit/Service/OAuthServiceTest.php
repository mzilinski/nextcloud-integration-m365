<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Service;

use OCA\IntegrationM365\Exception\ConfigurationException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\OAuthService;
use OCA\IntegrationM365\Tests\Unit\FakeSecretService;
use OCA\IntegrationM365\Tests\Unit\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OAuthServiceTest extends TestCase {
	private FakeSecretService $secrets;
	private IClient&MockObject $client;
	private IManager&MockObject $notifications;
	private INotification&MockObject $notification;
	private OAuthService $oauth;
	/** @var array<string,string> */
	private array $appConfig = ['client_id' => 'cid', 'client_secret' => 'sec', 'tenant' => '11111111-2222-3333-4444-555555555555'];
	private int $now = 1_700_000_000;

	protected function setUp(): void {
		$this->secrets = new FakeSecretService();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueString')->willReturnCallback(fn (string $key, string $default = '') => $this->appConfig[$key] ?? $default);
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/integration_m365/oauth-redirect');
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $len) => str_repeat('r', $len));
		$this->notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
			$this->notification->method($m)->willReturnSelf();
		}
		$this->notifications = $this->createMock(IManager::class);
		$this->notifications->method('createNotification')->willReturn($this->notification);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$time->method('getDateTime')->willReturn(new \DateTime());
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $t, $p = []) => $p === [] ? $t : vsprintf($t, (array)$p));
		$this->oauth = new OAuthService($config, $this->secrets, $clientService, $urls, $random, new NullLogger(), $this->notifications, $time, $l);
	}

	private function connected(int $expiresAt): void {
		$this->secrets->setEncrypted('u', 'refresh_token', 'RT');
		$this->secrets->setEncrypted('u', 'access_token', 'AT');
		$this->secrets->setInt('u', 'token_expires_at', $expiresAt);
	}

	public function testAuthorizeUrlCarriesPkceChallengeAndState(): void {
		$url = $this->oauth->getAuthorizeUrl('u');
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		self::assertStringStartsWith('https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/oauth2/v2.0/authorize?', $url);
		self::assertSame('S256', $q['code_challenge_method']);
		$verifier = $this->secrets->getEncrypted('u', 'oauth_verifier');
		self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $q['code_challenge']);
		self::assertSame($this->secrets->getPlain('u', 'oauth_state'), $q['state']);
		self::assertSame(OAuthService::SCOPES, $q['scope']);
	}

	public function testAuthorizeUrlRequiresAdminConfiguration(): void {
		$this->appConfig['client_secret'] = '';
		$this->expectException(TokenException::class);
		$this->oauth->getAuthorizeUrl('u');
	}

	public function testCachedAccessTokenIsReturnedWithoutHttp(): void {
		$this->connected($this->now + 3600);
		$this->client->expects(self::never())->method('post');
		self::assertSame('AT', $this->oauth->getAccessToken('u'));
	}

	public function testExpiringTokenIsRefreshedAndStored(): void {
		$this->connected($this->now + 60); // inside the 300 s margin
		$this->client->expects(self::once())->method('post')
			->with(self::stringContains('/oauth2/v2.0/token'), self::callback(static fn (array $o) => $o['body']['grant_type'] === 'refresh_token' && $o['body']['refresh_token'] === 'RT' && $o['body']['client_secret'] === 'sec'))
			->willReturn(Http::ok($this, 200, json_encode(['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3599])));
		self::assertSame('AT2', $this->oauth->getAccessToken('u'));
		self::assertSame('RT2', $this->secrets->getEncrypted('u', 'refresh_token'));
		self::assertSame($this->now + 3599, $this->secrets->getInt('u', 'token_expires_at'));
	}

	public function testRefreshWithoutNewRefreshTokenKeepsTheOldOne(): void {
		$this->connected(0);
		$this->client->method('post')->willReturn(Http::ok($this, 200, json_encode(['access_token' => 'AT2', 'expires_in' => 10])));
		$this->oauth->getAccessToken('u');
		self::assertSame('RT', $this->secrets->getEncrypted('u', 'refresh_token'));
	}

	public function testInvalidGrantFlagsReauthAndNotifiesOnce(): void {
		$this->connected(0);
		$this->client->method('post')->willThrowException(Http::error($this, 400, json_encode(['error' => 'invalid_grant', 'error_description' => 'AADSTS50173: revoked'])));
		$this->notifications->expects(self::once())->method('notify');
		$this->notification->expects(self::once())->method('setSubject')->with('reauth_required');
		foreach ([1, 2] as $attempt) {
			try {
				$this->oauth->getAccessToken('u');
				self::fail('expected TokenException');
			} catch (TokenException $e) {
				self::assertSame('invalid_grant', $e->getPrevious()?->graphCode ?? '');
			}
		}
		self::assertTrue($this->oauth->getAccountInfo('u')['reauthRequired']);
		self::assertTrue($this->oauth->isConnected('u'), 'the refresh token is kept until the user disconnects or reconnects');
	}

	public function testInvalidClientIsAnAdminProblemNotAReauth(): void {
		$this->connected(0);
		$this->client->method('post')->willThrowException(Http::error($this, 401, json_encode(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: bad secret'])));
		$this->notifications->expects(self::never())->method('notify');
		try {
			$this->oauth->getAccessToken('u');
			self::fail('expected ConfigurationException');
		} catch (ConfigurationException $e) {
			self::assertSame('invalid_client', $e->graphCode);
		}
		self::assertFalse($this->oauth->getAccountInfo('u')['reauthRequired']);
	}

	public function testNotConnectedThrowsTokenException(): void {
		$this->expectException(TokenException::class);
		$this->oauth->getAccessToken('nobody');
	}

	public function testRedirectRejectsWrongStateAndConsumesIt(): void {
		$this->oauth->getAuthorizeUrl('u');
		try {
			$this->oauth->handleRedirect('u', 'code', 'wrong');
			self::fail('expected TokenException');
		} catch (TokenException) {
		}
		self::assertSame('', $this->secrets->getPlain('u', 'oauth_state'), 'state is single use');
		self::assertSame('', $this->secrets->getEncrypted('u', 'oauth_verifier'));
	}

	public function testRedirectExchangesCodeStoresTokensAndProfileAndClearsReauth(): void {
		$this->secrets->setPlain('u', 'reauth_required', '1');
		$url = $this->oauth->getAuthorizeUrl('u');
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		$this->client->expects(self::once())->method('post')
			->with(self::anything(), self::callback(static fn (array $o) => $o['body']['grant_type'] === 'authorization_code' && $o['body']['code'] === 'CODE' && $o['body']['code_verifier'] === str_repeat('r', 64)))
			->willReturn(Http::ok($this, 200, json_encode(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600])));
		$this->client->expects(self::once())->method('get')
			->with(self::stringContains('/me?'), self::callback(static fn (array $o) => $o['headers']['Authorization'] === 'Bearer AT'))
			->willReturn(Http::ok($this, 200, json_encode(['displayName' => 'Ann', 'userPrincipalName' => 'ann@example.com'])));
		$this->notifications->expects(self::once())->method('markProcessed');
		$me = $this->oauth->handleRedirect('u', 'CODE', $q['state']);
		self::assertSame('Ann', $me['displayName']);
		self::assertSame(['name' => 'Ann', 'principal' => 'ann@example.com', 'reauthRequired' => false], $this->oauth->getAccountInfo('u'));
		self::assertTrue($this->oauth->isConnected('u'));
	}

	public function testDisconnectForgetsEverythingAndForgetTokensKeepsTheName(): void {
		$this->connected(0);
		$this->secrets->setPlain('u', 'user_name', 'Ann');
		$this->oauth->forgetTokens('u');
		self::assertFalse($this->oauth->isConnected('u'));
		self::assertSame('Ann', $this->oauth->getAccountInfo('u')['name']);
		$this->oauth->disconnect('u');
		self::assertSame('', $this->oauth->getAccountInfo('u')['name']);
	}

	public function testRegistrationTestUsesClientCredentialsForATenantId(): void {
		$this->client->expects(self::once())->method('post')
			->with(self::anything(), self::callback(static fn (array $o) => $o['body']['grant_type'] === 'client_credentials'))
			->willThrowException(Http::error($this, 401, json_encode(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided. Trace ID: x'])));
		$r = $this->oauth->testRegistration();
		self::assertFalse($r['ok']);
		self::assertTrue($r['verified']);
		self::assertStringContainsString('AADSTS7000215', $r['message']);
		self::assertStringNotContainsString('Trace ID', $r['message']);
	}

	public function testRegistrationTestCannotVerifyMultiTenantSettings(): void {
		$this->appConfig['tenant'] = 'common';
		$this->client->expects(self::never())->method('post');
		$r = $this->oauth->testRegistration();
		self::assertTrue($r['ok']);
		self::assertFalse($r['verified']);
	}
}
