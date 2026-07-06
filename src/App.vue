<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Hermiq app shell. Mounts CnAppRoot with the bundled manifest and the
 registry; provides the `objectSidebarState` channel so detail pages
 (CnDetailPage) can drive a single host-rendered CnObjectSidebar through
 the #sidebar slot.

 Onboarding uses the standard nc-vue system (the same one every other app uses):
 the first-visit product tour is declared in `manifest.walkthrough` and mounted
 automatically by CnAppRoot (which also auto-adds a "Restart walkthrough"
 settings section), and the first-time setup wizard is `manifest.setup` rendered
 by the shared CnSetupWizard — opened on demand from the admin-only "Run setup
 wizard" settings button below. The wrapper uses `display: contents` so CnAppRoot
 still behaves as the direct child of #content.

 @spec openspec/changes/template-manifest-v1/specs/template-manifest-v1/spec.md
 @spec openspec/changes/scaffold-v2/specs/scaffold-v2/spec.md
-->
<template>
	<div class="hermiq-root">
		<CnAppRoot
			:manifest="manifest"
			:custom-components="customComponents"
			:page-types="pageTypes"
			:registry="registry"
			app-id="hermiq"
			:translate="translateForApp"
			:permissions="permissions"
			:requires-apps="[]">
			<template #sidebar>
				<CnObjectSidebar
					v-if="objectSidebarState.active"
					:title="objectSidebarState.title"
					:subtitle="objectSidebarState.subtitle"
					:object-type="objectSidebarState.objectType"
					:object-id="objectSidebarState.objectId"
					:register="objectSidebarState.register"
					:schema="objectSidebarState.schema"
					:hidden-tabs="objectSidebarState.hiddenTabs"
					:tabs="objectSidebarState.tabs"
					:open="objectSidebarState.open"
					@update:open="objectSidebarState.open = $event" />
			</template>
			<!--
			  user-settings slot: NcAppSettingsSection children rendered inside
			  CnAppRoot's hosted NcAppSettingsDialog. CnAppNav opens it when the
			  user clicks the manifest menu entry with action: "user-settings".
			-->
			<template #user-settings>
				<NcAppSettingsSection
					id="about"
					:name="t('hermiq', 'About Hermiq')">
					<p class="hermiq-settings__text">
						{{ t('hermiq', 'Hermiq brings autonomous AI agents to Nextcloud — define an agent, give it tools, and run it on a schedule. Open source, EUPL-1.2, by Conduction.') }}
					</p>
					<ul class="hermiq-settings__links">
						<li>
							<a href="https://www.conduction.nl/academy" target="_blank" rel="noopener noreferrer">{{ t('hermiq', 'Documentation') }}</a>
						</li>
						<li>
							<a href="https://codeberg.org/Conduction/hermiq" target="_blank" rel="noopener noreferrer">{{ t('hermiq', 'Source code (Codeberg)') }}</a>
						</li>
					</ul>
				</NcAppSettingsSection>
				<NcAppSettingsSection
					id="talk-delivery"
					:name="t('hermiq', 'Talk delivery')">
					<TalkDeliverySettings />
				</NcAppSettingsSection>
				<NcAppSettingsSection
					v-if="isAdmin"
					id="setup"
					:name="t('hermiq', 'Setup')">
					<p class="hermiq-settings__text">
						{{ t('hermiq', 'Re-run the first-time setup wizard to point Hermiq at your LLM host and test the connection.') }}
					</p>
					<NcButton type="secondary" @click="showSetup = true">
						{{ t('hermiq', 'Run setup wizard') }}
					</NcButton>
				</NcAppSettingsSection>
			</template>
		</CnAppRoot>

		<CnSetupWizard
			v-if="showSetup"
			app-id="hermiq"
			:steps="setupSteps"
			:dialog-title="t('hermiq', 'Set up Hermiq')"
			@complete="showSetup = false"
			@close="showSetup = false" />
	</div>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { NcAppSettingsSection, NcButton } from '@nextcloud/vue'
import { CnAppRoot, CnObjectSidebar, CnSetupWizard } from '@conduction/nextcloud-vue'
import TalkDeliverySettings from './components/TalkDeliverySettings.vue'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
		CnSetupWizard,
		NcAppSettingsSection,
		NcButton,
		TalkDeliverySettings,
	},

	/**
	 * @spec exclude provide/inject wiring — exposes the reactive
	 *   objectSidebarState channel to descendants (CnDetailPage). Pure
	 *   framework plumbing with no domain behaviour of its own; the behaviour
	 *   that uses this channel lives in the consumers. This is the canonical
	 *   example of a legitimately-excluded provide() for template-derived apps.
	 */
	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// Vue.observable makes the plain object reactive for Vue 2.
			objectSidebarState: this.objectSidebarState,
		}
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Registry of consumer-injected components used by:
		 *   - `type: "custom"` pages (`page.component`)
		 *   - `headerComponent` / `actionsComponent` slot overrides
		 *   - `pages[].config.sidebarTabs[].component` (detail tab tabs)
		 *   - `pages[].config.sections[].component` (settings rich sections)
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances via
		 * provide/inject.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
		/**
		 * v2 five-kind component registry — `{ "<key>": { kind, component, ...metadata } }`.
		 * Introduced by hydra ADR-036; passed through to CnAppRoot which provides
		 * it via `cnRegistry` for v2 manifest widget resolution.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			showSetup: false,
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
				tabs: undefined,
			}),
		}
	},

	computed: {
		/**
		 * @spec exclude framework passthrough — surfaces the current user's
		 *   Nextcloud permission list (window.OC.currentUser.permissions) to
		 *   CnAppRoot unchanged. No domain logic; the permission semantics are
		 *   owned by the Nextcloud session and by CnAppRoot's consumers.
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},

		/**
		 * @spec exclude framework passthrough — the `manifest.setup.steps` array
		 *   handed straight to CnSetupWizard. No domain logic; the wizard owns
		 *   the setup contract.
		 */
		setupSteps() {
			return (this.manifest && this.manifest.setup && this.manifest.setup.steps) || []
		},

		/**
		 * @spec exclude framework passthrough — whether the current Nextcloud
		 *   user is an admin. The setup wizard writes app-config via admin-scoped
		 *   endpoints, so only admins see the "Run setup wizard" entry.
		 */
		isAdmin() {
			return window.OC?.isUserAdmin?.() === true
		},
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav / CnPageRenderer.
		 * Closes over the Nextcloud `translate` import so the lib never has to
		 * know our app id.
		 *
		 * @spec exclude i18n wrapper — binds the Nextcloud `translate` import
		 *   to this app's id so the shared lib stays app-agnostic.
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('hermiq', key)
		},
	},
}
</script>

<style scoped>
/* display: contents so this wrapper adds no box — CnAppRoot still fills #content. */
.hermiq-root {
	display: contents;
}

.hermiq-settings__text {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.hermiq-settings__links {
	list-style: none;
	margin: 0 0 8px;
	padding: 0;
}

.hermiq-settings__links a {
	color: var(--color-primary-element, var(--color-primary));
	text-decoration: underline;
}
</style>
