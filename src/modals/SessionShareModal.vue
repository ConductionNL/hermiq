<!--
  SessionShareModal (chat-fork-and-share-link).

  The owner of a session shares it read-only with colleagues and groups, takes
  a share back, and copies the link to the session's read-only page. Every
  change goes through the owner-only share routes; the server applies the
  instance's sharing settings and refuses anyone but the owner. The search is
  Nextcloud's collaborator search, so it only offers people the owner may
  share with. There is no public link.

  @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
-->
<template>
	<NcModal
		:show="show"
		:name="t('hermiq', 'Share session')"
		@close="$emit('close')">
		<div class="session-share" data-testid="session-share-modal">
			<h2>{{ t('hermiq', 'Share session') }}</h2>
			<p class="session-share__hint">
				{{ t('hermiq', 'People you share with can read this session. They cannot add to it or change it.') }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcSelect
				v-model="selected"
				:inputLabel="t('hermiq', 'Find a colleague or group')"
				:options="options"
				:loading="searching"
				:filterable="false"
				label="displayName"
				trackBy="key"
				@search="search" />
			<NcButton
				variant="primary"
				data-testid="session-share-add"
				:disabled="!selected || busy"
				@click="add">
				{{ t('hermiq', 'Share') }}
			</NcButton>

			<ul class="session-share__list">
				<li v-if="shares.length === 0" class="session-share__hint">
					{{ t('hermiq', 'This session is not shared.') }}
				</li>
				<li
					v-for="share in shares"
					:key="share.key"
					class="session-share__row"
					data-testid="session-share-row">
					<span class="session-share__who">
						<span>{{ share.displayName }}</span>
						<span v-if="share.type === 'group'" class="session-share__hint">
							{{ t('hermiq', 'Everyone in this group, now and later') }}
						</span>
					</span>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						@click="remove(share)">
						{{ t('hermiq', 'Remove') }}
					</NcButton>
				</li>
			</ul>

			<div class="session-share__link">
				<NcButton
					variant="secondary"
					data-testid="session-share-copy-link"
					@click="copyLink">
					<template #icon>
						<LinkVariant :size="20" />
					</template>
					{{ t('hermiq', 'Copy link') }}
				</NcButton>
				<p class="session-share__hint">
					{{ t('hermiq', 'The link opens the session only for the people it is shared with, after they sign in.') }}
				</p>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import { addShare, listShares, removeShare, searchSharees } from '../api/chat.js'
import { openShareOptions, sharedSessionLink, shareKey } from '../utils/sessionShare.js'

export default {
	name: 'SessionShareModal',

	components: {
		LinkVariant,
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

		/** The session whose shares are managed. */
		session: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			shares: [],
			options: [],
			selected: null,
			searching: false,
			busy: false,
			error: '',
		}
	},

	watch: {
		/**
		 * Load the shares each time the dialog opens.
		 *
		 * @param {boolean} open Whether it opened.
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
		 */
		show(open) {
			if (open) {
				this.load()
			}
		},
	},

	methods: {
		/**
		 * Load the current shares.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
		 */
		async load() {
			this.error = ''
			this.selected = null
			try {
				this.shares = this.keyed(await listShares(this.session.uuid))
			} catch (e) {
				this.error = this.messageFor(e)
			}
		},

		/**
		 * Search colleagues and groups for the select.
		 *
		 * @param {string} text The typed text.
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
		 */
		async search(text) {
			if (!text || text.length < 2) {
				this.options = []
				return
			}
			this.searching = true
			try {
				const found = await searchSharees(text)
				this.options = this.keyed(openShareOptions(found, this.shares, this.session.userId))
			} catch {
				this.options = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Share with the selected colleague or group.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
		 */
		async add() {
			const { type, id } = this.selected
			await this.change(() => addShare(this.session.uuid, type, id))
			this.selected = null
		},

		/**
		 * Take a share back; the recipient loses access at once.
		 *
		 * @param {{type: string, id: string}} share The share.
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
		 */
		async remove(share) {
			await this.change(() => removeShare(this.session.uuid, share.type, share.id))
		},

		/**
		 * Run one share change and show the result.
		 *
		 * @param {Function} action The api call.
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
		 */
		async change(action) {
			this.busy = true
			this.error = ''
			try {
				this.shares = this.keyed(await action())
				this.$emit('changed', this.shares)
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the link to the session's read-only page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-copy-a-link-to-a-shared-session-req-sshare-004
		 */
		async copyLink() {
			const link = sharedSessionLink(window.location.origin, window.OC?.webroot || '', this.session.uuid)
			try {
				await navigator.clipboard.writeText(link)
				showSuccess(this.t('hermiq', 'Link copied'))
			} catch {
				showError(this.t('hermiq', 'The link could not be copied.'))
			}
		},

		/**
		 * The entries with a stable key for lists and the select.
		 *
		 * @param {Array<{type: string, id: string}>} entries Shares or options.
		 * @return {Array<object>}
		 */
		keyed(entries) {
			return entries.map((entry) => ({ ...entry, key: shareKey(entry) }))
		},

		/**
		 * A readable message for a failed call, by the server's answer.
		 *
		 * @param {Error} e The failure.
		 * @return {string}
		 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
		 */
		messageFor(e) {
			const known = {
				'You can only share with members of your groups.': this.t('hermiq', 'You can only share with members of your groups.'),
				'You can only share with groups you are a member of.': this.t('hermiq', 'You can only share with groups you are a member of.'),
				'Sharing with groups is switched off.': this.t('hermiq', 'Sharing with groups is switched off.'),
				'Sharing is switched off for your account.': this.t('hermiq', 'Sharing is switched off for your account.'),
				'There is no user with that name.': this.t('hermiq', 'There is no user with that name.'),
				'There is no group with that name.': this.t('hermiq', 'There is no group with that name.'),
			}
			const message = e?.response?.data?.error
			if (message && known[message]) {
				return known[message]
			}
			return this.t('hermiq', 'The change could not be saved.')
		},
	},
}
</script>

<style scoped>
.session-share {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 5);
}

.session-share__hint {
	color: var(--color-text-maxcontrast);
}

.session-share__list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.session-share__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.session-share__who {
	display: flex;
	flex-direction: column;
}

.session-share__link {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	border-top: 1px solid var(--color-border);
	padding-top: calc(var(--default-grid-baseline) * 3);
}
</style>
