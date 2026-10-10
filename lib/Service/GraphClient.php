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
use Psr\Log\LoggerInterface;

/**
 * Thin Microsoft Graph HTTP client: bearer auth, one refresh-and-retry on 401,
 * Retry-After back-off on 429/503, OData paging, delta links and $batch.
 */
class GraphClient {
	public const BASE = 'https://graph.microsoft.com/v1.0';
	private const MAX_RETRIES = 3;
	private const MAX_RETRY_SLEEP = 20;

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
	public function request(string $userId, string $method, string $pathOrUrl, ?array $json = null, array $headers = [], ?string $rawBody = null, ?string $contentType = null): array {
		$url = str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : self::BASE . $pathOrUrl;
		$client = $this->clientService->newClient();
		$retries = 0;
		$refreshed = false;
		while (true) {
			$options = [
				'headers' => array_merge([
					'Authorization' => 'Bearer ' . $this->oauth->getAccessToken($userId, $refreshed),
					'Accept' => 'application/json',
				], $headers),
				'timeout' => 60,
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
				$status = $response->getStatusCode();
			} catch (\Exception $e) {
				if (!method_exists($e, 'getResponse') || $e->getResponse() === null) {
					throw new GraphException($e->getMessage(), 0, 'network', $e);
				}
				$response = $e->getResponse();
				$status = $response->getStatusCode();
			}
			if (($status === 429 || $status === 503) && $retries < self::MAX_RETRIES) {
				$wait = (int)($response->getHeader('Retry-After') ?: (1 << $retries));
				$retries++;
				$this->logger->info('Graph throttled, retrying', ['app' => Application::APP_ID, 'status' => $status, 'wait' => $wait]);
				sleep(min($wait, self::MAX_RETRY_SLEEP));
				continue;
			}
			if ($status === 401 && $retries === 0) {
				$retries++;
				$refreshed = true;
				continue;
			}
			if ($status >= 400) {
				throw $this->errorFromResponse($response, $status);
			}
			return ['status' => $status, 'headers' => $this->headersOf($response), 'body' => (string)$response->getBody()];
		}
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

	/** POST /$batch in chunks of 20; returns the flat list of sub-responses. */
	public function batch(string $userId, array $requests): array {
		$responses = [];
		foreach (array_chunk($requests, 20) as $chunk) {
			$result = $this->post($userId, '/$batch', ['requests' => $chunk]);
			$responses = array_merge($responses, $result['responses'] ?? []);
		}
		return $responses;
	}

	private function errorFromResponse(IResponse $response, int $status): GraphException {
		$body = json_decode((string)$response->getBody(), true);
		$err = is_array($body) ? ($body['error'] ?? []) : [];
		$message = (string)($err['message'] ?? ('HTTP ' . $status));
		return new GraphException($message, $status, (string)($err['code'] ?? ''));
	}

	/** @return array<string,string> lower-cased header names */
	private function headersOf(IResponse $response): array {
		$out = [];
		foreach ($response->getHeaders() as $name => $values) {
			$out[strtolower((string)$name)] = is_array($values) ? (string)($values[0] ?? '') : (string)$values;
		}
		return $out;
	}
}
