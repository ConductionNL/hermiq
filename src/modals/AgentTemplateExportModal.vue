<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentTemplateExportModal — shows a template's exported JSON package after the
  Export row action on the Store page. Own file per the modal-isolation rule
  (gate-13); imported by AgentTemplateRowActions.vue, where it used to be
  written inline.

  Pure presentation: the parent performs the export call and passes the
  resulting package text in.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints
-->
<template>
	<NcModal @close="$emit('close')">
		<div class="agent-template-export-modal">
			<h3>{{ t('hermiq', 'Exported package') }}</h3>
			<!--
			  The <label> WRAPS the control: that is the canonical HTML association
			  and needs no for/id pair. An aria-label would have satisfied gate-40
			  too, but it names the field for a screen reader while leaving a
			  speech-input user with nothing visible to say — so the visible label
			  is the fix, not the attribute.
			-->
			<label class="agent-template-export-modal__label">
				<span>{{ t('hermiq', 'Package contents') }}</span>
				<textarea class="agent-template-export-modal__textarea" readonly :value="exportedPackage" />
			</label>
		</div>
	</NcModal>
</template>

<script>
import { NcModal } from '@nextcloud/vue'

export default {
	name: 'AgentTemplateExportModal',

	components: {
		NcModal,
	},

	props: {
		/** The exported template package text. */
		exportedPackage: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
}
</script>

<style scoped>
.agent-template-export-modal {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-template-export-modal__label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-template-export-modal__textarea {
	width: 100%;
	min-height: 120px;
	font-family: monospace;
	font-size: 13px;
	margin-bottom: 8px;
	resize: vertical;
}
</style>
