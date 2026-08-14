<!--
  - SPDX-License-Identifier: EUPL-1.2
  - Copyright (C) 2026 Conduction B.V.
  -
  - Admin panel for the Talk chat bridge (talk-chat-bridge).
  -
  - Answers the question an administrator actually asks when an agent starts
  - replying in a room: why is this happening, and where else does it happen?
  - Before this the answer lived in an app-config JSON blob and spreed's bot
  - tables, reachable only by occ or SQL.
  -
  - Renders on a Talk-less instance too — it says so plainly instead of
  - erroring, because Talk is an optional runtime dependency.
-->
<template>
	<div class="talk-bridge-settings">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<NcNoteCard v-if="!status.talkAvailable" type="warning">
				{{
					t(
						'hermiq',
						'Nextcloud Talk is not installed on this instance, so agents cannot be reached from a conversation. Everything else in Hermiq is unaffected.',
					)
				}}
			</NcNoteCard>

			<template v-else>
				<NcNoteCard v-if="!status.botInstalled" type="warning">
					{{
						t(
							'hermiq',
							'The Hermiq bot is not registered with Talk. It is normally registered automatically when the app is installed or upgraded.',
						)
					}}
				</NcNoteCard>
				<NcNoteCard v-else type="success">
					{{
						t(
							'hermiq',
							'The Hermiq bot is registered. It answers only in conversations where a moderator has enabled it AND an opted-in agent is bound below.',
						)
					}}
				</NcNoteCard>

				<dl class="talk-bridge-settings__facts">
					<dt>{{ t('hermiq', 'Bot address') }}</dt>
					<dd>
						<code>{{ status.botUrl }}</code>
					</dd>

					<dt>{{ t('hermiq', 'Reply delivery') }}</dt>
					<dd>
						<span v-if="status.handOffPath === 'triggered'">
							{{
								t(
									'hermiq',
									'Immediate — a runner picks each turn up as it arrives.',
								)
							}}
						</span>
						<span v-else>
							{{
								t(
									'hermiq',
									'Background jobs — replies arrive on the next background-job run, so how quickly people get an answer depends on how often this instance runs them.',
								)
							}}
						</span>
					</dd>

					<dt>{{ t('hermiq', 'Sidebar grouping') }}</dt>
					<dd>
						{{
							status.groupingEnabled
								? t(
										'hermiq',
										'Agent rooms are filed under a personal “Hermiq” tag for each participant.',
									)
								: t(
										'hermiq',
										'Unavailable — this version of Talk does not support conversation tags.',
									)
						}}
					</dd>
				</dl>

				<h4>{{ t('hermiq', 'Conversations') }}</h4>

				<NcNoteCard v-if="!status.rooms.length" type="info">
					{{
						t(
							'hermiq',
							'No conversation is bound to an agent yet. Bind one below using its Talk conversation token.',
						)
					}}
				</NcNoteCard>

				<table v-else class="talk-bridge-settings__rooms">
					<thead>
						<tr>
							<th scope="col">{{ t('hermiq', 'Conversation') }}</th>
							<th scope="col">{{ t('hermiq', 'Agent') }}</th>
							<th scope="col">{{ t('hermiq', 'Status') }}</th>
							<th scope="col">
								<span class="hidden-visually">{{
									t('hermiq', 'Actions')
								}}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="room in status.rooms" :key="room.token">
							<td>
								{{ room.name }}<br /><code>{{ room.token }}</code>
							</td>
							<td>{{ room.agentName || room.agentId }}</td>
							<td>
								<span
									v-if="!room.botEnabled"
									class="talk-bridge-settings__warn">
									{{
										t(
											'hermiq',
											'The bot is not enabled in this conversation, so nothing will answer here.',
										)
									}}
								</span>
								<span
									v-else-if="!room.agentActive"
									class="talk-bridge-settings__warn">
									{{
										t(
											'hermiq',
											'The bound agent is missing or has not opted in to Talk, so nothing will answer here.',
										)
									}}
								</span>
								<span v-else class="talk-bridge-settings__ok">
									{{ t('hermiq', 'Answering') }}
								</span>
							</td>
							<td>
								<NcButton
									type="tertiary"
									:disabled="saving"
									@click="unbind(room.token)">
									{{ t('hermiq', 'Unbind') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="talk-bridge-settings__bind">
					<NcTextField
						:value.sync="newToken"
						:label="t('hermiq', 'Talk conversation token')"
						:placeholder="t('hermiq', 'e.g. a1b2c3d4')" />
					<NcTextField
						:value.sync="newAgentId"
						:label="t('hermiq', 'Agent UUID')"
						:placeholder="
							t('hermiq', 'The agent to answer in that conversation')
						" />
					<NcButton type="primary" :disabled="!canBind" @click="bind">
						{{ t('hermiq', 'Bind') }}
					</NcButton>
				</div>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { bindTalkRoom, getTalkBridgeStatus } from '../../api/talkBridge.js'

export default {
	name: 'TalkBridgeSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			newToken: '',
			newAgentId: '',
			status: {
				talkAvailable: false,
				botUrl: '',
				botInstalled: false,
				groupingEnabled: false,
				handOffPath: 'queued',
				rooms: [],
			},
		}
	},

	computed: {
		/**
		 * Whether the bind form is complete.
		 *
		 * @return {boolean} True when both fields are filled and no save is in flight.
		 */
		canBind() {
			return (
				this.newToken.trim() !== ''
				&& this.newAgentId.trim() !== ''
				&& !this.saving
			)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the bridge's effective configuration.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.status = await getTalkBridgeStatus()
			} catch (e) {
				this.error = this.t(
					'hermiq',
					'Could not read the Talk bridge configuration.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Bind the entered room to the entered agent.
		 *
		 * @return {Promise<void>}
		 */
		async bind() {
			this.saving = true
			this.error = ''
			try {
				this.status = await bindTalkRoom(
					this.newToken.trim(),
					this.newAgentId.trim(),
				)
				this.newToken = ''
				this.newAgentId = ''
			} catch (e) {
				this.error = this.t('hermiq', 'Could not bind that conversation.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Remove a room's binding.
		 *
		 * @param {string} token The Talk room token.
		 * @return {Promise<void>}
		 */
		async unbind(token) {
			this.saving = true
			this.error = ''
			try {
				this.status = await bindTalkRoom(token, '')
			} catch (e) {
				this.error = this.t('hermiq', 'Could not remove that binding.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.talk-bridge-settings__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 12px 0;
}

.talk-bridge-settings__facts dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.talk-bridge-settings__facts dd {
	margin: 0;
}

.talk-bridge-settings__rooms {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.talk-bridge-settings__rooms th,
.talk-bridge-settings__rooms td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.talk-bridge-settings__warn {
	color: var(--color-warning-text, var(--color-warning));
}

.talk-bridge-settings__ok {
	color: var(--color-success-text, var(--color-success));
}

.talk-bridge-settings__bind {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}
</style>
