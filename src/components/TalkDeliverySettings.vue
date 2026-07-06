<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  TalkDeliverySettings — per-user default delivery room for scheduled runs.

  Talk delivery is user-specific (each person's own room), so it lives in Personal
  settings rather than the app-wide setup wizard. The user picks a room they are in
  (type to search) or creates a new one; the choice is stored as the per-user
  `delivertarget` preference. Empty = deliver privately to Note to self.
-->
<template>
	<div class="talk-delivery">
		<p class="talk-delivery__text">
			{{ t('hermiq', 'Scheduled runs deliver their results to a Nextcloud Talk room. Pick a room you are in, or create one. Leave as “Note to self” to deliver privately to yourself.') }}
		</p>
		<NcSelect
			class="talk-delivery__select"
			:input-label="t('hermiq', 'Default delivery room')"
			:options="options"
			:value="selected"
			:loading="loading"
			:clearable="false"
			label="name"
			:placeholder="t('hermiq', 'Search your rooms…')"
			@input="onSelect" />
		<div class="talk-delivery__new">
			<NcTextField
				class="talk-delivery__new-name"
				:value.sync="newName"
				:label="t('hermiq', 'Create a new room')"
				:placeholder="t('hermiq', 'e.g. Hermiq')" />
			<NcButton
				type="secondary"
				:disabled="creating || newName.trim() === ''"
				@click="onCreate">
				<template v-if="creating" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('hermiq', 'New room') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="saved" type="success">
			{{ savedMessage }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { createRoom, getDeliveryTarget, listRooms, setDeliveryTarget } from '../api/talk.js'

/** The sentinel "no room → Note to self" option. */
const NOTE_TO_SELF = { token: '', name: '' }

export default {
	name: 'TalkDeliverySettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			rooms: [],
			selected: null,
			newName: '',
			loading: true,
			creating: false,
			saved: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The picker options: "Note to self" first, then the user's rooms.
		 *
		 * @return {Array<{token: string, name: string}>} Options for NcSelect.
		 */
		options() {
			return [{ token: '', name: this.t('hermiq', 'Note to self (private)') }, ...this.rooms]
		},

		/**
		 * Confirmation line for the current selection.
		 *
		 * @return {string} The saved-state message.
		 */
		savedMessage() {
			const token = this.selected && this.selected.token
			if (!token) {
				return this.t('hermiq', 'Saved — runs deliver to your Note to self.')
			}
			return this.t('hermiq', 'Saved — runs deliver to “{room}”.', { room: this.selected.name })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the user's rooms and current default in parallel; fail soft.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const [rooms, target] = await Promise.all([listRooms(), getDeliveryTarget()])
				this.rooms = rooms
				this.selected = this.options.find((o) => o.token === target) || NOTE_TO_SELF
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || this.t('hermiq', 'Could not load your Talk rooms')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist a newly-picked room as the default delivery target.
		 *
		 * @param {{token: string, name: string}} option The chosen option.
		 * @return {Promise<void>}
		 */
		async onSelect(option) {
			this.selected = option || NOTE_TO_SELF
			await this.save(this.selected.token)
		},

		/**
		 * Create a room, add it to the list, and make it the default.
		 *
		 * @return {Promise<void>}
		 */
		async onCreate() {
			this.creating = true
			this.error = ''
			try {
				const room = await createRoom(this.newName.trim())
				this.rooms = [room, ...this.rooms]
				this.selected = room
				this.newName = ''
				await this.save(room.token)
			} catch (e) {
				this.error = e?.response?.data?.ocs?.meta?.message || e?.message || this.t('hermiq', 'Could not create the room')
			} finally {
				this.creating = false
			}
		},

		/**
		 * Store the delivery target, surfacing a transient success note.
		 *
		 * @param {string} token The room token (`''` clears to Note to self).
		 * @return {Promise<void>}
		 */
		async save(token) {
			this.error = ''
			try {
				await setDeliveryTarget(token)
				this.saved = true
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || this.t('hermiq', 'Could not save')
			}
		},
	},
}
</script>

<style scoped>
.talk-delivery__text {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
	max-width: 560px;
}

.talk-delivery__select {
	max-width: 360px;
}

.talk-delivery__new {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.talk-delivery__new-name {
	max-width: 260px;
}
</style>
