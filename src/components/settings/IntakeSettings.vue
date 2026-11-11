<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  IntakeSettings — how certain the conversational intake has to be before it files
  (a-conversational-intake-that-files-for-the-citizen).

  Below this confidence the assistant says it does not know and hands the
  conversation to a person, with what was said so far. That is the safer failure: an
  abstention costs somebody a short wait, and a bezwaar filed as a melding costs them
  a statutory term that nobody notices has run.

  The number is on the screen because whoever set it has to be able to answer what it
  is.

  @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-threshold-is-readable
-->
<template>
	<div class="intake-settings">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Intake settings error')">
			{{ error }}
		</NcNoteCard>

		<div class="intake-settings__row">
			<NcTextField
				v-model="threshold"
				type="number"
				step="0.05"
				:label="t('hermiq', 'File only when this certain')"
				:disabled="loading || busy" />
			<NcButton variant="primary" :disabled="loading || busy" @click="save">
				{{ t('hermiq', 'Save threshold') }}
			</NcButton>
		</div>

		<p class="intake-settings__note">
			{{
				t(
					'hermiq',
					'Under this, the conversation goes to a person instead, carrying everything that was said. Nobody is left without an answer either way.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { getIntakeSettings, setIntakeSettings } from '../../api/intake.js'
import { settingsCall } from './settingsCall.js'

export default {
	name: 'IntakeSettings',

	components: {
		NcButton,
		NcNoteCard,
		NcTextField,
	},

	mixins: [settingsCall],

	data() {
		return {
			threshold: '0.8',
		}
	},

	methods: {
		/**
		 * Read the threshold in force.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-threshold-is-readable
		 */
		async load() {
			await this.withFlag('loading', async () => {
				const data = await getIntakeSettings()
				this.threshold = String(data.abstentionThreshold)
			})
		},

		/**
		 * Save the threshold.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
		 */
		async save() {
			await this.withFlag('busy', async () => {
				const data = await setIntakeSettings(Number(this.threshold))
				this.threshold = String(data.abstentionThreshold)
			})
		},
	},
}
</script>

<style scoped>
.intake-settings__row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 480px;
}

.intake-settings__note {
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
