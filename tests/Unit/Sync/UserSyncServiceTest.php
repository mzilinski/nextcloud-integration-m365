<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Sync;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\ConfigurationException;
use OCA\IntegrationM365\Exception\LocalCollectionMissingException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\LifecycleService;
use OCA\IntegrationM365\Service\Local\LocalCalendarService;
use OCA\IntegrationM365\Service\Local\LocalContactsService;
use OCA\IntegrationM365\Service\OAuthService;
use OCA\IntegrationM365\Service\Remote\RemoteCalendarService;
use OCA\IntegrationM365\Service\Remote\RemoteContactsService;
use OCA\IntegrationM365\Service\Remote\RemoteTasksService;
use OCA\IntegrationM365\Sync\SyncEngine;
use OCA\IntegrationM365\Sync\SyncError;
use OCA\IntegrationM365\Sync\SyncResult;
use OCA\IntegrationM365\Sync\UserSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UserSyncServiceTest extends TestCase {
	private CollectionMapper&MockObject $collections;
	private SyncEngine&MockObject $engine;
	private LocalCalendarService&MockObject $localCalendars;
	private RemoteCalendarService&MockObject $remoteCalendars;
	private OAuthService&MockObject $oauth;
	private LifecycleService&MockObject $lifecycle;
	private UserSyncService $service;
	private bool $reauth = false;
	private bool $lockFree = true;

	protected function setUp(): void {
		$this->collections = $this->createMock(CollectionMapper::class);
		$this->collections->method('update')->willReturnArgument(0);
		$this->collections->method('tryLock')->willReturnCallback(fn () => $this->lockFree);
		$this->engine = $this->createMock(SyncEngine::class);
		$this->localCalendars = $this->createMock(LocalCalendarService::class);
		$this->localCalendars->method('ensureCalendar')->willReturn([42, 'm365-cal']);
		$this->remoteCalendars = $this->createMock(RemoteCalendarService::class);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->oauth->method('getAccountInfo')->willReturnCallback(fn () => ['name' => '', 'principal' => '', 'reauthRequired' => $this->reauth]);
		$this->lifecycle = $this->createMock(LifecycleService::class);
		$this->lifecycle->method('resetPair')->willReturnCallback(static function (Collection $p, bool $enabled) {
			$p->setEnabled($enabled);
			$p->setLastError(null);
			return $p;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$this->service = new UserSyncService(
			$this->collections, $this->engine, $this->localCalendars, $this->createMock(LocalContactsService::class),
			$this->remoteCalendars, $this->createMock(RemoteTasksService::class), $this->createMock(RemoteContactsService::class),
			$this->oauth, $this->lifecycle, $time, new NullLogger(),
		);
	}

	private function pair(int $id): Collection {
		$p = new Collection();
		$p->setId($id);
		$p->setType(Application::TYPE_CALENDAR);
		$p->setEnabled(true);
		$p->setLocalId(42);
		$p->setLocalUri('m365-cal');
		$p->setRemoteId('rid');
		return $p;
	}

	public function testSuccessfulRunRecordsLastSyncAndClearsLockAndError(): void {
		$pair = $this->pair(1);
		$pair->setLastError('old');
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$result = new SyncResult();
		$this->engine->expects(self::once())->method('sync')->willReturn($result);
		$r = $this->service->syncUser('u');
		self::assertSame([1 => $result], $r);
		self::assertSame(1000, $pair->getLastSync());
		self::assertNull($pair->getLastError());
		self::assertNull($pair->getLockedUntil());
	}

	public function testItemErrorsBecomeAMachineReadableCode(): void {
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$result = new SyncResult();
		$result->errors = 3;
		$this->engine->method('sync')->willReturn($result);
		$this->service->syncUser('u');
		self::assertSame(SyncError::ITEMS_FAILED . '3', $pair->getLastError());
	}

	public function testFlaggedUserIsSkippedWithoutTouchingGraph(): void {
		$this->reauth = true;
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->engine->expects(self::never())->method('sync');
		$this->collections->expects(self::once())->method('update');
		self::assertSame([], $this->service->syncUser('u'));
		self::assertSame(SyncError::REAUTH_REQUIRED, $pair->getLastError());
	}

	public function testTokenExceptionStopsTheRemainingPairsOfTheUser(): void {
		$a = $this->pair(1);
		$b = $this->pair(2);
		$this->collections->method('findEnabledByUser')->willReturn([$a, $b]);
		$this->engine->expects(self::once())->method('sync')->willThrowException(new TokenException('dead'));
		$this->service->syncUser('u');
		self::assertSame(SyncError::REAUTH_REQUIRED, $a->getLastError());
		self::assertNull($a->getLockedUntil());
		self::assertNull($b->getLastError(), 'second pair never started');
	}

	public function testConfigurationErrorIsRecordedForTheAdmin(): void {
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->engine->method('sync')->willThrowException(new ConfigurationException('bad secret', 'invalid_client'));
		$this->service->syncUser('u');
		self::assertSame(SyncError::CONFIG_ERROR, $pair->getLastError());
	}

	public function testMissingLocalCollectionDisablesAndResetsThePair(): void {
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->localCalendars->method('ensureCalendar')->willThrowException(new LocalCollectionMissingException('gone'));
		$this->engine->expects(self::never())->method('sync');
		$this->lifecycle->expects(self::once())->method('resetPair')->with($pair, false);
		$this->service->syncUser('u');
		self::assertFalse($pair->getEnabled());
		self::assertNull($pair->getLocalId());
		self::assertSame(SyncError::LOCAL_MISSING, $pair->getLastError());
	}

	public function testMissingLocalCollectionInDryRunChangesNothing(): void {
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->localCalendars->method('ensureCalendar')->willThrowException(new LocalCollectionMissingException('gone'));
		$this->lifecycle->expects(self::never())->method('resetPair');
		$this->collections->expects(self::never())->method('update');
		$this->service->syncUser('u', true);
		self::assertTrue($pair->getEnabled());
	}

	public function testLockedPairIsReportedAsLockedNotAsPendingWork(): void {
		$this->lockFree = false;
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->engine->expects(self::never())->method('sync');
		$r = $this->service->syncUser('u');
		self::assertTrue($r[1]->truncated);
		self::assertTrue($r[1]->locked);
	}

	public function testLockIsReleasedWhenTheEngineThrows(): void {
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->engine->method('sync')->willThrowException(new \RuntimeException('boom'));
		$this->service->syncUser('u');
		self::assertSame('boom', $pair->getLastError());
		self::assertNull($pair->getLockedUntil());
	}

	public function testFullRunForgetsCursorsUnderTheLock(): void {
		$pair = $this->pair(1);
		$pair->setDeltaLink('d');
		$pair->setLocalSyncToken(9);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->engine->method('sync')->willReturnCallback(static function (Collection $p) {
			self::assertNull($p->getDeltaLink());
			self::assertNull($p->getLocalSyncToken());
			return new SyncResult();
		});
		$this->service->syncUser('u', false, null, null, null, true);
	}

	public function testDeadlineBeforeStartYieldsTruncatedPlaceholder(): void {
		$pair = $this->pair(1);
		$this->collections->method('findEnabledByUser')->willReturn([$pair]);
		$this->engine->expects(self::never())->method('sync');
		$r = $this->service->syncUser('u', false, null, null, microtime(true) - 1);
		self::assertTrue($r[1]->truncated);
		self::assertFalse($r[1]->locked);
	}
}
