<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillDraftRejectModal — reject a consolidation draft (skill-self-improvement)
  with an optional note and per-entry bad-learnings marking: marked refs are
  recorded on the DRAFT (rejectedLearningRefs — never an edit to learnings.md)
  and excluded from driving the skill's next proposal.

  Own file per ADR-004 modal-isolation.

  @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
-->
<template>
	<NcModal
		:show="true"
		size="normal"
		:name="t('hermiq', 'Reject draft')"
		@close="$emit('close')">
		<div class="skill-draft-reject">
			<h2 class="skill-draft-reject__title">
				{{ t('hermiq', 'Reject draft') }}
			</h2>

			<label class="skill-draft-reject__label" for="skill-draft-reject-note">
				{{ t('hermiq', 'Reason (optional)') }}
			</label>
			<textarea
				id="skill-draft-reject-note"
				v-model="note"
				class="skill-draft-reject__textarea"
				rows="3" />

			<template v-if="learningRefs.length > 0">
				<p class="skill-draft-reject__hint">
					{{
						t(
							'hermiq',
							'Mark learnings entries that led the proposal astray — marked entries will not drive the next proposal.',
						)
					}}
				</p>
				<ul class="skill-draft-reject__refs">
					<li v-for="ref in learningRefs" :key="ref">
						<NcCheckboxRadioSwitch
							:model-value="marked.includes(ref)"
							@update:modelValue="toggle(ref, $event)">
							<code>{{ ref }}</code>
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
			</template>

			<div class="skill-draft-reject__actions">
				<NcButton
					type="error"
					:disabled="busy"
					:aria-label="t('hermiq', 'Confirm the rejection')"
					@click="$emit('reject', { note, rejectedLearningRefs: marked })">
					{{
						busy
							? t('hermiq', 'Rejecting…')
							: t('hermiq', 'Reject draft')
					}}
				</NcButton>
				<NcButton type="tertiary" :disabled="busy" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'

export default {
	name: 'SkillDraftRejectModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcModal,
	},

	props: {
		/** The draft's driving learnings refs (markable as bad). */
		learningRefs: {
			type: Array,
			default: () => [],
		},
		/** Whether the rejection is in flight. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			note: '',
			marked: [],
		}
	},

	methods: {
		/**
		 * Toggle one ref in the marked set — builds the rejectedLearningRefs
		 * bad-learnings payload the rejection records on the draft.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
		 * @param {string} ref The learnings entry ref.
		 * @param {boolean} checked Whether it is now marked.
		 */
		toggle(ref, checked) {
			if (checked && !this.marked.includes(ref)) {
				this.marked = [...this.marked, ref]
				return
			}
			if (!checked) {
				this.marked = this.marked.filter((entry) => entry !== ref)
			}
		},
	},
}
</script>

<style scoped>
.skill-draft-reject {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.skill-draft-reject__title {
	margin: 0;
}

.skill-draft-reject__label,
.skill-draft-reject__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.skill-draft-reject__textarea {
	width: 100%;
	resize: vertical;
}

.skill-draft-reject__refs {
	margin: 0;
	padding: 0;
	list-style: none;
}

.skill-draft-reject__actions {
	display: flex;
	gap: 8px;
}
</style>
