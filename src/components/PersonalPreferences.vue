<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PersonalPreferences — the "Preferences" (Voorkeuren) section of hermiq's user
  settings dialog, as drawn on HmMijnInstellingen: the language agents answer
  in, the agent a new chat starts with, asking before an agent mails or sends
  something on the person's behalf, and a notification when a run is done.

  Each control saves at once through the per-user preferences endpoint and is
  read back when the dialog opens again. Asking before sending only ever adds
  an approval; the server never lets it remove one a tool or an administrator
  requires.

  @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
-->
<template>
	<div class="personal-preferences">
		<p class="personal-preferences__text">
			{{ t('hermiq', 'How agents work for you.') }}
		</p>
		<NcSelect
			class="personal-preferences__select"
			data-testid="pref-answer-language"
			:inputLabel="t('hermiq', 'Language of answers')"
			:options="languageOptions"
			:modelValue="selectedLanguage"
			:loading="loading"
			:disabled="loading"
			label="label"
			:placeholder="t('hermiq', 'As the agent is set up')"
			@update:modelValue="onLanguage" />
		<NcSelect
			class="personal-preferences__select"
			data-testid="pref-default-agent"
			:inputLabel="t('hermiq', 'Default agent in the chat')"
			:options="agentOptions"
			:modelValue="selectedAgent"
			:loading="loading"
			:disabled="loading"
			label="label"
			:placeholder="t('hermiq', 'Choose when starting a chat')"
			@update:modelValue="onDefaultAgent" />
		<NcCheckboxRadioSwitch
			data-testid="pref-ask-before-send"
			type="switch"
			:modelValue="askBeforeSend"
			:disabled="loading"
			@update:modelValue="onSwitch('askBeforeSend', $event)">
			{{ t('hermiq', 'Ask before an agent mails or sends something on my behalf') }}
		</NcCheckboxRadioSwitch>
		<NcCheckboxRadioSwitch
			data-testid="pref-notify-run-done"
			type="switch"
			:modelValue="notifyRunDone"
			:disabled="loading"
			@update:modelValue="onSwitch('notifyRunDone', $event)">
			{{ t('hermiq', 'Notify me when a run is done') }}
		</NcCheckboxRadioSwitch>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { getPreference, PREFERENCE_KEYS, setPreference } from '../api/preferences.js'
import { useAgentStore } from '../store/store.js'
import {
	agentIdOf,
	ANSWER_LANGUAGES,
	switchIsOn,
	switchValue,
} from '../utils/personalPreferences.js'

export default {
	name: 'PersonalPreferences',

	components: {
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			error: '',
			answerLanguage: '',
			defaultAgent: '',
			askBeforeSend: false,
			notifyRunDone: false,
			agents: [],
		}
	},

	computed: {
		/**
		 * The answer languages, translated.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		languageOptions() {
			return ANSWER_LANGUAGES.map((option) => ({
				value: option.value,
				label: this.t('hermiq', option.label),
			}))
		},

		/**
		 * The chosen answer language as an option, or null.
		 *
		 * @return {object|null} The option.
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		selectedLanguage() {
			return this.languageOptions.find((option) => option.value === this.answerLanguage) || null
		},

		/**
		 * The agents the person can start a chat with.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		agentOptions() {
			return this.agents
				.map((agent) => ({ value: agentIdOf(agent), label: agent.name || agentIdOf(agent) }))
				.filter((option) => option.value !== '')
		},

		/**
		 * The default agent as an option, or null.
		 *
		 * @return {object|null} The option.
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		selectedAgent() {
			return this.agentOptions.find((option) => option.value === this.defaultAgent) || null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the four preferences and the agents to choose from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [language, agent, ask, notify] = await Promise.all([
					getPreference(PREFERENCE_KEYS.answerLanguage),
					getPreference(PREFERENCE_KEYS.defaultAgent),
					getPreference(PREFERENCE_KEYS.askBeforeSend),
					getPreference(PREFERENCE_KEYS.notifyRunDone),
				])
				this.answerLanguage = language
				this.defaultAgent = agent
				this.askBeforeSend = switchIsOn(ask)
				this.notifyRunDone = switchIsOn(notify)
			} catch {
				this.error = this.t('hermiq', 'Your preferences could not be loaded.')
			}
			try {
				const store = useAgentStore()
				store.registerObjectType('agent', 'agent', 'hermiq')
				const agents = await store.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
			} catch {
				this.agents = []
			}
			this.loading = false
		},

		/**
		 * Save one preference at once; on failure say so and keep the old value.
		 *
		 * @param {string} key The preference key.
		 * @param {string} value The value to store.
		 * @return {Promise<boolean>} Whether it was saved.
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		async save(key, value) {
			this.error = ''
			try {
				await setPreference(key, value)
				return true
			} catch {
				this.error = this.t('hermiq', 'Your preference could not be saved.')
				return false
			}
		},

		/**
		 * Store the picked answer language.
		 *
		 * @param {object|null} option The picked option.
		 * @return {Promise<void>}
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		async onLanguage(option) {
			const value = option ? option.value : ''
			if (await this.save(PREFERENCE_KEYS.answerLanguage, value)) {
				this.answerLanguage = value
			}
		},

		/**
		 * Store the picked default agent.
		 *
		 * @param {object|null} option The picked option.
		 * @return {Promise<void>}
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		async onDefaultAgent(option) {
			const value = option ? option.value : ''
			if (await this.save(PREFERENCE_KEYS.defaultAgent, value)) {
				this.defaultAgent = value
			}
		},

		/**
		 * Store a switch.
		 *
		 * @param {'askBeforeSend'|'notifyRunDone'} name The switch.
		 * @param {boolean} on Its new state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
		 */
		async onSwitch(name, on) {
			if (await this.save(PREFERENCE_KEYS[name], switchValue(on))) {
				this[name] = on === true
			}
		},
	},
}
</script>

<style scoped>
.personal-preferences {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.personal-preferences__text {
	color: var(--color-text-maxcontrast);
}

.personal-preferences__select {
	max-width: 400px;
}
</style>
