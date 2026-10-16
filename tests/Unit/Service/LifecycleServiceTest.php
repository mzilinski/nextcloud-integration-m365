<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Service;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Db\ItemMapper;
use OCA\IntegrationM365\Service\LifecycleService;
use OCA\IntegrationM365\Service\OAuthService;
use OCP\BackgroundJob\IJobList;
use OCP\Config\IUserConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LifecycleServiceTest extends TestCase {
	private CollectionMapper&MockObject $collections;
	private ItemMapper&MockObject $items;
	private OAuthService&MockObject $oauth;
	private IUserConfig&MockObject $userConfig;
	private IJobList&MockObject $jobs;
	private IManager&MockObject $notifications;
	private LifecycleService $lifecycle;

	protected function setUp(): void {
		$this->collections = $this->createMock(CollectionMapper::class);
		$this->items = $this->createMock(ItemMapper::class);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->notifications = $this->createMock(IManager::class);
		$n = $this->createMock(INotification::class);
		$n->method('setApp')->willReturnSelf();
		$n->method('setUser')->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($n);
		$this->lifecycle = new LifecycleService($this->collections, $this->items, $this->oauth, $this->userConfig, $this->jobs, $this->notifications, new NullLogger());
	}

	private static function pair(int $id, bool $enabled, ?int $lockedUntil = null): Collection {
		$p = new Collection();
		$p->setId($id);
		$p->setEnabled($enabled);
		$p->setLockedUntil($lockedUntil);
		$p->setLastError('x');
		$p->setDeltaLink('d');
		$p->setLocalSyncToken(5);
		$p->setPendingItems(3);
		return $p;
	}

	public function testDisconnectWithoutPurgeDisablesPairsAndDropsTheQueuedJob(): void {
		$enabled = self::pair(1, true);
		$disabled = self::pair(2, false);
		$locked = self::pair(3, false, 99);
		$this->collections->method('findByUser')->with('u')->willReturn([$enabled, $disabled, $locked]);
		$this->oauth->expects(self::once())->method('disconnect')->with('u');
		$this->jobs->expects(self::once())->method('remove')->with(UserSyncJob::class, ['user_id' => 'u']);
		$this->collections->expects(self::exactly(2))->method('update')->willReturnArgument(0);
		$this->collections->expects(self::never())->method('deleteByUser');
		$this->lifecycle->disconnect('u', false);
		self::assertFalse($enabled->getEnabled());
		self::assertNull($locked->getLockedUntil());
	}

	public function testDisconnectWithPurgeDeletesCorrelationsBeforeThePairs(): void {
		$this->collections->method('findByUser')->willReturn([self::pair(1, true), self::pair(2, false)]);
		$this->items->expects(self::exactly(2))->method('deleteByCollection')->with(self::logicalOr(1, 2));
		$this->collections->expects(self::once())->method('deleteByUser')->with('u');
		$this->lifecycle->disconnect('u', true);
	}

	public function testPurgeUserRemovesEveryTrace(): void {
		$this->collections->method('findByUser')->willReturn([self::pair(7, true)]);
		$this->items->expects(self::once())->method('deleteByCollection')->with(7);
		$this->collections->expects(self::once())->method('deleteByUser')->with('u');
		$this->jobs->expects(self::once())->method('remove')->with(UserSyncJob::class, ['user_id' => 'u']);
		$this->userConfig->method('getKeys')->with('u', Application::APP_ID)->willReturn(['refresh_token', 'user_name']);
		$this->userConfig->expects(self::exactly(2))->method('deleteUserConfig')->with('u', Application::APP_ID, self::logicalOr('refresh_token', 'user_name'));
		$this->notifications->expects(self::once())->method('markProcessed');
		$this->lifecycle->purgeUser('u');
	}

	public function testResetPairForgetsCursorsAndCorrelations(): void {
		$p = self::pair(4, true);
		$this->items->expects(self::once())->method('deleteByCollection')->with(4);
		$this->collections->method('update')->willReturnArgument(0);
		$r = $this->lifecycle->resetPair($p, false);
		self::assertNull($r->getDeltaLink());
		self::assertNull($r->getLocalSyncToken());
		self::assertNull($r->getPendingItems());
		self::assertNull($r->getLastError());
		self::assertFalse($r->getEnabled());
	}

	public function testInvalidateAllTokensDropsTokensAndFlagsEveryConnectedUser(): void {
		$this->userConfig->method('getValuesByUsers')->with(Application::APP_ID, 'refresh_token')->willReturn(['a' => 'x', 'b' => 'y']);
		$this->oauth->expects(self::exactly(2))->method('forgetTokens')->with(self::logicalOr('a', 'b'));
		$this->oauth->expects(self::exactly(2))->method('markReauthRequired')->with(self::logicalOr('a', 'b'), 'admin', 'changed');
		self::assertSame(2, $this->lifecycle->invalidateAllTokens('changed'));
	}

	public function testAllUsersMergesPairOwnersAndTokenHolders(): void {
		$this->collections->method('findUsersWithPairs')->willReturn(['a', 'b']);
		$this->userConfig->method('getValuesByUsers')->willReturn(['b' => 'y', 'c' => 'z']);
		self::assertSame(['a', 'b', 'c'], $this->lifecycle->allUsers());
	}
}
