<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  PromptPlaceholderTools (agents-instruction-variables): under the agent's
  instructions, a menu that inserts a placeholder and a preview of the
  instructions filled in with the owner's own details and each question's
  default answer. The preview asks the server (POST /api/agents/{id}/prompt-preview),
  so it needs a saved agent.

  @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
-->
<template>
	<div class="prompt-placeholders" data-testid="agent-form-placeholders">
		<div class="prompt-placeholders__actions">
			<NcActions
				:menuName="t('hermiq', 'Insert placeholder')"
				:forceMenu="true"
				:forceName="true"
				data-testid="agent-form-insert-placeholder">
				<NcActionButton
					v-for="item in items"
					:key="item.token"
					:closeAfterClick="true"
					@click="$emit('insert', item.token)">
					{{ item.label }}
				</NcActionButton>
			</NcActions>
			<NcButton
				:disabled="!agentId || loading"
				data-testid="agent-form-preview-prompt"
				@click="preview">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Preview') }}
			</NcButton>
		</div>
		<p v-if="!agentId" class="prompt-placeholders__hint">
			{{
				t('hermiq', 'Save the agent to preview its filled-in instructions.')
			}}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-if="result">
			<pre
				class="prompt-placeholders__preview"
				data-testid="agent-form-prompt-preview"
				>{{ result.text }}</pre>
			<NcNoteCard v-if="result.unknown.length > 0" type="warning">
				{{
					t(
						'hermiq',
						'Left as written, because nothing has that name: {names}',
						{
							names: result.unknown.join(', '),
						},
					)
				}}
			</NcNoteCard>
		</template>
	</div>
</template>

<script>
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { previewPrompt } from '../api/agents.js'
import {
	initialAnswers,
	PLACEHOLDERS,
	startFieldsOf,
} from '../utils/instructionVariables.js'

export default {
	name: 'PromptPlaceholderTools',

	components: {
		NcActionButton,
		NcActions,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The saved agent's id, or '' for an agent not saved yet. */
		agentId: {
			type: String,
			default: '',
		},

		/** The instructions as typed. */
		prompt: {
			type: String,
			default: '',
		},

		/** The questions as edited. */
		startFields: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['insert'],

	data() {
		return {
			loading: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/** @return {Array<{token: string, label: string}>} The menu items. */
		items() {
			const labels = {
				displayName: this.t('hermiq', 'Name of the person'),
				userId: this.t('hermiq', 'User id of the person'),
				language: this.t('hermiq', 'Language of the person'),
				organisation: this.t('hermiq', 'Organisation'),
				today: this.t('hermiq', "Today's date"),
				now: this.t('hermiq', 'Date and time now'),
				agentName: this.t('hermiq', 'Name of this agent'),
				appId: this.t('hermiq', 'App the person is in'),
			}
			const fixed = PLACEHOLDERS.map((item) => ({
				token: item.token,
				label: labels[item.id],
			}))
			const fields = startFieldsOf({ startFields: this.startFields }).map(
				(field) => ({
					token: `{{field.${field.key}}}`,
					label: this.t('hermiq', 'Answer to "{question}"', {
						question: field.label,
					}),
				}),
			)
			return [...fixed, ...fields]
		},
	},

	methods: {
		/** @return {Promise<void>} Ask the server for the filled-in instructions. */
		async preview() {
			this.loading = true
			this.error = ''
			try {
				const fields = startFieldsOf({ startFields: this.startFields })
				this.result = await previewPrompt(this.agentId, {
					prompt: this.prompt,
					startFields: fields,
					sampleValues: initialAnswers(fields),
				})
			} catch {
				this.result = null
				this.error = this.t('hermiq', 'Could not preview the instructions.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.prompt-placeholders {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.prompt-placeholders__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.prompt-placeholders__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.prompt-placeholders__preview {
	margin: 0;
	padding: 8px;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}
</style>
