<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentAnswerCacheClearDialog: clear one agent's cached answers
  (models-prompt-and-answer-cache).

  Own file per ADR-004 modal-isolation, NcDialog, opened by AgentDetail's
  "Clear cached answers" header action. Only the agent's owner may clear; the
  server refuses anyone else and the refusal is shown here.

  @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Clear cached answers')"
		:open="show"
		size="small"
		@update:open="$emit('close')">
		<p v-if="done" data-testid="answer-cache-cleared">
			{{ n('hermiq', '%n cached answer removed.', '%n cached answers removed.', removed) }}
		</p>
		<p v-else>
			{{ t('hermiq', 'The next questions to this agent go to the model again. Answers it gives are cached anew while the cache is on.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ done ? t('hermiq', 'Close') : t('hermiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="error"
				:disabled="busy || !resolvedAgentId"
				data-testid="answer-cache-clear"
				@click="clear">
				{{ t('hermiq', 'Clear cached answers') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { clearAnswerCache } from '../api/agentAnswerCache.js'
import { agentModalTarget } from './agentModalTarget.js'

/**
 * Confirm, then clear the agent's cached answers and say how many went.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
 */
export default {
	name: 'AgentAnswerCacheClearDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	mixins: [agentModalTarget],

	emits: ['close'],

	data() {
		return {
			busy: false,
			done: false,
			removed: 0,
			error: '',
		}
	},

	methods: {
		/**
		 * Clear the cache; a refusal or failure is shown, not thrown.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/models-prompt-and-answer-cache/specs/answer-cache/spec.md#requirement-cached-answers-can-be-cleared-and-expire-req-acache-004
		 */
		async clear() {
			this.busy = true
			this.error = ''
			try {
				this.removed = await clearAnswerCache(this.resolvedAgentId)
				this.done = true
			} catch (e) {
				this.error = e?.response?.data?.error
					|| this.t('hermiq', 'The cached answers could not be cleared.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
