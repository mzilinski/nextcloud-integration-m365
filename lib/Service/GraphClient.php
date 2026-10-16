<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\IntegrationM365\Service;

use OCA\IntegrationM365\AppInfo\Application;
use OCA\IntegrationM365\Exception\DeltaExpiredException;
use OCA\IntegrationM365\Exception\GraphException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin Microsoft Graph HTTP client: bearer auth, one refresh-and-retry on 401,
 * Retry-After back-off on 429/5xx, OData paging, delta links and $batch.
 */
class GraphClient {
	public const BASE = 'https://graph.microsoft.com/v1.0';
	/** The only host a bearer token may be sent to; nextLink/deltaLink come from the API and are checked too. */
	public const HOST = 'graph.microsoft.com';
	private const MAX_RETRIES = 3;
	/** A Retry-After beyond this is not waited for: the pair fails and the next run (5 min) tries again. */
	private const MAX_RETRY_SLEEP = 20;
	/** Statuses retried for every method (Graph says so) ... */
	private const RETRY_ALWAYS = [429, 503, 504];
	/** ... and those retried only when the request can be repeated without side effects. */
	private const RETRY_IDEMPOTENT = [500, 502];
	private const IDEMPOTENT = ['GET', 'PUT', 'DELETE', 'PATCH'];

	public function __construct(
		private IClientService $clientService,
		private OAuthService $oauth,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int, headers:array<string,string>, body:string}
	 * @throws GraphException
	 */
	public function request(string $userId, string $method, string $pathOrUrl, ?array $json = null, array $headers = [], #[\SensitiveParameter] ?string $rawBody = null, ?string $contentType = null): array {
		$url = self::absoluteUrl($pathOrUrl);
		$client = $this->clientService->newClient();
		$retries = 0;
		$refreshed = false;
		$idempotent = in_array(strtoupper($method), self::IDEMPOTENT, true);
		while (true) {
			$options = [
				'headers' => array_merge([
					'Authorization' => 'Bearer ' . $this->oauth->getAccessToken($userId, $refreshed),
					'Accept' => 'application/json',
				], $headers),
				'timeout' => 60,
				'connect_timeout' => 10,
			];
			$refreshed = false;
			if ($json !== null) {
				$options['json'] = $json;
			} elseif ($rawBody !== null) {
				$options['body'] = $rawBody;
				$options['headers']['Content-Type'] = $contentType ?? 'application/octet-stream';
			}
			try {
				$response = $client->request($method, $url, $options);
			} catch (\Exception $e) {
				// OCP's client wraps Guzzle: on 4xx/5xx the exception carries a PSR-7 response, not an IResponse
				if (!method_exists($e, 'getResponse') || $e->getResponse() === null) {
					if ($idempotent && $retries < self::MAX_RETRIES) {
						$this->backOff(0, null, $retries++, 'network error: ' . $e->getMessage());
						continue;
					}
					throw new GraphException($e->getMessage(), 0, 'network', $e);
				}
				$response = $e->getResponse();
			}
			/** @var IResponse|ResponseInterface $response */
			$status = $response->getStatusCode();
			$hdrs = $this->headersOf($response);
			$retryable = in_array($status, self::RETRY_ALWAYS, true) || ($idempotent && in_array($status, self::RETRY_IDEMPOTENT, true));
			if ($retryable && $retries < self::MAX_RETRIES) {
				$this->backOff($status, $hdrs['retry-after'] ?? null, $retries++, null);
				continue;
			}
			if ($status === 401 && $retries === 0) {
				$retries++;
				$refreshed = true;
				continue;
			}
			$bodyStr = (string)$response->getBody();
			if ($status >= 400) {
				throw $this->errorFromBody($bodyStr, $status);
			}
			return ['status' => $status, 'headers' => $hdrs, 'body' => $bodyStr];
		}
	}

