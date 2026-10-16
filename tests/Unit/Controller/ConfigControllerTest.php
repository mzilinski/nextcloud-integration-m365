<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Controller;

use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCA\IntegrationM365\Controller\ConfigController;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\ConfigurationException;
use OCA\IntegrationM365\Service\LifecycleService;
use OCA\IntegrationM365\Service\OAuthService;
use OCA\IntegrationM365\Sync\SyncError;
use OCA\IntegrationM365\Sync\SyncResult;
use OCA\IntegrationM365\Sync\UserSyncService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConfigControllerTest extends TestCase {
	private OAuthService&MockObject $oauth;
	private CollectionMapper&MockObject $collections;
	private UserSyncService&MockObject $userSync;
	private LifecycleService&MockObject $lifecycle;
	private IJobList&MockObject $jobs;
	/** @var array<string,string> */
	private array $config = ['client_id' => 'old-id', 'client_secret' => 'sec', 'tenant' => 'old-tenant'];
	private bool $reauth = false;
	private string $principal = 'ann@example.com';

	protected function setUp(): void {
		$this->oauth = $this->createMock(OAuthService::class);
		$this->oauth->method('getClientId')->willReturnCallback(fn () => $this->config['client_id']);
		$this->oauth->method('getTenant')->willReturnCallback(fn () => $this->config['tenant']);
		$this->oauth->method('isConfigured')->willReturn(true);
		$this->oauth->method('getRedirectUri')->willReturn('https://nc/redirect');
		$this->oauth->method('getAccountInfo')->willReturnCallback(fn () => ['name' => 'Ann', 'principal' => $this->principal, 'reauthRequired' => $this->reauth]);
		$this->collections = $this->createMock(CollectionMapper::class);
		$this->userSync = $this->createMock(UserSyncService::class);
		$this->lifecycle = $this->createMock(LifecycleService::class);
		$this->jobs = $this->createMock(IJobList::class);
	}

	private function controller(?string $userId = 'u'): ConfigController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setAppValueString')->willReturnCallback(function (string $k, string $v) {
			$this->config[$k] = $v;
			return true;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/settings/user/connected-accounts');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $t, $p = []) => $p === [] ? $t : vsprintf($t, (array)$p));
		return new ConfigController('integration_m365', $this->createMock(IRequest::class), $appConfig, $this->oauth, $this->collections, $this->userSync, $this->lifecycle, $this->jobs, $time, $urls, $l, new NullLogger(), $userId);
	}

	public function testChangingClientIdInvalidatesTokensButASecretRotationDoesNot(): void {
		$this->lifecycle->expects(self::once())->method('invalidateAllTokens')->willReturn(4);
		$r = $this->controller()->setAdminConfig(['client_secret' => 'new-secret', 'tenant' => 'old-tenant', 'client_id' => 'old-id']);
		self::assertSame(0, $r->getData()['invalidatedUsers']);
		self::assertSame('new-secret', $this->config['client_secret']);
		$r = $this->controller()->setAdminConfig(['client_id' => 'new-id']);
		self::assertSame(4, $r->getData()['invalidatedUsers']);
	}

	public function testFirstConfigurationAndPlaceholderSecretDoNotInvalidate(): void {
		$this->config['client_id'] = '';
		$this->lifecycle->expects(self::never())->method('invalidateAllTokens');
		$this->controller()->setAdminConfig(['client_id' => 'id', 'client_secret' => 'dummySecret', 'ignored' => 'x']);
		self::assertSame('sec', $this->config['client_secret'], 'the placeholder must not overwrite the stored secret');
		self::assertArrayNotHasKey('ignored', $this->config);
	}

	public function testAdminConfigValidation(): void {
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->setAdminConfig(['secret_expires' => 'tomorrow'])->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->setAdminConfig(['client_id' => str_repeat('x', 65)])->getStatus());
		self::assertSame(Http::STATUS_OK, $this->controller()->setAdminConfig(['secret_expires' => '2027-01-31'])->getStatus());
		self::assertSame('2027-01-31', $this->config['secret_expires']);
	}

	public function testRedirectErrorFromMicrosoftIsMappedAndNotExchanged(): void {
		$this->oauth->expects(self::never())->method('handleRedirect');
		$r = $this->controller()->oauthRedirect('', '', 'invalid_request', 'AADSTS50011: redirect mismatch. Trace ID: 1');
		$url = $r->getRedirectURL();
		self::assertStringContainsString('m365=error', $url);
		self::assertStringContainsString(urlencode('AADSTS50011'), $url);
		self::assertStringNotContainsString('Trace', $url);
	}

	public function testRedirectWithADifferentAccountResetsThePairs(): void {
		$this->oauth->method('handleRedirect')->willReturn(['userPrincipalName' => 'bob@example.com']);
		$this->lifecycle->expects(self::once())->method('forgetPairs')->with('u');
		$r = $this->controller()->oauthRedirect('code', 'state');
		self::assertStringContainsString('m365=connected#m365_personal', $r->getRedirectURL());
	}

	public function testRedirectWithTheSameAccountKeepsThePairs(): void {
		$this->oauth->method('handleRedirect')->willReturn(['userPrincipalName' => 'Ann@Example.com']);
		$this->lifecycle->expects(self::never())->method('forgetPairs');
		$this->controller()->oauthRedirect('code', 'state');
	}

	public function testDisconnectDelegatesPurgeFlag(): void {
		$this->lifecycle->expects(self::once())->method('disconnect')->with('u', true);
		self::assertSame(['connected' => false], $this->controller()->disconnect(true)->getData());
	}

	public function testSyncNowRefusesWhileReauthIsPending(): void {
		$this->reauth = true;
		$this->userSync->expects(self::never())->method('syncUser');
		$r = $this->controller()->syncNow();
		self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		self::assertSame(SyncError::REAUTH_REQUIRED, $r->getData()['error']);
	}

	public function testSyncNowReportsConfigurationAndServerErrorsInsteadOfQueueing(): void {
		$this->jobs->expects(self::never())->method('add');
		$this->userSync->method('syncUser')->willThrowException(new ConfigurationException('x', 'invalid_client'));
		$r = $this->controller()->syncNow();
		self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		self::assertSame(SyncError::CONFIG_ERROR, $r->getData()['error']);

		$this->userSync = $this->createMock(UserSyncService::class);
		$this->userSync->method('syncUser')->willThrowException(new \RuntimeException('db'));
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $this->controller()->syncNow()->getStatus());
	}

	public function testSyncNowQueuesTheRemainderOfATruncatedRun(): void {
		$done = new SyncResult();
		$cut = new SyncResult();
		$cut->truncated = true;
		$cut->remaining = 7;
		$this->userSync->method('syncUser')->willReturn([1 => $done, 2 => $cut]);
		$this->jobs->method('has')->willReturn(false);
		$this->jobs->expects(self::once())->method('add')->with(UserSyncJob::class, ['user_id' => 'u']);
		$data = $this->controller()->syncNow()->getData();
		self::assertFalse($data['complete']);
		self::assertTrue($data['queued']);
		self::assertCount(2, $data['pairs']);
	}

	public function testSyncStatusUsesTheClockForTheLock(): void {
		$p = new Collection();
		$p->setId(1);
		$p->setLockedUntil(1500);
		$this->collections->method('findEnabledByUser')->willReturn([$p]);
		$this->jobs->method('has')->willReturn(true);
		$data = $this->controller()->syncStatus()->getData();
		self::assertTrue($data['running']);
		self::assertTrue($data['queued']);
	}

	public function testAnonymousRequestsAreRejected(): void {
		$c = $this->controller(null);
		self::assertSame(Http::STATUS_UNAUTHORIZED, $c->getOauthUrl()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $c->disconnect()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $c->syncNow()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $c->syncStatus()->getStatus());
	}
}
