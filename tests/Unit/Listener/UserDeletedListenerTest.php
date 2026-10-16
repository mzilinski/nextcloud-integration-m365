<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Listener;

use OCA\IntegrationM365\Listener\UserDeletedListener;
use OCA\IntegrationM365\Service\LifecycleService;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

class UserDeletedListenerTest extends TestCase {
	public function testDeletedUserIsPurged(): void {
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->expects(self::once())->method('purgeUser')->with('gone');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('gone');
		(new UserDeletedListener($lifecycle))->handle(new UserDeletedEvent($user));
	}

	public function testOtherEventsAreIgnored(): void {
		$lifecycle = $this->createMock(LifecycleService::class);
		$lifecycle->expects(self::never())->method('purgeUser');
		(new UserDeletedListener($lifecycle))->handle(new Event());
	}
}
