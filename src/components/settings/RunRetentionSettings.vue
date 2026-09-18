<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RunRetentionSettings — how long AI run records are kept, and whether anything is
  enforcing it (what-the-model-reads-and-what-is-kept).

  An AVG verwerkingsregister has to say what personal data a model was shown and for
  how long, and a retention nobody set is a retention of forever, so this instance
  always has a default. The second half is the part a setting alone cannot give: the
  report says when the cleanup job last ran and how much it removed, and a job that
  has never run says exactly that rather than reading as a successful run of zero.

  All strings via t(); server data comes from the admin-gated API, never a DOM read.

  @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-a-scheduled-job-must-enforce-retention-and-must-report-that-it-did
-->
<template>
	<div class="run-retention">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Retention error')">
			{{ error }}
		</NcNoteCard>

		<div class="run-retention__row">
			<NcTextField
				v-model="days"
				type="number"
				:label="t('hermiq', 'Keep AI run records for (days)')"
				:disabled="loading || busy"
				:min="minimumDays"
				:max="maximumDays" />
			<NcButton
				variant="primary"
				:disabled="loading || busy || !changed"
				@click="save">
				{{ t('hermiq', 'Save retention') }}
			</NcButton>
		</div>

		<p class="run-retention__report">
			<span v-if="lastCleanup.ran">
				{{
					t('hermiq', 'Retention last ran on {at} and removed {removed} run records.', {
						at: lastCleanup.at,
						removed: String(lastCleanup.removed),
					})
				}}
			</span>
			<span v-else>
				{{
					t(
						'hermiq',
						'Retention has not run yet on this instance. It runs once a day, and this line will say when it last did.',
					)
				}}
			</span>
		</p>

		<p class="run-retention__note">
			{{
				t(
					'hermiq',
					'A cleanup removes what a run recorded, never the audit entry itself: the trail keeps that a run happened, when, for which feature and provider, and that the rest was deleted under retention.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { getRunRetention, setRunRetention } from '../../api/runRetention.js'

export default {
	name: 'RunRetentionSettings',

	components: {
		NcButton,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			days: '90',
			storedDays: '90',
			minimumDays: 1,
			maximumDays: 3650,
			lastCleanup: { ran: false, at: '', removed: null },
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the typed retention differs from the stored one.
		 *
		 * @return {boolean} True when there is something to save.
		 */
		changed() {
			return String(this.days) !== String(this.storedDays)
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Read the retention and the report.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#scenario-the-last-cleanup-is-an-answerable-question
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const data = await getRunRetention()
				this.days = String(data.defaultDays)
				this.storedDays = String(data.defaultDays)
				this.minimumDays = data.minimumDays
				this.maximumDays = data.maximumDays
				this.lastCleanup = data.lastCleanup
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the retention. A value outside the permitted range comes back as a
		 * 422 naming the range, which is shown rather than swallowed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/what-the-model-reads-and-what-is-kept/specs/run-audit-log/spec.md#requirement-every-ai-run-must-carry-the-retention-that-applied-when-it-was-written
		 */
		async save() {
			this.busy = true
			this.error = ''
			try {
				const data = await setRunRetention(Number(this.days))
				this.storedDays = String(data.defaultDays)
				this.days = String(data.defaultDays)
				this.lastCleanup = data.lastCleanup
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The server's own wording where there is one, because it names the range.
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
.run-retention__row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 480px;
}

.run-retention__report {
	margin: 12px 0 0;
	font-size: 13px;
}

.run-retention__note {
	margin: 4px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
