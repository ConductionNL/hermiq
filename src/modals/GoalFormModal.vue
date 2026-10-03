<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  GoalFormModal: give the agent in this session a standing goal
  (agents-standing-goal). The agent keeps taking a turn in the session until the
  check says the goal is reached, someone stops it, or the turn limit is used.

  Saves via POST /apps/hermiq/api/sessions/{uuid}/goal and emits `saved` with
  the stored goal.
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Set a goal')"
		@close="$emit('close')">
		<div class="goal-form" data-testid="goal-form">
			<h2 class="goal-form__title">
				{{ t('hermiq', 'Set a goal') }}
			</h2>
			<p class="goal-form__help">
				{{
					t(
						'hermiq',
						'The agent keeps working on the goal in this session, a turn at a time, until the check says it is reached.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextArea
				v-model="form.statement"
				data-testid="goal-statement"
				:label="t('hermiq', 'Goal')"
				:placeholder="
					t(
						'hermiq',
						'Every overdue permit application has had a reminder',
					)
				"
				:maxlength="500" />

			<fieldset class="goal-form__kinds">
				<legend>{{ t('hermiq', 'How do we know it is reached?') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="form.kind"
					type="radio"
					value="objectCount"
					name="goal-check-kind"
					data-testid="goal-kind-count">
					{{
						t(
							'hermiq',
							'A count of objects reaches a target (preferred)',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.kind"
					type="radio"
					value="judge"
					name="goal-check-kind"
					data-testid="goal-kind-judge">
					{{
						t(
							'hermiq',
							'A model answers a question about the last answer',
						)
					}}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<template v-if="form.kind === 'objectCount'">
				<NcTextField
					v-model="form.register"
					data-testid="goal-register"
					:label="t('hermiq', 'Register')" />
				<NcTextField
					v-model="form.schema"
					data-testid="goal-schema"
					:label="t('hermiq', 'Schema')" />
				<NcTextArea
					v-model="form.filters"
					data-testid="goal-filters"
					:label="t('hermiq', 'Filters (JSON)')"
					placeholder='{"status": "overdue"}' />
				<NcTextField
					v-model="form.target"
					type="number"
					data-testid="goal-target"
					:label="t('hermiq', 'Reached when the count is')" />
			</template>
			<NcTextArea
				v-else
				v-model="form.question"
				data-testid="goal-question"
				:label="t('hermiq', 'Question for the model')"
				:placeholder="
					t('hermiq', 'Has every overdue application had a reminder?')
				" />

			<NcTextField
				v-model="form.intervalMinutes"
				type="number"
				data-testid="goal-interval"
				:label="t('hermiq', 'Take a turn every (minutes, 15 to 1440)')" />
			<NcTextField
				v-model="form.maxTurns"
				type="number"
				data-testid="goal-max-turns"
				:label="t('hermiq', 'Turn limit (1 to 50)')" />

			<div class="goal-form__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="goal-save"
					:disabled="saving || !canSave"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { setSessionGoal } from '../api/chat.js'

/**
 * A fresh form.
 *
 * @return {object} The empty goal form.
 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
 */
function emptyForm() {
	return {
		statement: '',
		kind: 'objectCount',
		register: '',
		schema: '',
		filters: '',
		target: '0',
		question: '',
		intervalMinutes: '60',
		maxTurns: '10',
	}
}

export default {
	name: 'GoalFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The session the goal is set on. */
		session: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: emptyForm(),
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the form holds a goal and a complete check.
		 *
		 * @return {boolean} True when it can be saved.
		 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
		 */
		canSave() {
			if (!this.form.statement.trim()) {
				return false
			}
			if (this.form.kind === 'judge') {
				return this.form.question.trim() !== ''
			}
			return this.form.register.trim() !== '' && this.form.schema.trim() !== ''
		},
	},

	watch: {
		/**
		 * Start from an empty form each time the modal opens.
		 *
		 * @param {boolean} open Whether the modal is now shown.
		 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
		 */
		show(open) {
			if (open) {
				this.form = emptyForm()
				this.error = ''
			}
		},
	},

	methods: {
		/**
		 * The check the form describes, or null when the filters are not JSON.
		 *
		 * @return {object|null} The check.
		 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
		 */
		check() {
			if (this.form.kind === 'judge') {
				return { kind: 'judge', question: this.form.question.trim() }
			}
			let filters = {}
			if (this.form.filters.trim() !== '') {
				try {
					filters = JSON.parse(this.form.filters)
				} catch {
					return null
				}
			}
			return {
				kind: 'objectCount',
				register: this.form.register.trim(),
				schema: this.form.schema.trim(),
				filters,
				target: Number(this.form.target) || 0,
			}
		},

		/**
		 * Save the goal and hand it to the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
		 */
		async save() {
			if (!this.session?.uuid || !this.canSave) {
				return
			}
			const check = this.check()
			if (check === null) {
				this.error = t('hermiq', 'The filters are not valid JSON.')
				return
			}
			this.saving = true
			this.error = ''
			try {
				const goal = await setSessionGoal(this.session.uuid, {
					statement: this.form.statement.trim(),
					check,
					intervalMinutes: Number(this.form.intervalMinutes) || 60,
					maxTurns: Number(this.form.maxTurns) || 10,
				})
				this.$emit('saved', goal)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('hermiq', 'The goal could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.goal-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.goal-form__title {
	margin: 0 0 4px;
	font-size: 20px;
	font-weight: 600;
}

.goal-form__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.goal-form__kinds {
	border: none;
	margin: 0;
	padding: 0;
}

.goal-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
