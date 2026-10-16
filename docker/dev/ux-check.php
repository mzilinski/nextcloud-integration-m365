<?php
// Dev check: settings forms render, registration test, AADSTS mapping.
require '/var/www/html/lib/base.php';
\OC_App::loadApps();
\OC_User::setUserId($argv[1] ?? 'admin');
$l = \OC::$server->get(\OCP\L10N\IFactory::class)->get('integration_m365');
foreach ([\OCA\IntegrationM365\Settings\Admin::class, \OCA\IntegrationM365\Settings\Personal::class] as $cls) {
	$resp = \OC::$server->get($cls)->getForm();
	echo basename(str_replace('\\', '/', $cls)), ' form: template ', $resp->getTemplateName(), "\n";
}
$ug = \OC::$server->get(\OCP\IURLGenerator::class);
echo 'section icon: ', $ug->imagePath('integration_m365', 'app-dark.svg'), "\n";
$oauth = \OC::$server->get(\OCA\IntegrationM365\Service\OAuthService::class);
echo 'tenant: ', $oauth->getTenant(), "\n";
echo 'test: ', json_encode($oauth->testRegistration($l), JSON_UNESCAPED_SLASHES), "\n";
foreach ([
	['invalid_client', "AADSTS7000215: Invalid client secret provided. Ensure the secret being sent in the request is the client secret value, not the client secret ID, for a secret added to app '7a8752fb-...'. Trace ID: 0000 Correlation ID: 1111 Timestamp: 2026-10-16 10:00:00Z"],
	['invalid_request', 'AADSTS50011: The redirect URI https://x/y specified in the request does not match the redirect URIs configured for the application. Trace ID: 1'],
	['access_denied', ''],
	['unauthorized_client', "AADSTS700016: Application with identifier '...' was not found in the directory 'Contoso'."],
	['invalid_grant', 'AADSTS50173: The provided grant has expired due to it being revoked. Trace ID: 2'],
	['server_error', 'something new'],
] as [$e, $d]) {
	echo "$e -> ", \OCA\IntegrationM365\Service\EntraErrors::describe($e, $d, $l, 'https://nc/redirect'), "\n";
}
