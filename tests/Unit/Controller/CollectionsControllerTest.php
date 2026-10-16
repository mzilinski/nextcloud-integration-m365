<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Controller;

use OCA\IntegrationM365\Controller\CollectionsController;
use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Db\CollectionMapper;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Exception\TokenException;
use OCA\IntegrationM365\Service\RemoteCollectionsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CollectionsControllerTest extends TestCase {
	private CollectionMapper&MockObject $collections;
	private RemoteCollectionsService&MockObject $remote;
	private CollectionsController $controller;

	protected function setUp(): void {
		$this->collections = $this->createMock(CollectionMapper::class);
		$this->collections->method('insert')->willReturnCallback(static function (Collection $c) {
			$c->setId(99);
			return $c;
		});
		$this->collections->method('update')->willReturnArgument(0);
		$this->remote = $this->createMock(RemoteCollectionsService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->controller = new CollectionsController('integration_m365', $this->createMock(IRequest::class), $this->collections, $this->remote, $l, 'u');
	}

	public function testInvalidParametersAreRejectedBeforeAnyGraphCall(): void {
		$this->remote->expects(self::never())->method('isReadOnly');
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('mail', 'id')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('calendar', '')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('calendar', str_repeat('x', 513))->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('calendar', 'id', str_repeat('n', 256))->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('calendar', 'id', 'n', true, str_repeat('ü', 256))->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update('calendar', 'id', 'n', true, 'x', 'merge')->getStatus());
	}

	public function testNewPairIsScopedToTheUserAndReadOnlyForcesImport(): void {
		$this->remote->method('isReadOnly')->with('u', 'calendar', 'rid')->willReturn(true);
		$this->collections->expects(self::once())->method('findByRemote')->with('u', 'calendar', 'rid')->willThrowException(new DoesNotExistException(''));
		$r = $this->controller->update('calendar', 'rid', 'Holidays', true, '', 'local');
		/** @var Collection $pair */
		$pair = $r->getData();
		self::assertSame('u', $pair->getUserId());
		self::assertSame('import', $pair->getConflictPolicy());
		self::assertSame('M365 Holidays', $pair->getLocalName());
		self::assertSame(99, $pair->getId());
	}

	public function testEnablingAPairClearsItsLastError(): void {
		$existing = new Collection();
		$existing->setId(5);
		$existing->setUserId('u');
		$existing->setEnabled(false);
		$existing->setLastError('local_missing');
		$existing->setRemoteName('Cal');
		$this->collections->method('findByRemote')->willReturn($existing);
		$this->remote->method('isReadOnly')->willReturn(false);
		$this->controller->update('calendar', 'rid', '', true, 'My cal');
		self::assertNull($existing->getLastError());
		self::assertSame('My cal', $existing->getLocalName());
		$existing->setLastError('items_failed:2');
		$this->controller->update('calendar', 'rid', '', true, 'My cal');
		self::assertSame('items_failed:2', $existing->getLastError(), 'no transition, error stays');
	}

	public function testGraphProblemsDuringUpdateAreReportedNotCrashed(): void {
		$this->remote->method('isReadOnly')->willThrowException(new GraphException('503', 503, 'ServiceUnavailable'));
		self::assertSame(Http::STATUS_BAD_GATEWAY, $this->controller->update('calendar', 'rid')->getStatus());
		$this->remote = $this->createMock(RemoteCollectionsService::class);
		$this->remote->method('listAll')->willThrowException(new TokenException('reconnect'));
		$this->setUpControllerWithRemote();
		$r = $this->controller->index();
		self::assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		self::assertTrue($r->getData()['reauthRequired']);
	}

	private function setUpControllerWithRemote(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$this->controller = new CollectionsController('integration_m365', $this->createMock(IRequest::class), $this->collections, $this->remote, $l, 'u');
	}

	public function testIndexMergesStoredPairsIntoTheRemoteListing(): void {
		$this->remote->method('listAll')->willReturn([
			'calendar' => [['id' => 'c1', 'name' => 'Work', 'readOnly' => false, 'isDefault' => true]],
			'contacts' => [],
			'tasks' => [['id' => 't1', 'name' => 'Tasks', 'readOnly' => false, 'isDefault' => true]],
		]);
		$pair = new Collection();
		$pair->setId(3);
		$pair->setType('calendar');
		$pair->setRemoteId('c1');
		$pair->setEnabled(true);
		$pair->setLocalName('Arbeit');
		$pair->setLastError('reauth_required');
		$this->collections->method('findByUser')->with('u')->willReturn([$pair]);
		$data = $this->controller->index()->getData();
		self::assertSame(3, $data['calendar'][0]['id']);
		self::assertSame('Arbeit', $data['calendar'][0]['localName']);
		self::assertSame('reauth_required', $data['calendar'][0]['lastError']);
		self::assertNull($data['tasks'][0]['id']);
		self::assertSame('M365 Tasks', $data['tasks'][0]['localName']);
		self::assertSame([], $data['contacts']);
	}
}
