<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Nextcloud admin app-settings panel.

 Mounted into `#hermiq-settings` by `src/settings.js` (which
 itself is loaded from `templates/settings/admin.php` via
 `Util::addScript`). This is the panel users reach via Nextcloud's
 "Administration settings" → "Hermiq".

 In a manifest-driven app this surface is mostly redundant — the
 SPA's `type: "settings"` page (declared in `src/manifest.json`)
 covers admin/user settings inside the app's own UI. The Nextcloud
 admin panel below stays as a placeholder for "before the app
 boots" wiring (e.g. choosing the OpenRegister register before the
 manifest renders).
-->
<template>
	<div class="hermiq-admin-settings">
		<NcSettingsSection
			:name="t('hermiq', 'Hermiq')"
			:description="t('hermiq', 'Pre-app-boot configuration. Most settings live inside the app at /settings (manifest-driven).')">
			<p class="hermiq-admin-settings__hint">
				{{ t('hermiq', 'No pre-boot settings yet. Edit `src/views/AdminRoot.vue` to add fields here.') }}
			</p>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'

export default {
	name: 'AdminRoot',
	components: {
		NcSettingsSection,
	},
}
</script>

<style scoped>
.hermiq-admin-settings {
	max-width: 720px;
}

.hermiq-admin-settings__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}
</style>
