<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ReportGroupingSettings — where the line falls between one event and two
  (identical-reports-collapse-into-one).

  Both thresholds are shown and both are editable, because the cost of each kind of
  mistake is the municipality's to weigh. Above the upper one a report is counted
  into a group. Below the lower one it stands alone. Between them it is a
  near-duplicate: attached to the group and listed beside it, which is where a
  human's attention belongs. A single threshold would force every borderline report
  into one of two failures, so the middle band is the point rather than a detail.

  All strings via t(); server data comes from the admin-gated API, never a DOM read.

  @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
-->
<template>
	<div class="report-grouping">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Grouping settings error')">
			{{ error }}
		</NcNoteCard>

		<div class="report-grouping__row">
			<NcTextField
				v-model="upper"
				type="number"
				step="0.05"
				:label="t('hermiq', 'Same event at or above')"
				:disabled="loading || busy" />
			<NcTextField
				v-model="lower"
				type="number"
				step="0.05"
				:label="t('hermiq', 'A report of its own below')"
				:disabled="loading || busy" />
			<NcButton variant="primary" :disabled="loading || busy" @click="save">
				{{ t('hermiq', 'Save thresholds') }}
			</NcButton>
		</div>

		<p class="report-grouping__note">
			{{
				t(
					'hermiq',
					'A report scoring between the two is a near-duplicate: it joins the group and is listed beside it, so somebody reads it rather than finding it buried or missing.',
				)
			}}
		</p>

		<p class="report-grouping__note">
			{{
				t('hermiq', 'Reports are only compared against others from the last {minutes} minutes.', {
					minutes: String(defaultWindowMinutes),
				})
			}}
		</p>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import {
	getReportSimilaritySettings,
	setReportSimilaritySettings,
} from '../../api/reportSimilarity.js'

export default {
	name: 'ReportGroupingSettings',

	components: {
		NcButton,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			upper: '0.85',
			lower: '0.55',
			defaultWindowMinutes: 1440,
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
		 * Read both thresholds and the window.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const data = await getReportSimilaritySettings()
				this.upper = String(data.upper)
				this.lower = String(data.lower)
				this.defaultWindowMinutes = data.defaultWindowMinutes
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save both thresholds. A lower that does not sit below the upper comes back
		 * as a 422 saying why, which is shown rather than swallowed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-a-report-must-fall-into-one-of-three-bands-not-two
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				const data = await setReportSimilaritySettings({
					upper: Number(this.upper),
					lower: Number(this.lower),
				})
				this.upper = String(data.upper)
				this.lower = String(data.lower)
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
.report-grouping__row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 640px;
}

.report-grouping__note {
	margin: 8px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
