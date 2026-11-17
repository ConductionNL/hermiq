<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RunConcurrencySettings — how many scheduled agent runs may go at once, on the
  instance and per organisation (operations-parallel-run-workers).

  The schedule tick queues each due occurrence for a background worker; these
  limits keep one organisation from taking every worker. A run over a limit
  waits in the queue, it is never dropped.

  All strings via t(); server data comes from the admin-gated API, never a DOM read.

  @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
-->
<template>
	<div class="run-concurrency">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Run limits error')">
			{{ error }}
		</NcNoteCard>

		<div class="run-concurrency__row">
			<NcTextField
				v-model="maxParallel"
				type="number"
				:label="t('hermiq', 'Scheduled runs at once on this instance')"
				:disabled="loading || busy"
				:min="1"
				:max="maximum" />
			<NcTextField
				v-model="maxParallelPerOrganisation"
				type="number"
				:label="t('hermiq', 'Scheduled runs at once per organisation')"
				:disabled="loading || busy"
				:min="1"
				:max="maximum" />
			<NcButton
				variant="primary"
				:disabled="loading || busy || !changed"
				@click="save">
				{{ t('hermiq', 'Save run limits') }}
			</NcButton>
		</div>

		<p class="run-concurrency__note">
			{{
				t(
					'hermiq',
					'Runs go at once only when the instance runs more than one background worker (occ background-job:worker). A run over a limit waits in the queue until a run finishes.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { getRunConcurrency, setRunConcurrency } from '../../api/runConcurrency.js'
import { settingsCall } from './settingsCall.js'

export default {
	name: 'RunConcurrencySettings',

	components: {
		NcButton,
		NcNoteCard,
		NcTextField,
	},

	mixins: [settingsCall],

	data() {
		return {
			maxParallel: '4',
			maxParallelPerOrganisation: '2',
			stored: { maxParallel: '4', maxParallelPerOrganisation: '2' },
			maximum: 64,
		}
	},

	computed: {
		/**
		 * Whether a typed limit differs from the stored one.
		 *
		 * @return {boolean} True when there is something to save.
		 */
		changed() {
			return String(this.maxParallel) !== this.stored.maxParallel
				|| String(this.maxParallelPerOrganisation) !== this.stored.maxParallelPerOrganisation
		},
	},

	methods: {
		/**
		 * Show the limits the server answered.
		 *
		 * @param {{maxParallel: number, maxParallelPerOrganisation: number, maximum: number}} data The limits.
		 * @return {void}
		 */
		apply(data) {
			this.maxParallel = String(data.maxParallel)
			this.maxParallelPerOrganisation = String(data.maxParallelPerOrganisation)
			this.stored = {
				maxParallel: String(data.maxParallel),
				maxParallelPerOrganisation: String(data.maxParallelPerOrganisation),
			}
			this.maximum = data.maximum
		},

		/**
		 * Read the limits.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
		 */
		async load() {
			await this.withFlag('loading', async () => {
				this.apply(await getRunConcurrency())
			})
		},

		/**
		 * Save the limits. A limit out of range, or an organisation limit above
		 * the instance limit, comes back as a 422 with the reason, which is shown.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-parallel-run-workers/specs/agent-schedule/spec.md#requirement-an-administrator-limits-how-many-runs-go-at-once-req-prun-003
		 */
		async save() {
			await this.withFlag('busy', async () => {
				this.apply(await setRunConcurrency(Number(this.maxParallel), Number(this.maxParallelPerOrganisation)))
			})
		},
	},
}
</script>

<style scoped>
.run-concurrency__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
	max-width: 720px;
}

.run-concurrency__note {
	margin: 12px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
