<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\BackgroundJob;

use OCA\IntegrationM365\BackgroundJob\SyncJob;
use OCA\IntegrationM365\BackgroundJob\UserSyncJob;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Sync\SyncResult;
use OCA\IntegrationM365\Sync\UserSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class JobsTest extends TestCase {
	private static function outcome(bool $truncated, bool $locked = false): SyncResult {
		$r = new SyncResult();
		$r->truncated = $truncated;
		$r->locked = $locked;
		return $r;
	}

	private function runJob(IJob $job, mixed $argument): void {
		$m = new \ReflectionMethod($job, 'run');
		$m->invoke($job, $argument);
	}

	public function testUserSyncJobRequeuesOnlyForRealTruncation(): void {
		$sync = $this->createMock(UserSyncService::class);
		$sync->method('syncUser')->willReturnOnConsecutiveCalls(
			[1 => self::outcome(true)],
			[1 => self::outcome(true, true)],
			[1 => self::outcome(false)],
		);
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects(self::once())->method('add')->with(UserSyncJob::class, ['user_id' => 'u']);
		$job = new UserSyncJob($this->createMock(ITimeFactory::class), $sync, $jobs);
		$this->runJob($job, ['user_id' => 'u']); // truncated: re-queue
		$this->runJob($job, ['user_id' => 'u']); // locked by another run: leave it
		$this->runJob($job, ['user_id' => 'u']); // done
	}

	public function testUserSyncJobIgnoresMissingArgument(): void {
		$sync = $this->createMock(UserSyncService::class);
		$sync->expects(self::never())->method('syncUser');
		$this->runJob(new UserSyncJob($this->createMock(ITimeFactory::class), $sync, $this->createMock(IJobList::class)), null);
	}

	public function testSyncJobIsTimeSensitiveAndIsolatesFailingUsers(): void {
		$collections = $this->createMock(CollectionMapper::class);
		$collections->method('findUsersWithEnabledPairs')->willReturn(['a', 'b', 'c']);
		$sync = $this->createMock(UserSyncService::class);
		$seen = [];
		$sync->method('syncUser')->willReturnCallback(static function (string $uid) use (&$seen) {
			$seen[] = $uid;
			if ($uid === 'b') {
				throw new \RuntimeException('db down for b');
			}
			return [];
		});
		$job = new SyncJob($this->createMock(ITimeFactory::class), $collections, $sync, new NullLogger());
		self::assertSame(IJob::TIME_SENSITIVE, $job->isTimeSensitive() ? IJob::TIME_SENSITIVE : IJob::TIME_INSENSITIVE);
		self::assertSame(300, $job->getInterval());
		$this->runJob($job, null);
		self::assertSame(['a', 'b', 'c'], $seen, 'c still runs after b failed');
	}
}
