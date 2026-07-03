<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Example custom component for the manifest-driven app shell.

 This component exists to demonstrate the `customComponents` registry
 contract. It is NOT referenced by the default `src/manifest.json` —
 wire it up by adding a `type: "custom"` page entry:

   {
     "id": "Example",
     "route": "/example",
     "type": "custom",
     "component": "CustomExample",
     "title": "Example"
   }

 Resolution flow:
   - CnPageRenderer dispatches by `page.type`. For `"custom"`, it
     looks up `page.component` in the customComponents registry
     (this app's `src/customComponents.js`).
   - This component then renders inside the CnAppRoot shell, with
     the host-rendered #sidebar slot still available.

 Use `type: "custom"` ONLY when none of the built-in page types
 (`index`, `detail`, `dashboard`, `logs`, `settings`, `chat`, `files`)
 can express the page's behaviour. The vast majority of CRUD pages
 in a Conduction app are NOT customs.
-->
<template>
	<div class="custom-example">
		<CnNoteCard type="info">
			<h2>{{ t('hermiq', 'Custom example component') }}</h2>
			<p>
				{{ t('hermiq', 'This is the canonical example of a custom-type page in the manifest renderer pattern. It is rendered when a manifest page declares `type: "custom"` and `component: "CustomExample"`.') }}
			</p>
			<p>
				{{ t('hermiq', 'Replace the body of this component with whatever bespoke behaviour your page needs — realtime UI, vendor integrations, anything that does not fit the built-in page types.') }}
			</p>
		</CnNoteCard>
	</div>
</template>

<script>
import { CnNoteCard } from '@conduction/nextcloud-vue'

export default {
	name: 'CustomExample',
	components: {
		CnNoteCard,
	},
}
</script>

<style scoped>
.custom-example {
	padding: 16px;
	max-width: 720px;
	margin: 0 auto;
}

.custom-example h2 {
	margin: 0 0 8px;
	font-size: 18px;
	font-weight: 600;
}

.custom-example p {
	margin: 0 0 12px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
