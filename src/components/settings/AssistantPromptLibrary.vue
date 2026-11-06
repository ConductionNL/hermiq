<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AssistantPromptLibrary — the prompts the assistant offers on a record, as objects
  an administrator can read (the-declared-tool-surface-and-the-prompt-library).

  A gemeente that cannot read the prompt cannot defend the output: when a citizen
  asks why the assistant summarised their bezwaar as it did, the answer is the exact
  text on this page. So the text is shown in full, not truncated to a preview, and
  the library is rendered in the administrator's own order rather than re-sorted.

  Disabling everything is one button, because an incident response that requires
  editing twelve rows is not a response. There is no button to switch everything back
  on: coming back is per prompt, deliberately, since a bulk re-enable would restore a
  prompt that had been switched off weeks earlier for a different reason.

  A standard settings component — no inline modal (modal-isolation gate), no DOM
  reads, all strings via t().

  @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-the-prompts-the-assistant-offers-must-be-administered-objects
-->
<template>
	<div class="prompt-library">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Prompt library error')">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-if="!loading && prompts.length === 0"
			:name="t('hermiq', 'No prompts yet')"
			:description="
				t(
					'hermiq',
					'A consuming app ships an initial library, and an administrator edits it here. Until one does, the assistant offers nothing on a record.',
				)
			" />

		<ul v-else class="prompt-library__list">
			<li
				v-for="prompt in prompts"
				:key="prompt.id"
				class="prompt-library__item"
				:class="{
					'prompt-library__item--disabled': prompt.enabled === false,
				}">
				<div class="prompt-library__head">
					<strong class="prompt-library__label">{{ prompt.label }}</strong>
					<span class="prompt-library__scope">
						{{ prompt.usageScope || t('hermiq', 'Everywhere') }}
					</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						@click="toggle(prompt)">
						{{
							prompt.enabled === false
								? t('hermiq', 'Enable')
								: t('hermiq', 'Disable')
						}}
					</NcButton>
				</div>
				<!-- The exact text that is sent, in full: a truncated prompt is one
				     nobody can defend an answer with. -->
				<pre class="prompt-library__text">{{ prompt.prompt }}</pre>
			</li>
		</ul>

		<div class="prompt-library__actions">
			<NcButton
				variant="secondary"
				:disabled="busy || prompts.length === 0"
				@click="disableAll">
				{{ t('hermiq', 'Disable every prompt') }}
			</NcButton>
			<span class="prompt-library__hint">
				{{
					t(
						'hermiq',
						'Switching everything off is one act and is recorded with who did it and when. Switching prompts back on is done one at a time.',
					)
				}}
			</span>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import {
	disableAllAssistantPrompts,
	listAssistantPrompts,
	saveAssistantPrompt,
} from '../../api/assistantPrompts.js'

export default {
	name: 'AssistantPromptLibrary',

	components: {
		NcButton,
		NcEmptyContent,
		NcNoteCard,
	},

	data() {
		return {
			prompts: [],
			loading: true,
			busy: false,
			error: '',
		}
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Read the library, in the administrator's order.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-order-is-the-administrators
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.prompts = await listAssistantPrompts()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Switch one prompt on or off.
		 *
		 * @param {object} prompt The prompt row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-coming-back-is-deliberate
		 */
		async toggle(prompt) {
			this.busy = true
			this.error = ''
			try {
				await saveAssistantPrompt(prompt.id, {
					enabled: prompt.enabled === false,
				})
				await this.load()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Switch every prompt off in one act.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-everything-stops-in-one-act
		 */
		async disableAll() {
			this.busy = true
			this.error = ''
			try {
				await disableAllAssistantPrompts()
				await this.load()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The server's own wording where there is one.
		 *
		 * @param {object} e The caught error.
		 * @return {string} The message to show.
		 */
		messageFor(e) {
			return (
				e?.response?.data?.error
				|| e?.message
				|| this.t('hermiq', 'Unknown error')
			)
		},
	},
}
</script>

<style scoped>
.prompt-library__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.prompt-library__item {
	border-bottom: 1px solid var(--color-border);
	padding: 8px 0;
}

.prompt-library__item--disabled {
	opacity: 0.6;
}

.prompt-library__head {
	display: flex;
	align-items: center;
	gap: 12px;
}

.prompt-library__label {
	font-size: 14px;
}

.prompt-library__scope {
	font-size: 12px;
	text-transform: uppercase;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.prompt-library__text {
	margin: 4px 0 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.prompt-library__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
}

.prompt-library__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
