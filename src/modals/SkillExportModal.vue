<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillExportModal — shows a skill's exported agentskills.io package after the
  Export row action on the Skills catalog. Own file per the modal-isolation
  rule; imported by SkillRowActions.vue.

  Pure presentation: the parent performs the export call and passes the
  resulting package text in.

  @spec openspec/specs/skills-catalog/spec.md#requirement-bidirectional-skillserializer-round-trip-fidelity
-->
<template>
	<NcModal @close="$emit('close')">
		<div class="skill-export-modal">
			<h3>{{ t('hermiq', 'Exported package') }}</h3>
			<!--
			  The <label> WRAPS the control: that is the canonical HTML association
			  and needs no for/id pair. An aria-label would have satisfied gate-40
			  too, but it names the field for a screen reader while leaving a
			  speech-input user with nothing visible to say — so the visible label
			  is the fix, not the attribute.
			-->
			<label class="skill-export-modal__label">
				<span>{{ t('hermiq', 'Package contents') }}</span>
				<textarea
					class="skill-export-modal__textarea"
					readonly
					:value="exportedPackage" />
			</label>
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'

export default {
	name: 'SkillExportModal',

	components: {
		NcModal,
	},

	props: {
		/** The exported agentskills.io package text. */
		exportedPackage: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
}
</script>

<style scoped>
.skill-export-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.skill-export-modal__label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.skill-export-modal__textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	font-size: 13px;
	margin-bottom: 8px;
	resize: vertical;
}
</style>
