<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  Organisation credentials with the organisation shown and choosable
  (claude-provider-for-every-member, Ruben 2026-10-10). The picker lists what the
  admin may manage, defaulting to their active organisation. OpenRegister re-checks
  every choice, so the picker is a convenience, never the authority.
-->
<template>
	<div class="hermiq-org-credentials">
		<template v-if="options.length">
			<NcSelect
				v-model="selected"
				class="hermiq-org-credentials__picker"
				:options="options"
				:clearable="false"
				:inputLabel="t('hermiq', 'Organisation')" />
			<p class="hermiq-org-credentials__hint">
				{{
					t(
						'hermiq',
						'You see the credentials of {organisation}. New credentials are stored for {organisation}.',
						{ organisation: selected ? selected.label : '' },
					)
				}}
			</p>
			<OrganisationScopedCredentials
				v-if="selected"
				:key="selected.value"
				:organisation="selected.value"
				scope="organisation"
				appId="hermiq"
				:appName="t('hermiq', 'Hermiq')"
				:appCredentials="appCredentials" />
		</template>
		<template v-else-if="!loading">
			<p class="hermiq-org-credentials__hint">
				{{
					t(
						'hermiq',
						'Could not load the organisations you manage. You see the credentials of your active organisation.',
					)
				}}
			</p>
			<CnCredentials
				scope="organisation"
				appId="hermiq"
				:appName="t('hermiq', 'Hermiq')"
				:appCredentials="appCredentials" />
		</template>
	</div>
</template>

<script>
import { CnCredentials } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import {
	defaultOrganisation,
	loadManageableOrganisations,
} from '../../utils/organisationCredentials.js'
import OrganisationScopedCredentials from './OrganisationScopedCredentials.js'

export default {
	name: 'OrganisationCredentialSettings',
	components: { CnCredentials, NcSelect, OrganisationScopedCredentials },

	props: {
		/**
		 * The app manifest's `credentials[]`, shown read-only by CnCredentials.
		 *
		 * @type {Array<object>}
		 */
		appCredentials: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			loading: true,
			options: [],
			selected: null,
		}
	},

	/**
	 * Load the organisations the admin may manage and preselect the active one.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec openspec/changes/claude-provider-for-every-member/specs/claude-provider-for-every-member/spec.md#requirement-the-organisation-credential-form-shows-and-chooses-its-organisation
	 */
	async mounted() {
		this.options = await loadManageableOrganisations(
			(url) => axios.get(url),
			generateUrl,
		)
		this.selected = defaultOrganisation(this.options)
		this.loading = false
	},
}
</script>

<style scoped>
.hermiq-org-credentials__picker {
	max-width: 400px;
}

.hermiq-org-credentials__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px;
}
</style>
