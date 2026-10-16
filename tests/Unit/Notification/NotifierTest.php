<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Notification;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Notification\Notifier;
use OCA\IntegrationM365\Settings\PersonalSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	private Notifier $notifier;
	private IURLGenerator $urls;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $args) => 'https://nc/settings/' . $args['section']);
		$this->urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$this->urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $p) => 'https://nc' . $p);
		$this->notifier = new Notifier($factory, $this->urls);
	}

	public function testReauthNotificationLinksToTheConnectedAccountsSection(): void {
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn(Application::APP_ID);
		$n->method('getSubject')->willReturn(Notifier::SUBJECT_REAUTH);
		$n->method('setParsedSubject')->willReturnSelf();
		$n->method('setParsedMessage')->willReturnSelf();
		$n->expects(self::once())->method('setLink')->with('https://nc/settings/' . PersonalSection::ID)->willReturnSelf();
		$n->expects(self::once())->method('setIcon')->with('https://nc/img/app-dark.svg')->willReturnSelf();
		self::assertSame($n, $this->notifier->prepare($n, 'de'));
		self::assertSame(Application::APP_ID, $this->notifier->getID());
	}

	public function testForeignNotificationsAreRejected(): void {
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('other');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($n, 'en');
	}
}
