<?php
// Dev check: atomic pair lock, user rotation order, Graph host allow-list.
require '/var/www/html/lib/base.php';
\OC_App::loadApps();
$cols = \OC::$server->get(\OCA\IntegrationM365\Db\CollectionMapper::class);
$pairs = $cols->findByUser($argv[1] ?? 'mzili');
$p = $pairs[0];
$id = (int)$p->getId();
$now = time();
echo 'first lock: ', var_export($cols->tryLock($id, $now, $now + 900), true), "\n";
echo 'second lock: ', var_export($cols->tryLock($id, $now, $now + 900), true), "\n";
echo 'expired lock: ', var_export($cols->tryLock($id, $now + 1000, $now + 1900), true), "\n";
$p = $cols->find($id); $p->setLockedUntil(null); $cols->update($p);
echo 'order: ', json_encode($cols->findUsersWithEnabledPairs()), "\n";
foreach (['/me/calendars', 'https://graph.microsoft.com/v1.0/me?x=1', 'https://evil.example/graph', 'http://graph.microsoft.com/v1.0/me', 'https://graph.microsoft.com:8443/x', 'https://a@graph.microsoft.com/x'] as $u) {
	try { echo "$u -> ", \OCA\IntegrationM365\Service\GraphClient::absoluteUrl($u), "\n"; } catch (\Throwable $e) { echo "$u -> REFUSED (", $e->getMessage(), ")\n"; }
}
foreach (['120', 'Thu, 01 Jan 2099 00:00:00 GMT', 'garbage', null, ''] as $h) {
	echo json_encode($h), ' -> ', json_encode(\OCA\IntegrationM365\Service\GraphClient::retryAfterSeconds($h)), "\n";
}
