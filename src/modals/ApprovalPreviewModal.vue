<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<!--
  ApprovalPreviewModal: what a held action will do, before the reviewer decides
  (oversight-what-an-approval-will-do). For a held tool call, the tool, whether
  it reads, changes, sends or deletes, how far it reaches and its arguments as
  stored after redaction. For a held run, the prompt and the tools the agent
  may call. Approve and deny are available here; the caller does the request.

  @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
-->
<template>
	<NcDialog
		:name="t('hermiq', 'What this will do')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="approval-preview">
			<p class="approval-preview__held">
				{{ heldBecause }}
			</p>
			<p v-if="approval.prompt" class="approval-preview__prompt">
				<strong>{{ t('hermiq', 'Prompt') }}:</strong> {{ approval.prompt }}
			</p>
			<p v-if="tools.length === 0" class="approval-preview__none">
				{{ t('hermiq', 'No tools are named for this action.') }}
			</p>
			<ul v-else class="approval-preview__tools">
				<li
					v-for="tool in tools"
					:key="tool.id"
					class="approval-preview__tool">
					<span class="approval-preview__name">{{ tool.name }}</span>
					<span
						class="approval-preview__effect"
						:class="`approval-preview__effect--${tool.effect}`">
						{{ effectLabel(tool.effect) }}
					</span>
					<span class="approval-preview__reach">{{
						reachLabel(tool.reach)
					}}</span>
					<pre v-if="tool.arguments" class="approval-preview__arguments">{{
						formatArguments(tool.arguments)
					}}</pre>
				</li>
			</ul>
		</div>

		<template #actions>
			<NcButton variant="error" @click="$emit('deny')">
				{{ t('hermiq', 'Deny') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('approve')">
				{{ t('hermiq', 'Approve') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ApprovalPreviewModal',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** The inbox record, with its `preview`. */
		approval: {
			type: Object,
			required: true,
		},
	},

	emits: ['approve', 'close', 'deny'],

	computed: {
		/**
		 * The tools the held action may call.
		 *
		 * @return {Array<object>} The preview's tools.
		 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		tools() {
			return Array.isArray(this.approval.preview?.tools)
				? this.approval.preview.tools
				: []
		},

		/**
		 * Why the action was held.
		 *
		 * @return {string} The reason, in the reader's language.
		 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		heldBecause() {
			return this.approval.preview?.kind === 'toolcall'
				? this.t(
						'hermiq',
						'This tool needs a reviewer before the agent may call it.',
					)
				: this.t('hermiq', 'This run needs a reviewer before it starts.')
		},
	},

	methods: {
		/**
		 * What a tool does, as a label.
		 *
		 * @param {string} effect reads, changes, sends or deletes.
		 * @return {string} The label.
		 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		effectLabel(effect) {
			const labels = {
				reads: this.t('hermiq', 'Reads'),
				changes: this.t('hermiq', 'Changes'),
				sends: this.t('hermiq', 'Sends'),
				deletes: this.t('hermiq', 'Deletes'),
			}
			return labels[effect] || effect
		},

		/**
		 * How far a tool reaches, as a label.
		 *
		 * @param {string} reach self, user, instance or external.
		 * @return {string} The label.
		 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		reachLabel(reach) {
			const labels = {
				self: this.t('hermiq', 'Only the agent itself'),
				user: this.t('hermiq', 'Your own files and data'),
				instance: this.t('hermiq', 'Everyone on this Nextcloud'),
				external: this.t('hermiq', 'Outside this Nextcloud'),
			}
			return labels[reach] || this.t('hermiq', 'Reach unknown')
		},

		/**
		 * The stored arguments, readable.
		 *
		 * @param {object} args The arguments, as stored after redaction.
		 * @return {string} Pretty JSON.
		 * @spec openspec/changes/oversight-what-an-approval-will-do/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		formatArguments(args) {
			return JSON.stringify(args, null, 2)
		},
	},
}
</script>

<style scoped>
.approval-preview__tools {
	list-style: none;
	margin: 12px 0 0;
	padding: 0;
}

.approval-preview__tool {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.approval-preview__name {
	font-weight: 600;
}

.approval-preview__effect--deletes,
.approval-preview__effect--sends {
	color: var(--color-error-text);
}

.approval-preview__reach,
.approval-preview__none {
	color: var(--color-text-maxcontrast);
}

.approval-preview__arguments {
	flex-basis: 100%;
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
</style>
