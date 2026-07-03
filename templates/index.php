<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Hermiq\AppInfo\Application::APP_ID;

// webpack splitChunks (see webpack.config.js) emits two shared chunks
// (`shared-vendor`, `shared-nc-vue`) via `enforce: true` cacheGroups. The
// main entry's bundle tail wraps the Vue mount in
// `__webpack_require__.O(0, [shared chunks], …)` which only fires once every
// listed chunk has registered itself on `self.webpackChunk<appId>`. If we
// only `addScript` the main entry, the shared chunks never load, the
// callback never fires, and the Vue app silently fails to mount inside the
// `#content` wrapper. Register every chunk produced by splitChunks here,
// in dependency order, before the main entry. (Mirrors zaakafhandelapp#206.)
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>
