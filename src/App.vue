<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Hermiq app shell. Mounts CnAppRoot with the bundled manifest and the
 registry; provides the `objectSidebarState` channel so detail pages
 (CnDetailPage) can drive the CnObjectSidebar that CnAppRoot auto-mounts.

 Onboarding uses the standard nc-vue system (the same one every other app uses):
 the first-visit product tour is declared in `manifest.walkthrough` and mounted
 automatically by CnAppRoot (which also auto-adds a "Restart walkthrough"
 settings section), and the first-time setup wizard is `manifest.setup` rendered
 by the shared CnSetupWizard — opened on demand from the admin-only "Run setup
 wizard" settings button below. The wrapper uses `display: contents` so CnAppRoot
 still behaves as the direct child of #content.

 @spec openspec/specs/app-manifest/spec.md
 @spec openspec/specs/manifest-driven-pages/spec.md
-->
<template>
	<div class="hermiq-root">
		<CnAppRoot
			:aiCompanion="true"
			:manifest="manifest"
			:customComponents="customComponents"
			:pageTypes="pageTypes"
			:registry="registry"
			:cellWidgets="cellWidgets"
			appId="hermiq"
			:translate="translateForApp"
			:permissions="permissions"
			:requiresApps="[]">
			<!--
			  This app provides `objectSidebarState`, which makes CnAppRoot defer its
			  own CnObjectSidebar auto-mount to us — so this slot MUST render it.

			  The flow editor's sidebar is NOT dispatched here: it is declared as
			  `pages[].sidebarComponent` in the manifest and resolved by CnAppRoot
			  itself (nc-vue #528). This slot only has to handle the object sidebar.
			-->
			<template #sidebar>
				<CnObjectSidebar
					v-if="objectSidebarState.active"
					:title="objectSidebarState.title"
					:subtitle="objectSidebarState.subtitle"
					:objectType="objectSidebarState.objectType"
					:objectId="objectSidebarState.objectId"
					:register="objectSidebarState.register"
					:schema="objectSidebarState.schema"
					:hiddenTabs="objectSidebarState.hiddenTabs"
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
				<NcAppSettingsSection id="about" :name="t('hermiq', 'About Hermiq')">
					<p class="hermiq-settings__text">
						{{
							t(
								'hermiq',
								'Hermiq brings autonomous AI agents to Nextcloud — define an agent, give it tools, and run it on a schedule. Open source, EUPL-1.2, by Conduction.',
							)
						}}
					</p>
					<ul class="hermiq-settings__links">
						<li>
							<a
								href="https://www.conduction.nl/academy"
								target="_blank"
								rel="noopener noreferrer"
								>{{ t('hermiq', 'Documentation') }}</a
							>
						</li>
						<li>
							<a
								href="https://codeberg.org/Conduction/hermiq"
								target="_blank"
								rel="noopener noreferrer"
								>{{ t('hermiq', 'Source code (Codeberg)') }}</a
							>
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
						{{
							t(
								'hermiq',
								'Re-run the first-time setup wizard to point Hermiq at your LLM host and test the connection.',
							)
						}}
					</p>
					<NcButton variant="secondary" @click="showSetup = true">
						{{ t('hermiq', 'Run setup wizard') }}
					</NcButton>
				</NcAppSettingsSection>
				<NcAppSettingsSection
					id="credentials"
					:name="t('hermiq', 'Credentials')">
					<CnCredentials
						scope="personal"
						appId="hermiq"
						:appName="t('hermiq', 'Hermiq')"
						:appCredentials="appCredentials" />
				</NcAppSettingsSection>
			</template>
		</CnAppRoot>

		<CnSetupWizard
			v-if="showSetup"
			appId="hermiq"
			:steps="setupSteps"
			:dialogTitle="t('hermiq', 'Set up Hermiq')"
			@complete="showSetup = false"
			@close="showSetup = false" />
	</div>
</template>

<script>
import {
	CnAppRoot,
	CnCredentials,
	CnObjectSidebar,
	CnSetupWizard,
} from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { NcAppSettingsSection, NcButton } from '@nextcloud/vue'
import { reactive } from 'vue'
import TalkDeliverySettings from './components/TalkDeliverySettings.vue'
// skill-maturity: the SkillsCatalog maturityLevel column's dots badge, resolved
// via CnAppRoot's cellWidgets registry (manifest column `widget: "maturity-dots"`).
import SkillMaturityDots from './widgets/SkillMaturityDots.vue'
// Provider-credential declarations for the CnCredentials settings surface.
// Lives beside the manifest (not inside it): the app-manifest v2 schema has no
// root `credentials` block, and the declaration is consumed only by this shell.
import credentialDeclarations from './credentials.json'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
		CnSetupWizard,
		CnCredentials,
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
			// Vue 3: `reactive()` replaces Vue 2's `Vue.observable()` (removed
			// from the global API; under @vue/compat MODE 3 it hard-errors with
			// "GLOBAL_OBSERVABLE compat has been disabled").
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
			objectSidebarState: reactive({
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
		 * Consumer cell-widget registry for manifest columns declaring a
		 * `widget` id (CnCellRenderer resolves it via the injected
		 * `cnCellWidgets`). Currently only the skill-maturity dots badge.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {object} Map of cell-widget id → component.
		 */
		cellWidgets() {
			return {
				'maturity-dots': SkillMaturityDots,
			}
		},

		/**
		 * @spec exclude declaration passthrough — surfaces the static
		 *   provider-credential declarations (src/credentials.json) to the
		 *   CnCredentials settings surface unchanged. No domain logic; the
		 *   broker semantics are owned by OpenRegister's credential broker.
		 * @return {Array} Provider-credential declaration list.
		 */
		appCredentials() {
			return credentialDeclarations
		},

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
			return (
				(this.manifest && this.manifest.setup && this.manifest.setup.steps)
				|| []
			)
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
