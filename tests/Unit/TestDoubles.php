<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Tests\Unit;

use OCA\IntegrationM365\Service\GraphClient;
use OCA\IntegrationM365\Service\SecretService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** In-memory SecretService: the real one needs IUserConfig and ICrypto. */
class FakeSecretService extends SecretService {
	/** @var array<string,array<string,string>> */
	public array $data = [];

	public function __construct() {
	}

	public function setEncrypted(string $userId, string $key, string $value): void {
		if ($value === '') {
			unset($this->data[$userId][$key]);
			return;
		}
		$this->data[$userId][$key] = 'enc:' . $value;
	}

	public function getEncrypted(string $userId, string $key): string {
		$v = $this->data[$userId][$key] ?? '';
		return str_starts_with($v, 'enc:') ? substr($v, 4) : '';
	}

	public function setPlain(string $userId, string $key, string $value): void {
		$this->data[$userId][$key] = $value;
	}

	public function getPlain(string $userId, string $key, string $default = ''): string {
		return $this->data[$userId][$key] ?? $default;
	}

	public function setInt(string $userId, string $key, int $value): void {
		$this->data[$userId][$key] = (string)$value;
	}

	public function getInt(string $userId, string $key, int $default = 0): int {
		return isset($this->data[$userId][$key]) ? (int)$this->data[$userId][$key] : $default;
	}

	public function delete(string $userId, string $key): void {
		unset($this->data[$userId][$key]);
	}
}

/** What OCP's HTTP client throws on 4xx/5xx: an exception carrying a PSR-7 response. */
class HttpErrorException extends \RuntimeException {
	public function __construct(
		private ResponseInterface $response,
		string $message = 'HTTP error',
	) {
		parent::__construct($message);
	}

	public function getResponse(): ResponseInterface {
		return $this->response;
	}
}

/** GraphClient that records instead of sleeping. */
class RecordingGraphClient extends GraphClient {
	/** @var list<float> */
	public array $waits = [];

	protected function pause(float $seconds): void {
		$this->waits[] = $seconds;
	}
}

/** Builders for HTTP responses as the OCP client and PSR-7 deliver them. */
final class Http {
	/** @param array<string,string> $headers */
	public static function ok(TestCase $tc, int $status, string $body, array $headers = []): IResponse {
		$r = $tc->getMockBuilder(IResponse::class)->getMock();
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn($body);
		$r->method('getHeaders')->willReturn(array_map(static fn ($v) => [$v], $headers));
		return $r;
	}

	/** @param array<string,string> $headers */
	public static function error(TestCase $tc, int $status, string $body, array $headers = []): HttpErrorException {
		$stream = $tc->getMockBuilder(StreamInterface::class)->getMock();
		$stream->method('__toString')->willReturn($body);
		$r = $tc->getMockBuilder(ResponseInterface::class)->getMock();
		$r->method('getStatusCode')->willReturn($status);
		$r->method('getBody')->willReturn($stream);
		$r->method('getHeaders')->willReturn(array_map(static fn ($v) => [$v], $headers));
		return new HttpErrorException($r, "HTTP $status");
	}
}
