<?php
// Dev helper: php docker/dev/graph.php <user> <METHOD> <path> [json]
// Runs inside the container: occ-less Graph call with the user's stored token.
require_once '/var/www/html/lib/base.php';
[$_, $user, $method, $path] = $argv;
$json = isset($argv[4]) ? json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR) : null;
$client = \OCP\Server::get(\OCA\IntegrationM365\Service\GraphClient::class);
try {
	if ($method === 'LIST') {
		$out = $client->listAll($user, $path);
	} else {
		$r = $client->request($user, $method, $path, $json); $out = json_decode((string)($r["body"] ?? ""), true) ?? $r;
	}
	echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} catch (\Throwable $e) {
	fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
	exit(1);
}