	/**
	 * Absolute Graph URL for a path, or the URL itself when it already points at Graph.
	 *
	 * @throws GraphException for any other host: nextLink/deltaLink are stored and replayed,
	 *                        a bearer token must never follow them elsewhere
	 */
	public static function absoluteUrl(string $pathOrUrl): string {
		if (!str_starts_with($pathOrUrl, 'http')) {
			return self::BASE . $pathOrUrl;
		}
		$parts = parse_url($pathOrUrl);
		if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== self::HOST || isset($parts['user']) || isset($parts['port'])) {
			throw new GraphException('refusing to send a Graph token to ' . ($parts['host'] ?? $pathOrUrl), 0, 'ssrf');
		}
		return $pathOrUrl;
	}

	/**
	 * Wait before a retry: Retry-After (seconds or HTTP date) when given, otherwise
	 * exponential with full jitter so that many users of one tenant do not retry in step.
	 *
	 * @throws GraphException when Graph asks for a longer pause than one run should spend
	 */
	private function backOff(int $status, ?string $retryAfter, int $attempt, ?string $reason): void {
		$wait = self::retryAfterSeconds($retryAfter);
		if ($wait === null) {
			$wait = (float)(1 << $attempt) * (0.5 + 0.5 * (float)mt_rand() / (float)mt_getrandmax()); // 0.5..1, 1..2, 2..4 s
		}
		if ($wait > self::MAX_RETRY_SLEEP) {
			throw new GraphException("Graph asks to retry after {$wait} s", $status, 'throttled');
		}
		$this->logger->info('Graph request failed, retrying', ['app' => Application::APP_ID, 'status' => $status, 'wait' => round($wait, 1), 'reason' => $reason]);
		$this->pause($wait);
	}

	/** @return float|null seconds, null when the header is absent or unparseable */
	public static function retryAfterSeconds(?string $header, ?int $now = null): ?float {
		if ($header === null || trim($header) === '') {
			return null;
		}
		if (is_numeric(trim($header))) {
			return max(0.0, (float)trim($header));
		}
		$ts = strtotime($header);
		return $ts === false ? null : max(0.0, (float)$ts - (float)($now ?? time()));
	}

	/** Separate so that tests can skip the real wait. */
	protected function pause(float $seconds): void {
		usleep((int)($seconds * 1000000.0));
	}

	/** @throws GraphException */
	public function get(string $userId, string $pathOrUrl, array $headers = []): array {
		$r = $this->request($userId, 'GET', $pathOrUrl, null, $headers);
		return $r['body'] === '' ? [] : (json_decode($r['body'], true) ?: []);
	}

	/** @return array{0:string,1:string} body bytes and content type */
	public function getRaw(string $userId, string $path): array {
		$r = $this->request($userId, 'GET', $path, null, ['Accept' => '*/*']);
		return [$r['body'], $r['headers']['content-type'] ?? 'application/octet-stream'];
	}

	public function post(string $userId, string $path, array $json, array $headers = []): array {
		$r = $this->request($userId, 'POST', $path, $json, $headers);
		return $r['body'] === '' ? [] : (json_decode($r['body'], true) ?: []);
	}

	public function patch(string $userId, string $path, array $json, array $headers = []): array {
		$r = $this->request($userId, 'PATCH', $path, $json, $headers);
		return $r['body'] === '' ? [] : (json_decode($r['body'], true) ?: []);
	}

	public function putRaw(string $userId, string $path, string $bytes, string $contentType): void {
		$this->request($userId, 'PUT', $path, null, [], $bytes, $contentType);
	}

	public function delete(string $userId, string $path): void {
		$this->request($userId, 'DELETE', $path);
	}

	/** GET with transparent @odata.nextLink paging. */
	public function listAll(string $userId, string $path, array $headers = []): array {
		$items = [];
		$url = $path;
		while ($url !== null) {
			$page = $this->get($userId, $url, $headers);
			$items = array_merge($items, $page['value'] ?? []);
			$url = $page['@odata.nextLink'] ?? null;
		}
		return $items;
	}

	/**
	 * Follow a delta query to its end.
	 *
	 * @return array{items: array, deltaLink: ?string}
	 * @throws DeltaExpiredException when a stored delta link is no longer accepted
	 */
	public function delta(string $userId, string $url, int $pageSize = 50): array {
		$headers = ['Prefer' => sprintf('odata.maxpagesize=%d, outlook.timezone="UTC", outlook.body-content-type="text"', $pageSize)];
		$items = [];
		$deltaLink = null;
		$next = $url;
		while ($next !== null) {
			try {
				$page = $this->get($userId, $next, $headers);
			} catch (GraphException $e) {
				if ($e->httpStatus === 410 || $e->graphCode === 'ErrorInvalidSyncStateData' || $e->graphCode === 'syncStateNotFound') {
					throw new DeltaExpiredException($e->getMessage(), $e->httpStatus, $e->graphCode, $e);
				}
				throw $e;
			}
			$items = array_merge($items, $page['value'] ?? []);
			$deltaLink = $page['@odata.deltaLink'] ?? $deltaLink;
			$next = $page['@odata.nextLink'] ?? null;
		}
		return ['items' => $items, 'deltaLink' => $deltaLink];
	}

	/**
	 * POST /$batch in chunks of 20; returns the flat list of sub-responses keyed by request id.
	 * Sub-requests throttled individually (429/503 inside a 200 batch) are retried; whatever
	 * still fails is returned with its error status so the caller can tell "no photo" from "unknown".
	 *
	 * @return array<string,array> sub-responses by request id
	 */
	public function batch(string $userId, array $requests): array {
		$responses = [];
		foreach (array_chunk($requests, 20) as $chunk) {
			/** @var list<array> $pending */
			$pending = $chunk;
			for ($attempt = 0; ; $attempt++) {
				$result = $this->post($userId, '/$batch', ['requests' => $pending]);
				$retry = [];
				$maxWait = null;
				foreach ($result['responses'] ?? [] as $resp) {
					$id = (string)($resp['id'] ?? '');
					$responses[$id] = $resp;
					if (in_array((int)($resp['status'] ?? 0), self::RETRY_ALWAYS, true)) {
						$retry[$id] = true;
						$wait = self::retryAfterSeconds(self::headerOf($resp['headers'] ?? [], 'retry-after'));
						$maxWait = max($maxWait ?? 0.0, $wait ?? 0.0);
					}
				}
				$pending = array_values(array_filter($pending, static fn (array $req) => isset($retry[(string)$req['id']])));
				if ($pending === [] || $attempt >= self::MAX_RETRIES) {
					break;
				}
				try {
					$this->backOff(429, $maxWait !== null && $maxWait > 0 ? (string)$maxWait : null, $attempt, 'batch sub-requests throttled');
				} catch (GraphException) {
					break; // too long to wait inside this run: leave the 429 responses in place
				}
			}
		}
		return $responses;
	}

	/** @param array<string,mixed> $headers */
	private static function headerOf(array $headers, string $name): ?string {
		foreach ($headers as $k => $v) {
			if (strtolower((string)$k) === $name) {
				return is_array($v) ? (string)($v[0] ?? '') : (string)$v;
			}
		}
		return null;
	}

	private function errorFromBody(string $bodyStr, int $status): GraphException {
		$body = json_decode($bodyStr, true);
		$err = is_array($body) ? ($body['error'] ?? []) : [];
		$message = (string)($err['message'] ?? ('HTTP ' . $status));
		return new GraphException($message, $status, (string)($err['code'] ?? ''));
	}

	/**
	 * @param IResponse|ResponseInterface $response
	 * @return array<string,string> lower-cased header names
	 */
	private function headersOf(object $response): array {
		$out = [];
		foreach ($response->getHeaders() as $name => $values) {
			$out[strtolower((string)$name)] = is_array($values) ? (string)($values[0] ?? '') : (string)$values;
		}
		return $out;
	}
}
