<!--
  SPDX-FileCopyrightText: 2024 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  Minimal dashboard widget. Replace the body with your own content. The
  important parts to keep are:
    - <NcDashboardWidget> wrapper (handles loading + empty states)
    - axios fetch in mounted() (or pull from a Pinia store)
    - error handling that downgrades gracefully — a widget that throws
      breaks the whole dashboard mount
-->
<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:empty-content-message="emptyMessage">
		<template #empty-content>
			<NcEmptyContent :title="emptyMessage">
				<template #icon>
					<InfoOutline />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import NcDashboardWidget from '@nextcloud/vue/dist/Components/NcDashboardWidget.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import InfoOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'ExampleWidget',
	components: { NcDashboardWidget, NcEmptyContent, InfoOutline },
	props: {
		title: { type: String, default: '' },
	},
	data: () => ({
		items: [],
		loading: true,
		emptyMessage: '',
	}),
	/**
	 * Load widget rows on mount; degrade to an empty state on failure.
	 *
	 * @spec openspec/specs/scaffold-components/spec.md#REQ-COMP-003
	 * @return {Promise<void>}
	 */
	async mounted() {
		this.emptyMessage = t('hermiq', 'No data yet')
		try {
			// Replace this with your own data source. The pattern here —
			// fetch from /api/<your-resource> via @nextcloud/axios — is
			// what you'd use for OpenRegister-driven widgets too.
			const url = generateUrl('/apps/hermiq/api/items')
			const { data } = await axios.get(url, { params: { limit: 7 } })
			this.items = (data?.results || []).map(o => ({
				id: o.id,
				mainText: o.title || o.name || `#${o.id}`,
			}))
		} catch (err) {
			console.warn('[ExampleWidget] fetch failed — empty state', err)
			this.items = []
		} finally {
			this.loading = false
		}
	},
}
</script>
