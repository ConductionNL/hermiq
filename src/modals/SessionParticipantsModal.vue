<!--
  SessionParticipantsModal (chat-work-together-in-one-session).

  The owner of a session on /chat invites colleagues into it and takes them off
  again. Every change goes through the owner-only participant routes; the server
  refuses anyone else and a session bound to a Talk room.

  @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
-->
<template>
	<NcModal
		:show="show"
		:name="t('hermiq', 'Invite colleagues')"
		@close="$emit('close')">
		<div class="session-participants">
			<h2>{{ t('hermiq', 'Invite colleagues') }}</h2>
			<p class="session-participants__hint">
				{{
					t(
						'hermiq',
						'People you invite can read this session and ask the agent questions in it. Each question runs with their own files and rights.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcSelect
				v-model="selected"
				:inputLabel="t('hermiq', 'Find a colleague')"
				:options="options"
				:loading="searching"
				:filterable="false"
				label="displayName"
				trackBy="uid"
				@search="search" />
			<NcButton variant="primary" :disabled="!selected || busy" @click="add">
				{{ t('hermiq', 'Invite') }}
			</NcButton>

			<ul class="session-participants__list">
				<li
					v-if="participants.length === 0"
					class="session-participants__hint">
					{{ t('hermiq', 'Only you are in this session.') }}
				</li>
				<li
					v-for="person in participants"
					:key="person.uid"
					class="session-participants__row">
					<span>{{ person.displayName }}</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						@click="remove(person)">
						{{ t('hermiq', 'Remove') }}
					</NcButton>
				</li>
			</ul>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	addParticipant,
	listParticipants,
	removeParticipant,
	searchUsers,
} from '../api/chat.js'

export default {
	name: 'SessionParticipantsModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The session whose participants are managed. */
		session: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			participants: [],
			options: [],
			selected: null,
			searching: false,
			busy: false,
			error: '',
		}
	},

	watch: {
		/**
		 * Load the list each time the dialog opens.
		 *
		 * @param {boolean} open Whether it opened.
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		show(open) {
			if (open) {
				this.load()
			}
		},
	},

	methods: {
		/**
		 * Load the current participants.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		async load() {
			this.error = ''
			this.selected = null
			try {
				this.participants = await listParticipants(this.session.uuid)
			} catch (e) {
				this.error = this.messageFor(e)
			}
		},

		/**
		 * Search users for the select.
		 *
		 * @param {string} text The typed text.
		 * @return {Promise<void>}
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		async search(text) {
			if (!text || text.length < 2) {
				this.options = []
				return
			}
			this.searching = true
			try {
				const found = await searchUsers(text)
				const taken = new Set([
					this.session.userId,
					...this.participants.map((p) => p.uid),
				])
				this.options = found.filter((user) => !taken.has(user.uid))
			} catch {
				this.options = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Invite the selected colleague.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		async add() {
			await this.change(() =>
				addParticipant(this.session.uuid, this.selected.uid),
			)
			this.selected = null
		},

		/**
		 * Take a colleague off the session.
		 *
		 * @param {{uid: string}} person The colleague.
		 * @return {Promise<void>}
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		async remove(person) {
			await this.change(() => removeParticipant(this.session.uuid, person.uid))
		},

		/**
		 * Run one roster change and show the result.
		 *
		 * @param {Function} action The api call.
		 * @return {Promise<void>}
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		async change(action) {
			this.busy = true
			this.error = ''
			try {
				this.participants = await action()
				this.$emit('changed', this.participants)
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * A readable message for a failed call, by its status.
		 *
		 * @param {Error} e The failure.
		 * @return {string}
		 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
		 */
		messageFor(e) {
			const status = e?.response?.status
			if (status === 409) {
				return this.t(
					'hermiq',
					'This session belongs to a Talk room. Invite the person to the room instead.',
				)
			}
			if (status === 400) {
				return this.t('hermiq', 'There is no user with that name.')
			}
			return this.t('hermiq', 'The change could not be saved.')
		},
	},
}
</script>

<style scoped>
.session-participants {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);
}

.session-participants__hint {
	color: var(--color-text-maxcontrast);
}

.session-participants__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.session-participants__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
}
</style>
