<?php
// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later
$appId = OCA\IntegrationM365\AppInfo\Application::APP_ID;
\OCP\Util::addScript($appId, $appId . '-personalSettings');
?>
<div id="integration_m365_personal"></div>
