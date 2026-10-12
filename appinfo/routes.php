<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Malte Zilinski <malte@zilinski.eu>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#getOauthUrl', 'url' => '/oauth-url', 'verb' => 'GET'],
		['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
		['name' => 'config#disconnect', 'url' => '/disconnect', 'verb' => 'POST'],
		['name' => 'config#syncNow', 'url' => '/sync-now', 'verb' => 'POST'],
		['name' => 'config#syncStatus', 'url' => '/sync-status', 'verb' => 'GET'],
		['name' => 'collections#index', 'url' => '/collections', 'verb' => 'GET'],
		['name' => 'collections#update', 'url' => '/collections/{type}', 'verb' => 'PUT'],
	],
];
