<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentExportModal: the agent page's "Export" and "Save as template".

  Export downloads the agent's secret-free package as `<agent-name>.hermiq-agent.json`
  (the existing export route, readers only). Save as template turns the agent into an
  active template in the Store (owner only, the server says 403 otherwise). One modal,
  two modes, because a header action can only open a modal with static props.

  @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001
-->
<template>
	<NcModal v-if="show" size="normal" :noClose="busy" @close="$emit('close')">
		<div class="agent-export">
			<h2 class="agent-export__title">
				{{
					saveMode
						? t('hermiq', 'Save as template')
						: t('hermiq', 'Export agent')
				}}
			</h2>

			<p v-if="saveMode">
				{{
					t(
						'hermiq',
						'The template appears in the Store. Colleagues create their own agent from it with Use this template. Sharing, schedules and credentials stay with this agent.',
					)
				}}
			</p>
			<p v-else>
				{{
					t(
						'hermiq',
						'The file holds the prompt, the model and the tools. It holds no users, groups, quotas or credentials.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="done" type="success">
				{{ done }}
			</NcNoteCard>

			<div class="agent-export__actions">
				<NcButton
					variant="tertiary"
					:disabled="busy"
					@click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !resolvedAgentId"
					@click="run">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{
						saveMode
							? t('hermiq', 'Save as template')
							: t('hermiq', 'Download file')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import { exportAgentToTemplate, saveAgentAsTemplate } from '../api/agentTemplates.js'
import { exportFileName } from '../utils/agentExport.js'

export default {
	name: 'AgentExportModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** `save` for Save as template; anything else exports to a file. */
		mode: {
			type: String,
			default: 'export',
		},

		/** The agent; when absent, the route's `:id` (open-modal props are static). */
		agentId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			busy: false,
			error: '',
			done: '',
		}
	},

	computed: {
		/**
		 * Whether this is Save as template.
		 *
		 * @return {boolean}
		 @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001
		 */
		saveMode() {
			return this.mode === 'save'
		},

		/**
		 * The agent uuid: the prop, else the route's `:id`.
		 *
		 * @return {string}
		 @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},
	},

	methods: {
		/**
		 * Export or save, by mode.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-saved-as-a-reusable-template-req-agexp-003
		 */
		async run() {
			this.busy = true
			this.error = ''
			this.done = ''
			try {
				if (this.saveMode) {
					const template = await saveAgentAsTemplate(this.resolvedAgentId)
					this.done = this.t(
						'hermiq',
						'Saved as the template {name}. You find it in the Store.',
						{ name: template?.name || '' },
					)
				} else {
					const pkg = await exportAgentToTemplate(this.resolvedAgentId)
					this.download(pkg)
					this.done = this.t('hermiq', 'The file is downloaded.')
				}
			} catch (e) {
				if (e?.response?.status === 403) {
					this.error = this.t(
						'hermiq',
						'Only the owner of this agent can save it as a template.',
					)
				} else {
					this.error = this.saveMode
						? this.t('hermiq', 'Could not save the agent as a template')
						: this.t('hermiq', 'Could not export the agent')
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Hand the package to the browser as a file named after the agent.
		 *
		 * @param {string} pkg The package text.
		 * @return {void}
		 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001
		 */
		download(pkg) {
			let name
			try {
				name = JSON.parse(pkg)?.name || ''
			} catch {
				name = ''
			}
			const url = URL.createObjectURL(
				new Blob([pkg], { type: 'application/json' }),
			)
			const link = document.createElement('a')
			link.href = url
			link.download = exportFileName(name)
			document.body.appendChild(link)
			link.click()
			link.remove()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.agent-export {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-export__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.agent-export__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
