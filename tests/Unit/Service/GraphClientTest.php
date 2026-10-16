<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit\Service;

use OCA\IntegrationM365\Exception\DeltaExpiredException;
use OCA\IntegrationM365\Exception\GraphException;
use OCA\IntegrationM365\Service\GraphClient;
use OCA\IntegrationM365\Service\OAuthService;
use OCA\IntegrationM365\Tests\Unit\Http;
use OCA\IntegrationM365\Tests\Unit\RecordingGraphClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GraphClientTest extends TestCase {
	private IClient&MockObject $client;
	private OAuthService&MockObject $oauth;
	private RecordingGraphClient $graph;
	/** @var list<array{0:string,1:string,2:array}> method, url, options */
	private array $requests = [];
	/** @var list<mixed> IResponse to return or exception to throw, in order */
	private array $queue = [];

	protected function setUp(): void {
		$this->client = $this->createMock(IClient::class);
		$this->client->method('request')->willReturnCallback(function (string $method, string $url, array $options) {
			$this->requests[] = [$method, $url, $options];
			$next = array_shift($this->queue);
			if ($next instanceof \Throwable) {
				throw $next;
			}
			self::assertNotNull($next, 'unexpected request ' . $method . ' ' . $url);
			return $next;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$this->oauth = $this->createMock(OAuthService::class);
		$this->oauth->method('getAccessToken')->willReturnCallback(static fn (string $u, bool $force = false) => $force ? 'fresh' : 'tok');
		$this->graph = new RecordingGraphClient($clientService, $this->oauth, new NullLogger());
	}

	public function testBearerHeaderAndBaseUrl(): void {
		$this->queue[] = Http::ok($this, 200, '{"value":[1]}');
		self::assertSame(['value' => [1]], $this->graph->get('u', '/me/calendars'));
		[$method, $url, $options] = $this->requests[0];
		self::assertSame('GET', $method);
		self::assertSame(GraphClient::BASE . '/me/calendars', $url);
		self::assertSame('Bearer tok', $options['headers']['Authorization']);
		self::assertSame(10, $options['connect_timeout']);
	}

	public function testListAllFollowsNextLink(): void {
		$this->queue[] = Http::ok($this, 200, json_encode(['value' => ['a'], '@odata.nextLink' => GraphClient::BASE . '/me/x?$skip=1']));
		$this->queue[] = Http::ok($this, 200, json_encode(['value' => ['b']]));
		self::assertSame(['a', 'b'], $this->graph->listAll('u', '/me/x'));
		self::assertSame(GraphClient::BASE . '/me/x?$skip=1', $this->requests[1][1]);
	}

	public function testNextLinkOnForeignHostIsRefusedBeforeSendingTheToken(): void {
		$this->queue[] = Http::ok($this, 200, json_encode(['value' => ['a'], '@odata.nextLink' => 'https://evil.example/v1.0/me/x']));
		try {
			$this->graph->listAll('u', '/me/x');
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame('ssrf', $e->graphCode);
		}
		self::assertCount(1, $this->requests, 'nothing was sent to the foreign host');
	}

	/** @return list<array{string,bool}> */
	public static function urls(): array {
		return [
			['https://graph.microsoft.com/v1.0/me', true],
			['https://GRAPH.microsoft.com/v1.0/me', true],
			['http://graph.microsoft.com/v1.0/me', false],
			['https://graph.microsoft.com:8443/me', false],
			['https://user@graph.microsoft.com/me', false],
			['https://graph.microsoft.com.evil.example/me', false],
			['https://evil.example/?graph.microsoft.com', false],
		];
	}

	/** @dataProvider urls */
	public function testAbsoluteUrlAllowList(string $url, bool $ok): void {
		if (!$ok) {
			$this->expectException(GraphException::class);
		}
		self::assertSame($url, GraphClient::absoluteUrl($url));
	}

	public function testDeltaCollectsPagesAndReturnsDeltaLink(): void {
		$this->queue[] = Http::ok($this, 200, json_encode(['value' => [['id' => 1]], '@odata.nextLink' => GraphClient::BASE . '/d?p=2']));
		$this->queue[] = Http::ok($this, 200, json_encode(['value' => [['id' => 2]], '@odata.deltaLink' => GraphClient::BASE . '/d?token=T']));
		$r = $this->graph->delta('u', '/d');
		self::assertSame([['id' => 1], ['id' => 2]], $r['items']);
		self::assertSame(GraphClient::BASE . '/d?token=T', $r['deltaLink']);
		self::assertStringContainsString('odata.maxpagesize=50', $this->requests[0][2]['headers']['Prefer']);
	}

	/** @return list<array{int,string}> */
	public static function expiredDeltas(): array {
		return [[410, 'ErrorResync'], [400, 'ErrorInvalidSyncStateData'], [404, 'syncStateNotFound']];
	}

	/** @dataProvider expiredDeltas */
	public function testExpiredDeltaLinkRaisesDeltaExpired(int $status, string $code): void {
		$this->queue[] = Http::error($this, $status, json_encode(['error' => ['code' => $code, 'message' => 'resync']]));
		$this->expectException(DeltaExpiredException::class);
		$this->graph->delta('u', GraphClient::BASE . '/d?token=OLD');
	}

	public function testThrottlingHonoursRetryAfterSecondsThenSucceeds(): void {
		$this->queue[] = Http::error($this, 429, '', ['Retry-After' => '3']);
		$this->queue[] = Http::ok($this, 200, '{"ok":1}');
		self::assertSame(['ok' => 1], $this->graph->get('u', '/x'));
		self::assertSame([3.0], $this->graph->waits);
	}

	public function testThrottlingWithHttpDateRetryAfter(): void {
		self::assertEqualsWithDelta(90.0, GraphClient::retryAfterSeconds('Thu, 01 Jan 2026 00:01:30 GMT', (int)strtotime('Thu, 01 Jan 2026 00:00:00 GMT')), 0.001);
		self::assertNull(GraphClient::retryAfterSeconds('soon'));
		self::assertNull(GraphClient::retryAfterSeconds(null));
	}

	public function testLongRetryAfterGivesUpImmediately(): void {
		$this->queue[] = Http::error($this, 429, '', ['Retry-After' => '600']);
		try {
			$this->graph->get('u', '/x');
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame('throttled', $e->graphCode);
			self::assertSame(429, $e->httpStatus);
		}
		self::assertSame([], $this->graph->waits);
		self::assertCount(1, $this->requests);
	}

	public function testBackOffWithoutRetryAfterIsExponentialWithJitterAndBounded(): void {
		$this->queue[] = Http::error($this, 503, '');
		$this->queue[] = Http::error($this, 503, '');
		$this->queue[] = Http::error($this, 503, '');
		$this->queue[] = Http::error($this, 503, '{"error":{"code":"ServiceUnavailable","message":"still down"}}');
		try {
			$this->graph->get('u', '/x');
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame('ServiceUnavailable', $e->graphCode);
		}
		self::assertCount(3, $this->graph->waits);
		foreach ($this->graph->waits as $i => $w) {
			self::assertGreaterThanOrEqual((1 << $i) * 0.5, $w);
			self::assertLessThanOrEqual((1 << $i) * 1.0 + 0.001, $w);
		}
		self::assertCount(4, $this->requests);
	}

	public function testServerErrorIsRetriedOnGetButNotOnPost(): void {
		$this->queue[] = Http::error($this, 502, '');
		$this->queue[] = Http::ok($this, 200, '{"a":1}');
		self::assertSame(['a' => 1], $this->graph->get('u', '/x'));

		$this->requests = [];
		$this->queue[] = Http::error($this, 502, '');
		$this->expectException(GraphException::class);
		$this->graph->post('u', '/x', ['b' => 1]);
	}

	public function testNetworkErrorIsRetriedForIdempotentRequestsOnly(): void {
		$this->queue[] = new \RuntimeException('connection reset');
		$this->queue[] = Http::ok($this, 204, '');
		$this->graph->delete('u', '/x');
		self::assertCount(2, $this->requests);

		$this->requests = [];
		$this->queue[] = new \RuntimeException('connection reset');
		try {
			$this->graph->post('u', '/x', []);
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame('network', $e->graphCode);
		}
		self::assertCount(1, $this->requests);
	}

	public function testUnauthorizedTriggersOneForcedRefresh(): void {
		$this->queue[] = Http::error($this, 401, '');
		$this->queue[] = Http::ok($this, 200, '{"a":1}');
		self::assertSame(['a' => 1], $this->graph->get('u', '/x'));
		self::assertSame('Bearer tok', $this->requests[0][2]['headers']['Authorization']);
		self::assertSame('Bearer fresh', $this->requests[1][2]['headers']['Authorization']);

		$this->requests = [];
		$this->queue[] = Http::error($this, 401, '');
		$this->queue[] = Http::error($this, 401, '{"error":{"code":"InvalidAuthenticationToken","message":"nope"}}');
		try {
			$this->graph->get('u', '/x');
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame(401, $e->httpStatus);
			self::assertSame('InvalidAuthenticationToken', $e->graphCode);
		}
		self::assertCount(2, $this->requests, 'only one refresh attempt');
	}

	public function testErrorBodyIsMappedToGraphException(): void {
		$this->queue[] = Http::error($this, 404, json_encode(['error' => ['code' => 'ErrorItemNotFound', 'message' => 'gone']]));
		try {
			$this->graph->get('u', '/x');
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame(404, $e->httpStatus);
			self::assertSame('ErrorItemNotFound', $e->graphCode);
			self::assertSame('gone', $e->getMessage());
		}
		$this->queue[] = Http::error($this, 403, 'not json');
		try {
			$this->graph->get('u', '/x');
			self::fail('expected GraphException');
		} catch (GraphException $e) {
			self::assertSame('HTTP 403', $e->getMessage());
			self::assertSame('', $e->graphCode);
		}
	}

	public function testBatchRetriesThrottledSubRequestsAndKeepsTheRestOfTheResponses(): void {
		$requests = [];
		for ($i = 0; $i < 25; $i++) {
			$requests[] = ['id' => (string)$i, 'method' => 'GET', 'url' => "/me/contacts/$i/photo/\$value"];
		}
		$first = [];
		for ($i = 0; $i < 20; $i++) {
			$first[] = ['id' => (string)$i, 'status' => $i === 3 ? 429 : ($i === 4 ? 404 : 200), 'headers' => $i === 3 ? ['Retry-After' => '2'] : [], 'body' => $i === 3 ? '' : base64_encode("p$i")];
		}
		$this->queue[] = Http::ok($this, 200, json_encode(['responses' => $first]));
		$this->queue[] = Http::ok($this, 200, json_encode(['responses' => [['id' => '3', 'status' => 200, 'headers' => [], 'body' => base64_encode('p3')]]]));
		$this->queue[] = Http::ok($this, 200, json_encode(['responses' => array_map(static fn ($i) => ['id' => (string)$i, 'status' => 200, 'body' => base64_encode("p$i")], range(20, 24))]));
		$out = $this->graph->batch('u', $requests);
		self::assertCount(25, $out);
		self::assertSame(200, $out['3']['status']);
		self::assertSame(404, $out['4']['status']);
		self::assertSame([2.0], $this->graph->waits);
		self::assertCount(20, $this->requests[0][2]['json']['requests']);
		self::assertSame(['3'], array_column($this->requests[1][2]['json']['requests'], 'id'));
		self::assertCount(5, $this->requests[2][2]['json']['requests']);
	}

	public function testBatchLeavesThrottledSubRequestsWhenRetriesAreExhausted(): void {
		$req = [['id' => '0', 'method' => 'GET', 'url' => '/x']];
		for ($i = 0; $i < 4; $i++) {
			$this->queue[] = Http::ok($this, 200, json_encode(['responses' => [['id' => '0', 'status' => 429, 'headers' => [], 'body' => '']]]));
		}
		$out = $this->graph->batch('u', $req);
		self::assertSame(429, $out['0']['status']);
		self::assertCount(4, $this->requests);
		self::assertCount(3, $this->graph->waits);
	}
}
