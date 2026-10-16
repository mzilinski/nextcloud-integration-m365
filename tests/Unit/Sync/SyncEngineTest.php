<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Sync;

use OCA\IntegrationM365\Db\Collection;
use OCA\IntegrationM365\Service\Mapping\CalendarItemMapper;
use OCA\IntegrationM365\Service\Mapping\ContactItemMapper;
use OCA\IntegrationM365\Service\Mapping\VObjectUid;
use OCA\IntegrationM365\Sync\SyncEngine;
use OCA\IntegrationM365\Sync\SyncResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncEngineTest extends TestCase {
	private FakeLocal $local;
	private FakeRemote $remote;
	private FakeItemMapper $items;
	private SyncEngine $engine;
	private Collection $pair;

	protected function setUp(): void {
		$this->local = new FakeLocal();
		$this->remote = new FakeRemote();
		$this->items = new FakeItemMapper();
		$this->engine = new SyncEngine($this->items, new NullLogger());
		$this->pair = new Collection();
		$this->pair->setId(7);
		$this->pair->setConflictPolicy('remote');
	}

	private function syncOnce(bool $dryRun = false): SyncResult {
		return $this->engine->sync($this->pair, $this->local, $this->remote, new CalendarItemMapper(), $dryRun);
	}

	private static function ics(string $uid, string $summary, string $start = '20260710T090000Z'): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:$summary\r\nDTSTART:$start\r\nDTEND:20260710T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	private static function event(string $subject): array {
		return ['subject' => $subject, 'body' => ['contentType' => 'text', 'content' => ''], 'isAllDay' => false,
			'start' => ['dateTime' => '2026-07-10T09:00:00.0000000', 'timeZone' => 'UTC'],
			'end' => ['dateTime' => '2026-07-10T10:00:00.0000000', 'timeZone' => 'UTC'], 'showAs' => 'busy'];
	}

	public function testInitialSyncCopiesBothWaysAndSecondRunIsNoop(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$rid = $this->remote->seed(self::event('Remote B'));

		$r = $this->syncOnce();
		self::assertSame(1, $r->remoteCreated);
		self::assertSame(1, $r->localCreated);
		self::assertCount(2, $this->items->rows);
		self::assertCount(2, $this->remote->objects);
		self::assertCount(2, $this->local->objects);
		// the remote-born event keeps Graph's iCalUId as local UID
		$created = array_values(array_diff(array_keys($this->local->objects), ['a.ics']))[0];
		self::assertSame('ICAL-' . $rid, VObjectUid::uidOf($this->local->objects[$created]['data']));
		// the local-born event keeps its UID in the correlation (Graph assigned its own iCalUId)
		$uids = array_map(static fn ($i) => $i->getUid(), $this->items->rows);
		self::assertContains('uid-a', $uids);

		// Exchange bumped the changeKey after our upload; the next run must not write anything.
		$r2 = $this->syncOnce();
		self::assertSame(0, $r2->writes(), implode("\n", $r2->log));
		self::assertStringContainsString('echo absorbed', implode("\n", $r2->log));
		$r3 = $this->syncOnce();
		self::assertSame(0, $r3->writes());
		self::assertStringNotContainsString('echo', implode("\n", $r3->log));
	}

	public function testExpiredTimeBudgetTruncatesWithoutAdvancingCursorsAndNextRunFinishes(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$this->remote->seed(self::event('Remote B'));

		// deadline already in the past: listing happens, no item may be written
		$r = $this->engine->sync($this->pair, $this->local, $this->remote, new CalendarItemMapper(), false, microtime(true) - 1);
		self::assertTrue($r->truncated);
		self::assertSame(0, $r->writes(), implode("\n", $r->log));
		self::assertNull($this->pair->getLocalSyncToken());
		self::assertNull($this->pair->getDeltaLink());
		self::assertStringContainsString('time budget exhausted', implode("\n", $r->log));
		self::assertSame(2, $r->remaining);

		// the next unbudgeted run picks everything up exactly once
		$r2 = $this->syncOnce();
		self::assertFalse($r2->truncated);
		self::assertSame(1, $r2->localCreated);
		self::assertSame(1, $r2->remoteCreated);
		self::assertCount(2, $this->local->objects);
		self::assertCount(2, $this->remote->objects);
		self::assertNotNull($this->pair->getLocalSyncToken());
	}

	public function testImportOnlyNeverWritesRemoteAndRestoresLocalDeletions(): void {
		$this->pair->setConflictPolicy('import');
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$rid = $this->remote->seed(self::event('Remote B'));

		$r = $this->syncOnce();
		self::assertSame(1, $r->localCreated);
		self::assertSame(0, $r->remoteCreated, 'local-born objects must not be uploaded');
		self::assertCount(1, $this->remote->objects);
		self::assertStringContainsString('import only', implode("\n", $r->log));
		$imported = array_values(array_diff(array_keys($this->local->objects), ['a.ics']))[0];

		// local edit of an imported object: ignored, and overwritten by the next remote change
		$this->local->put($imported, self::ics('ICAL-' . $rid, 'Edited locally'));
		$r2 = $this->syncOnce();
		self::assertSame(0, $r2->writes(), implode("\n", $r2->log));
		self::assertSame([], array_values(array_filter($this->remote->calls, static fn ($c) => $c[0] !== 'changes')), 'no remote write calls');
		$this->remote->update($rid, ['subject' => 'Remote B2']);
		$this->syncOnce();
		self::assertStringContainsString('SUMMARY:Remote B2', $this->local->objects[$imported]['data']);

		// local deletion: correlation is forgotten, the next remote change brings it back
		$this->local->remove($imported);
		$r4 = $this->syncOnce();
		self::assertSame(0, $r4->writes(), implode("\n", $r4->log));
		self::assertCount(1, $this->remote->objects);
		$this->remote->update($rid, ['subject' => 'Remote B3']);
		$r5 = $this->syncOnce();
		self::assertSame(1, $r5->localCreated);
		self::assertCount(2, $this->local->objects);
	}

	public function testLocalEditGoesToRemoteAndRemoteEditComesBack(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$rid = $this->remote->seed(self::event('Remote B'));
		$this->syncOnce();
		$this->syncOnce();

		$this->local->put('a.ics', self::ics('uid-a', 'Local A edited'));
		$r = $this->syncOnce();
		self::assertSame(1, $r->remoteUpdated);
		self::assertSame(0, $r->localUpdated);
		$remoteA = array_values(array_filter($this->remote->objects, static fn ($o) => ($o['subject'] ?? '') === 'Local A edited'));
		self::assertCount(1, $remoteA);

		$this->remote->objects[$rid]['subject'] = 'Remote B edited';
		$this->remote->objects[$rid]['changeKey'] = 'ck-new';
		$this->remote->journal[] = [$rid, false];
		$r = $this->syncOnce();
		self::assertSame(1, $r->localUpdated);
		$localB = array_values(array_filter($this->local->objects, static fn ($o) => str_contains($o['data'], 'Remote B edited')));
		self::assertCount(1, $localB);
		self::assertSame('ICAL-' . $rid, VObjectUid::uidOf($localB[0]['data']));
		self::assertSame(0, $this->syncOnce()->writes());
	}

	public function testDeletionsPropagateBothWays(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$rid = $this->remote->seed(self::event('Remote B'));
		$this->syncOnce();
		$this->syncOnce();

		$this->local->remove('a.ics');
		$this->remote->removeRemote($rid);
		$r = $this->syncOnce();
		self::assertSame(1, $r->remoteDeleted);
		self::assertSame(1, $r->localDeleted);
		self::assertCount(0, $this->items->rows);
		self::assertCount(0, $this->local->objects);
		self::assertCount(0, $this->remote->objects);
	}

	public function testConflictRemoteWinsByDefaultAndLocalWinsWhenConfigured(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Original'));
		$this->syncOnce();
		$this->syncOnce();
		$rid = array_key_first($this->remote->objects);

		$this->local->put('a.ics', self::ics('uid-a', 'Local change'));
		$this->remote->objects[$rid]['subject'] = 'Remote change';
		$this->remote->objects[$rid]['changeKey'] = 'ck-conf';
		$this->remote->journal[] = [$rid, false];
		$r = $this->syncOnce();
		self::assertSame(1, $r->conflicts);
		self::assertStringContainsString('Remote change', $this->local->objects['a.ics']['data']);
		self::assertSame('Remote change', $this->remote->objects[$rid]['subject']);

		$this->pair->setConflictPolicy('local');
		$this->syncOnce();
		$this->local->put('a.ics', self::ics('uid-a', 'Local again'));
		$this->remote->objects[$rid]['subject'] = 'Remote again';
		$this->remote->objects[$rid]['changeKey'] = 'ck-conf2';
		$this->remote->journal[] = [$rid, false];
		$r = $this->syncOnce();
		self::assertSame(1, $r->conflicts);
		self::assertSame('Local again', $this->remote->objects[$rid]['subject']);
		self::assertStringContainsString('Local again', $this->local->objects['a.ics']['data']);
	}

	public function testExpiredDeltaRelistsWithoutDuplicates(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$rid = $this->remote->seed(self::event('Remote B'));
		$this->syncOnce();
		$this->syncOnce();
		$this->remote->removeRemote($rid);
		$this->remote->expireNextDelta = true;
		$r = $this->syncOnce();
		self::assertSame(1, $r->localDeleted);
		self::assertSame(0, $r->localCreated);
		self::assertSame(0, $r->remoteCreated);
		self::assertCount(1, $this->items->rows);
	}

	public function testPreExistingSameUidOnBothSidesIsPairedNotDuplicated(): void {
		$this->local->put('x.ics', self::ics('ICAL-R1', 'Same'));
		$this->remote->seed(self::event('Same') + ['id' => 'R1', 'iCalUId' => 'ICAL-R1']);
		$r = $this->syncOnce();
		self::assertSame(0, $r->writes());
		self::assertStringContainsString('paired by uid', implode("\n", $r->log));
		self::assertCount(1, $this->items->rows);
	}

	public function testDryRunWritesNothing(): void {
		$this->local->put('a.ics', self::ics('uid-a', 'Local A'));
		$this->remote->seed(self::event('Remote B'));
		$r = $this->syncOnce(true);
		self::assertSame(1, $r->remoteCreated);
		self::assertSame(1, $r->localCreated);
		self::assertCount(0, $this->items->rows);
		self::assertCount(1, $this->remote->objects);
		self::assertCount(1, $this->local->objects);
		self::assertNull($this->pair->getDeltaLink());
	}

	public function testUnavailablePhotoDefersTheContactAndKeepsCursors(): void {
		$engine = new SyncEngine($this->items, new NullLogger());
		$mapper = new ContactItemMapper();
		$id = $this->remote->seed(['displayName' => 'Ann', 'givenName' => 'Ann', 'surname' => 'A']);
		$this->remote->photos[$id] = ['PHOTOBYTES', 'image/jpeg'];
		$this->remote->seed(['displayName' => 'Bob', 'givenName' => 'Bob', 'surname' => 'B']);

		// throttled photo fetch: Ann waits, Bob is written, cursors stay
		$this->remote->photoUnavailable[$id] = true;
		$r = $engine->sync($this->pair, $this->local, $this->remote, $mapper);
		self::assertTrue($r->truncated);
		self::assertSame(1, $r->localCreated);
		self::assertSame(1, $r->remaining);
		self::assertNull($this->pair->getDeltaLink());
		self::assertStringContainsString('deferred', implode("\n", $r->log));
		self::assertStringNotContainsString('Ann', implode('', array_column($this->local->objects, 'data')));

		// next run: photo available, Ann arrives with her picture, Bob is an absorbed echo
		$r2 = $engine->sync($this->pair, $this->local, $this->remote, $mapper);
		self::assertFalse($r2->truncated);
		self::assertSame(1, $r2->localCreated);
		self::assertNotNull($this->pair->getDeltaLink());
		$ann = array_values(array_filter($this->local->objects, static fn ($o) => str_contains($o['data'], 'Ann')))[0]['data'];
		self::assertStringContainsString(base64_encode('PHOTOBYTES'), $ann);
	}

	public function testSeriesInstancesAreSkipped(): void {
		$this->remote->seed(self::event('occurrence') + ['type' => 'occurrence']);
		$r = $this->syncOnce();
		self::assertSame(1, $r->skipped);
		self::assertSame(0, $r->localCreated);
	}
}
