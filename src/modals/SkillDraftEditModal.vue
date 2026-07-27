<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillDraftEditModal — the edit-then-accept editor (skill-self-improvement),
  available ONLY from the SkillDetail review surface (editing needs the surface).

  Own file per ADR-004 modal-isolation. Saving replaces the DRAFT's proposed
  body; server-side this INVALIDATES the stored scan + eval evidence and re-runs
  pre-qualification — the linked Approval is not approvable from any surface
  until it passes, and the edit is recorded (editedBeforeAccept + editor):
  human curation is evidence, not noise.

  @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
-->
<template>
	<NcModal
		:show="true"
		size="large"
		:name="t('hermiq', 'Edit draft before accepting')"
		@close="$emit('close')">
		<div class="skill-draft-edit">
			<h2 class="skill-draft-edit__title">
				{{ t('hermiq', 'Edit draft before accepting') }}
			</h2>

			<p class="skill-draft-edit__hint">
				{{ t('hermiq', 'Saving re-runs the content scan and the paired eval over your edited text. The draft cannot be accepted anywhere until re-qualification passes.') }}
			</p>

			<label class="skill-draft-edit__label" for="skill-draft-edit-body">
				{{ t('hermiq', 'Proposed body (markdown)') }}
			</label>
			<textarea
				id="skill-draft-edit-body"
				v-model="body"
				class="skill-draft-edit__textarea"
				rows="18" />

			<div class="skill-draft-edit__actions">
				<NcButton type="primary"
					:disabled="busy || body.trim() === ''"
					:aria-label="t('hermiq', 'Save the edited draft and re-qualify')"
					@click="$emit('save', { body })">
					{{ busy ? t('hermiq', 'Saving…') : t('hermiq', 'Save and re-qualify') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="busy" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

export default {
	name: 'SkillDraftEditModal',

	components: {
		NcButton,
		NcModal,
	},

	props: {
		/** The awaiting-approval draft being edited. */
		draft: {
			type: Object,
			required: true,
		},
		/** Whether a save is in flight. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			body: typeof this.draft?.proposedBody === 'string' ? this.draft.proposedBody : '',
		}
	},
}
</script>

<style scoped>
.skill-draft-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.skill-draft-edit__title {
	margin: 0;
}

.skill-draft-edit__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.skill-draft-edit__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.skill-draft-edit__textarea {
	width: 100%;
	font-family: monospace;
	font-size: 13px;
	resize: vertical;
}

.skill-draft-edit__actions {
	display: flex;
	gap: 8px;
}
</style>
