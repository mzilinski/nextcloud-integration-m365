<?php
// Dev check for LifecycleService: run inside the app container as `php custom_apps/integration_m365/docker/dev/lifecycle-check.php <uid>`.
require '/var/www/html/lib/base.php';
\OC_App::loadApps();
$uid = $argv[1] ?? 'lc-test';
$lc = \OC::$server->get(\OCA\IntegrationM365\Service\LifecycleService::class);
$oauth = \OC::$server->get(\OCA\IntegrationM365\Service\OAuthService::class);
$secrets = \OC::$server->get(\OCA\IntegrationM365\Service\SecretService::class);
$cols = \OC::$server->get(\OCA\IntegrationM365\Db\CollectionMapper::class);
$jobs = \OC::$server->get(\OCP\BackgroundJob\IJobList::class);
$secrets->setEncrypted($uid, 'refresh_token', 'dummy');
$secrets->setPlain($uid, 'user_principal', 'old@example.com');
$jobs->add(\OCA\IntegrationM365\BackgroundJob\UserSyncJob::class, ['user_id' => $uid]);
echo 'connected before: ', var_export($oauth->isConnected($uid), true), "\n";
echo 'invalidated: ', $lc->invalidateAllTokens('test'), "\n";
echo 'connected after: ', var_export($oauth->isConnected($uid), true), ' reauth: ', var_export($oauth->getAccountInfo($uid)['reauthRequired'], true), "\n";
$secrets->setEncrypted($uid, 'refresh_token', 'dummy');
$lc->disconnect($uid, false);
echo 'after disconnect: connected=', var_export($oauth->isConnected($uid), true), ' reauth=', var_export($oauth->getAccountInfo($uid)['reauthRequired'], true), ' job=', var_export($jobs->has(\OCA\IntegrationM365\BackgroundJob\UserSyncJob::class, ['user_id' => $uid]), true), ' pairs=', count($cols->findByUser($uid)), ' enabled=', count($cols->findEnabledByUser($uid)), "\n";
$lc->disconnect($uid, true);
echo 'after purge: pairs=', count($cols->findByUser($uid)), "\n";
echo 'allUsers: ', json_encode($lc->allUsers()), "\n";
