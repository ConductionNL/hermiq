<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ChatSettingsModal — per-conversation view/tool selection + RAG settings
  (agent-engine-port task 5.1; OR rendered this inline as an NcDialog in
  ChatIndex.vue, hermiq's modal-isolation gate requires an own file).

  Pure edit modal: no API calls. The parent (Chat.vue) owns the settings state
  and passes it in via `value`; every change is emitted back via `input`
  (v-model contract). The settings ride along on POST /api/chat/send —
  the SSE stream endpoint deliberately does not accept them (see Chat.vue's
  ground-truth adaptation note).
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Chat settings')"
		@close="$emit('close')">
		<div class="chat-settings">
			<h2 class="chat-settings__title">
				{{ t('hermiq', 'Chat settings') }}
			</h2>

			<p class="chat-settings__description">
				{{ t('hermiq', 'Control which views and tools the AI may use in this conversation. By default, all agent capabilities are enabled.') }}
			</p>

			<!-- Views -->
			<section v-if="availableViews.length > 0" class="chat-settings__section">
				<h3>{{ t('hermiq', 'Views') }}</h3>
				<p class="chat-settings__hint">
					{{ t('hermiq', 'Select which data views the AI can search') }}
				</p>
				<NcCheckboxRadioSwitch
					v-for="view in availableViews"
					:key="view.uuid"
					:checked="value.views.includes(view.uuid)"
					@update:checked="toggle('views', view.uuid)">
					{{ view.name }}
				</NcCheckboxRadioSwitch>
			</section>

			<!-- Tools -->
			<section v-if="availableTools.length > 0" class="chat-settings__section">
				<h3>{{ t('hermiq', 'Tools') }}</h3>
				<p class="chat-settings__hint">
					{{ t('hermiq', 'Select which tools the AI can use to perform actions') }}
				</p>
				<NcCheckboxRadioSwitch
					v-for="tool in availableTools"
					:key="tool.uuid"
					:checked="value.tools.includes(tool.uuid)"
					@update:checked="toggle('tools', tool.uuid)">
					{{ tool.name }}
				</NcCheckboxRadioSwitch>
			</section>

			<!-- RAG configuration -->
			<section class="chat-settings__section">
				<h3>{{ t('hermiq', 'RAG configuration') }}</h3>
				<p class="chat-settings__hint">
					{{ t('hermiq', 'Configure which data is searched for context and how many sources are retrieved') }}
				</p>
				<NcCheckboxRadioSwitch
					:checked="value.includeObjects"
					@update:checked="set('includeObjects', $event)">
					{{ t('hermiq', 'Search in objects') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked="value.includeFiles"
					@update:checked="set('includeFiles', $event)">
					{{ t('hermiq', 'Search in files') }}
				</NcCheckboxRadioSwitch>

				<div class="chat-settings__row">
					<NcTextField
						:value="String(value.numSourcesObjects)"
						type="number"
						:label="t('hermiq', 'Object sources')"
						@update:value="setNumber('numSourcesObjects', $event)" />
					<NcTextField
						:value="String(value.numSourcesFiles)"
						type="number"
						:label="t('hermiq', 'File sources')"
						@update:value="setNumber('numSourcesFiles', $event)" />
				</div>
				<p class="chat-settings__hint">
					{{ t('hermiq', 'Fewer sources answer faster and more focused; more sources give broader context but slower responses. 5 is a good balance.') }}
				</p>
			</section>

			<div class="chat-settings__actions">
				<NcButton type="primary" @click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcModal, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ChatSettingsModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/** The agent's selectable views ({uuid, name}). */
		availableViews: {
			type: Array,
			default: () => [],
		},
		/** The agent's selectable tools ({uuid, name}). */
		availableTools: {
			type: Array,
			default: () => [],
		},
		/**
		 * The settings state (v-model): {views: string[], tools: string[],
		 * includeObjects, includeFiles, numSourcesObjects, numSourcesFiles}.
		 */
		value: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'input'],

	methods: {
		/**
		 * Emit an updated settings object with one field replaced.
		 *
		 * @param {string} key The settings key.
		 * @param {*} fieldValue The new value.
		 * @return {void}
		 */
		set(key, fieldValue) {
			this.$emit('input', { ...this.value, [key]: fieldValue })
		},

		/**
		 * Emit an updated settings object with a numeric field replaced
		 * (clamped to 1–20, mirroring OR's input bounds).
		 *
		 * @param {string} key The settings key.
		 * @param {string} raw The raw input value.
		 * @return {void}
		 */
		setNumber(key, raw) {
			const parsed = Number(raw)
			if (Number.isNaN(parsed)) {
				return
			}
			this.set(key, Math.min(20, Math.max(1, Math.round(parsed))))
		},

		/**
		 * Toggle a uuid inside one of the selection arrays.
		 *
		 * @param {string} key 'views' or 'tools'.
		 * @param {string} uuid The uuid to toggle.
		 * @return {void}
		 */
		toggle(key, uuid) {
			const current = this.value[key] || []
			const next = current.includes(uuid)
				? current.filter((entry) => entry !== uuid)
				: [...current, uuid]
			this.set(key, next)
		},
	},
}
</script>

<style scoped>
.chat-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	max-height: 70vh;
	overflow-y: auto;
}

.chat-settings__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.chat-settings__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.chat-settings__section {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.chat-settings__section h3 {
	margin: 8px 0 0;
	font-size: 16px;
	font-weight: 600;
}

.chat-settings__hint {
	margin: 0 0 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.chat-settings__row {
	display: flex;
	gap: 12px;
	margin-top: 8px;
}

.chat-settings__row > * {
	flex: 1;
}

.chat-settings__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
