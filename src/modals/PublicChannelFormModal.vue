<!--
  PublicChannelFormModal (delivery-public-web-chat).

  The create/edit dialog for a public chat channel, wired into the Public chat
  settings page through `slots.form-dialog` and the CnIndexPage slot contract
  (`{ show, item, schema, close }`), the same seam ContextFormModal uses.

  The agent picker offers only agents without tools; the server's save guard
  refuses one with tools anyway, and gives a new channel its token. Once a
  website channel is saved, the dialog shows the script snippet and the iframe
  snippet for the website.

  @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
-->
<template>
	<NcModal :show="show" size="large" :name="heading" @close="handleClose">
		<div class="public-channel-form">
			<h2 class="public-channel-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="problems.length > 0"
				type="error"
				:heading="t('hermiq', 'Could not save the channel')">
				<ul>
					<li v-for="problem in problems" :key="problem">
						{{ problem }}
					</li>
				</ul>
			</NcNoteCard>

			<template v-if="saved === null">
				<NcTextField
					v-model="form.name"
					:label="t('hermiq', 'Name')"
					:required="true" />

				<fieldset class="public-channel-form__kind">
					<legend>{{ t('hermiq', 'Where the chat appears') }}</legend>
					<NcCheckboxRadioSwitch
						v-model="form.kind"
						type="radio"
						value="website"
						name="public-channel-kind">
						{{ t('hermiq', 'A website') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="form.kind"
						type="radio"
						value="app"
						name="public-channel-kind">
						{{ t('hermiq', 'Another app, such as a portal') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<NcSelect
					v-model="agentOption"
					:options="agentOptions"
					:inputLabel="t('hermiq', 'Agent')"
					:loading="loadingAgents"
					:placeholder="t('hermiq', 'Pick an agent without tools')"
					label="label" />
				<p v-if="!loadingAgents && agentOptions.length === 0" class="public-channel-form__hint">
					{{ t('hermiq', 'No agent without tools exists yet. A public assistant cannot use tools.') }}
				</p>

				<NcTextArea
					v-if="form.kind === 'website'"
					v-model="form.origins"
					:label="t('hermiq', 'Allowed origins, one per line')"
					:helperText="t('hermiq', 'Only these websites may show the chat, for example https://www.example.nl.')" />
				<NcTextField
					v-else
					v-model="form.channelKey"
					:label="t('hermiq', 'Channel key')"
					:helperText="t('hermiq', 'The key the app asks by, for example app:portaliq:wonen.')" />

				<NcTextArea
					v-model="form.scope"
					:label="t('hermiq', 'Public sources, one register/schema per line')"
					:helperText="t('hermiq', 'Answers come only from what these registers show to visitors who are not signed in.')" />

				<NcTextArea
					v-model="form.disclosureText"
					:label="t('hermiq', 'Notice shown to visitors')" />

				<NcTextField
					v-model="form.dailyQuestionLimit"
					type="number"
					min="1"
					:label="t('hermiq', 'Questions per day')" />

				<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
					{{ t('hermiq', 'Answer visitors') }}
				</NcCheckboxRadioSwitch>

				<div class="public-channel-form__actions">
					<NcButton variant="tertiary" @click="handleClose">
						{{ t('hermiq', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="save">
						<template v-if="saving" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('hermiq', 'Save') }}
					</NcButton>
				</div>
			</template>

			<template v-if="snippets !== null">
				<h3 class="public-channel-form__subhead">
					{{ t('hermiq', 'Put the chat on the website') }}
				</h3>
				<p class="public-channel-form__hint">
					{{ t('hermiq', 'Paste one of these into the website. The script adds a chat button; the iframe shows the chat in the page.') }}
				</p>
				<label class="public-channel-form__snippet-label" for="public-channel-script">{{ t('hermiq', 'Script') }}</label>
				<textarea
					id="public-channel-script"
					class="public-channel-form__snippet"
					readonly
					rows="2"
					:value="snippets.script" />
				<label class="public-channel-form__snippet-label" for="public-channel-iframe">{{ t('hermiq', 'Iframe') }}</label>
				<textarea
					id="public-channel-iframe"
					class="public-channel-form__snippet"
					readonly
					rows="3"
					:value="snippets.iframe" />
				<div v-if="saved !== null" class="public-channel-form__actions">
					<NcButton variant="primary" @click="handleClose">
						{{ t('hermiq', 'Done') }}
					</NcButton>
				</div>
			</template>
		</div>
	</NcModal>
</template>

<script>
import { generateFilePath, generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { useAgentStore, usePublicChannelStore } from '../store/store.js'
import {
	channelForm,
	channelPayload,
	embedSnippets,
	formProblems,
	publicAgentOptions,
} from '../utils/publicChannel.js'
import { formDialogSlot } from './formDialogSlot.js'

export default {
	name: 'PublicChannelFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	mixins: [formDialogSlot],

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: channelForm(this.item),
			agents: [],
			loadingAgents: false,
			saving: false,
			problems: [],
			saved: null,
		}
	},

	computed: {
		/**
		 * The dialog title.
		 *
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		heading() {
			return this.item ? this.t('hermiq', 'Edit public chat channel') : this.t('hermiq', 'New public chat channel')
		},

		/**
		 * The agents a channel may answer with.
		 *
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		agentOptions() {
			return publicAgentOptions(this.agents)
		},

		/**
		 * The picked agent, as the picker shows it.
		 *
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		agentOption: {
			get() {
				return this.agentOptions.find((option) => option.id === this.form.agentId) || null
			},

			set(option) {
				this.form.agentId = option?.id || ''
			},
		},

		/**
		 * The embed snippets of a saved website channel, or null.
		 *
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		snippets() {
			const channel = this.saved || this.item
			if (!channel || channel.kind === 'app') {
				return null
			}
			const token = channel.channelToken
			return embedSnippets(
				token,
				window.location.origin,
				generateFilePath('hermiq', 'js', 'public-chat.js'),
				generateUrl(`/apps/hermiq/public/${token}`),
			)
		},
	},

	watch: {
		/**
		 * Reset the form each time the dialog opens.
		 *
		 * @param {boolean} open Whether the dialog is now open.
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		show(open) {
			if (open) {
				this.form = channelForm(this.item)
				this.problems = []
				this.saved = null
				this.fetchAgents()
			}
		},
	},

	mounted() {
		if (this.show) {
			this.fetchAgents()
		}
	},

	methods: {
		/**
		 * Load the agents for the picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		async fetchAgents() {
			this.loadingAgents = true
			try {
				const store = useAgentStore()
				store.registerObjectType('agent', 'agent', 'hermiq')
				const agents = await store.fetchCollection('agent')
				this.agents = Array.isArray(agents) ? agents : []
			} catch {
				this.agents = []
			} finally {
				this.loadingAgents = false
			}
		},

		/**
		 * Save through the generic OpenRegister object store; show the snippets of a website channel.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
		 */
		async save() {
			this.problems = formProblems(this.form, this.t)
			if (this.problems.length > 0) {
				return
			}
			this.saving = true
			try {
				const store = usePublicChannelStore()
				store.registerObjectType('agentpublicchannel', 'agentpublicchannel', 'hermiq')
				const saved = await store.saveObject('agentpublicchannel', channelPayload(this.form, this.item))
				if (!saved) {
					const error = store.errors?.agentpublicchannel
					this.problems = [error?.message || String(error || '') || this.t('hermiq', 'The server did not accept the channel.')]
					return
				}
				this.$emit('saved', saved)
				if (saved.kind === 'app') {
					this.handleClose()
					return
				}
				this.saved = saved
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.public-channel-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);
}

.public-channel-form__kind {
	border: 0;
	padding: 0;
}

.public-channel-form__hint {
	color: var(--color-text-maxcontrast);
}

.public-channel-form__snippet {
	width: 100%;
	font-family: var(--font-face-monospace, monospace);
	color: var(--color-main-text);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
}

.public-channel-form__snippet-label {
	font-weight: bold;
}

.public-channel-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
