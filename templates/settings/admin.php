<?php
// SPDX-License-Identifier: EUPL-1.2

use OCA\Hermiq\AppInfo\Application;
use OCP\Util;

$appId = Application::APP_ID;

// Inject the app version via Nextcloud's IInitialState API so the Vue settings
// app can read it with loadState('hermiq', 'version') — the NC-standard
// CSP-compliant approach (replaces the prior data-version DOM attribute).
\OC::$server->get(\OCP\IInitialStateService::class)
    ->provideInitialState($appId, 'version', $_['version'] ?? '');

// webpack splitChunks emits shared chunks that every entry-point depends on
// (see comment in templates/index.php). The admin-settings entry's bundle
// tail also wraps its mount in `__webpack_require__.O(...)` waiting for the
// shared chunks, so register them in dependency order here too.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="hermiq-settings"></div>
