<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ComputerTargets — the machines an agent may operate, in the admin settings
  (tools-browser-and-computer-use, REQ-COMPUTER-001).

  Each target is reached only through an integriq source; its key lives in the
  credential broker behind that source. "Test screenshot" asks the machine for one
  screenshot through integriq and shows it here: it is held in this view only and
  never stored. Only an admin reaches this page, and the routes refuse anyone else.

  @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
-->
<template>
	<div class="computer-targets">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="!loading && targets.length === 0" class="computer-targets__empty">
			{{ t('hermiq', 'No machine is registered. Agents cannot operate a computer until an admin adds one.') }}
		</p>

		<table v-else-if="targets.length > 0" class="computer-targets__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('hermiq', 'Name') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Applications') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Agents may use it') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('hermiq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="target in targets" :key="target.id">
					<td>{{ target.name }}</td>
					<td>{{ target.applications || '—' }}</td>
					<td>{{ target.enabled ? t('hermiq', 'Yes') : t('hermiq', 'No') }}</td>
					<td class="computer-targets__actions">
						<NcButton variant="tertiary" :disabled="busy" @click="screenshot(target)">
							{{ t('hermiq', 'Test screenshot') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="edit(target)">
							{{ t('hermiq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="remove(target)">
							{{ t('hermiq', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcButton variant="secondary" :disabled="loading || busy" @click="edit(null)">
			{{ t('hermiq', 'Add computer target') }}
		</NcButton>

		<figure v-if="shot" class="computer-targets__shot">
			<img :src="`data:${shot.mimeType};base64,${shot.image}`" :alt="t('hermiq', 'Test screenshot of {name}', { name: shot.name }, undefined, { escape: false })">
			<figcaption>
				{{ t('hermiq', 'Test screenshot of {name}. It is shown here only and not stored.', { name: shot.name }, undefined, { escape: false }) }}
			</figcaption>
		</figure>

		<ComputerTargetFormModal
			:show="editing !== false"
			:target="editing || null"
			@saved="load"
			@close="editing = false" />
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import ComputerTargetFormModal from '../../modals/ComputerTargetFormModal.vue'
import { deleteComputerTarget, listComputerTargets, takeTestScreenshot } from '../../api/computerTargets.js'
import { settingsCall } from './settingsCall.js'

export default {
	name: 'ComputerTargets',

	components: {
		ComputerTargetFormModal,
		NcButton,
		NcNoteCard,
	},

	mixins: [settingsCall],

	data() {
		return {
			targets: [],
			/** The target the form is open for, null for a new one, false when closed. */
			editing: false,
			/** The last test screenshot, held in this view only. */
			shot: null,
		}
	},

	methods: {
		/**
		 * Read the registered targets.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		async load() {
			await this.withFlag('loading', async () => {
				this.targets = await listComputerTargets()
			})
		},

		/**
		 * Open the form for a target, or for a new one.
		 *
		 * @param {object|null} target The target, or null.
		 * @return {void}
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		edit(target) {
			this.editing = target
		},

		/**
		 * Remove a target and read the list again.
		 *
		 * @param {object} target The target.
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		async remove(target) {
			await this.withFlag('busy', async () => {
				await deleteComputerTarget(target.id)
				if (this.shot && this.shot.id === target.id) {
					this.shot = null
				}
				this.targets = await listComputerTargets()
			})
		},

		/**
		 * Take a test screenshot through the target's integriq source and show it.
		 *
		 * @param {object} target The target.
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
		 */
		async screenshot(target) {
			this.shot = null
			await this.withFlag('busy', async () => {
				const answer = await takeTestScreenshot(target.id)
				this.shot = { id: target.id, name: target.name, image: answer.image, mimeType: answer.mimeType || 'image/png' }
			})
		},
	},
}
</script>

<style scoped>
.computer-targets {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.computer-targets__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.computer-targets__table {
	width: 100%;
	border-collapse: collapse;
}

.computer-targets__table th,
.computer-targets__table td {
	text-align: start;
	padding: 8px;
	border-block-end: 1px solid var(--color-border);
}

.computer-targets__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.computer-targets__shot {
	margin: 0;
	max-width: 100%;
}

.computer-targets__shot img {
	max-width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.computer-targets__shot figcaption {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
