<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  DraftReviewModal (oversight-ask-and-hold): read, edit and release what an
  agent wrote before it reaches people. The recipients are shown read-only;
  only the text (a report's output, or a mail's subject and body) can be
  edited. "Release" sends it as written, "Edit and release" sends the edit,
  and "Discard" hands over to the deny dialog for a reason.

  Own file per ADR-004 modal-isolation. The parent ApprovalInbox opens it for
  one draft and reloads on `released`.

  @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="t('hermiq', 'Review draft')"
		@close="$emit('close')">
		<div class="draft-review" data-testid="draft-review">
			<h2 class="draft-review__title">
				{{ t('hermiq', 'Review draft') }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not release the draft')">
				{{ error }}
			</NcNoteCard>

			<dl class="draft-review__recipients" data-testid="draft-review-recipients">
				<dt>{{ t('hermiq', 'Goes to') }}</dt>
				<dd>{{ recipients }}</dd>
			</dl>

			<NcTextField
				v-if="isMail"
				v-model="subject"
				:label="t('hermiq', 'Subject')"
				:disabled="saving" />
			<NcTextArea
				v-model="text"
				:label="isMail ? t('hermiq', 'Message') : t('hermiq', 'Text')"
				:disabled="saving"
				resize="vertical"
				rows="10" />

			<div class="draft-review__actions">
				<NcButton
					variant="error"
					:disabled="saving"
					data-testid="draft-review-discard"
					@click="$emit('discard', draft)">
					{{ t('hermiq', 'Discard') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="saving || edited"
					data-testid="draft-review-release"
					@click="release(false)">
					{{ t('hermiq', 'Release') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || !edited"
					data-testid="draft-review-edit-release"
					@click="release(true)">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Edit and release') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { releaseApproval } from '../api/approvals.js'
import { errorMessage } from '../utils/errorMessage.js'

/**
 * The editable text of a held draft: `{subject, text}`.
 *
 * @param {object|null} held The approval's `draft` record.
 * @return {{subject: string, text: string, mail: boolean}} The fields.
 */
export function draftFields(held) {
	if (held?.kind === 'tool') {
		const args = held.arguments || {}
		return {
			subject: String(args.subject ?? ''),
			text: String(args.body ?? args.message ?? ''),
			mail: Object.hasOwn(args, 'subject'),
		}
	}
	return { subject: '', text: String(held?.output ?? ''), mail: false }
}

/**
 * The edits a release sends: only the fields that changed, under the names the
 * server knows (`output` for a report, `subject`/`body` or `message` for a tool).
 *
 * @param {object|null} held The approval's `draft` record.
 * @param {{subject: string, text: string}} now The edited fields.
 * @return {object} The edits; empty when nothing changed.
 */
export function draftEdits(held, now) {
	const before = draftFields(held)
	const edits = {}
	if (held?.kind === 'tool') {
		const args = held.arguments || {}
		const textField = Object.hasOwn(args, 'body') || !Object.hasOwn(args, 'message') ? 'body' : 'message'
		if (now.text !== before.text) {
			edits[textField] = now.text
		}
		if (before.mail && now.subject !== before.subject) {
			edits.subject = now.subject
		}
		return edits
	}
	if (now.text !== before.text) {
		edits.output = now.text
	}
	return edits
}

export default {
	name: 'DraftReviewModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The pending draft approval (an inbox record with `draft`), or null. */
		draft: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'released', 'discard'],

	data() {
		return {
			subject: '',
			text: '',
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the draft is a mail with a subject.
		 *
		 * @return {boolean}
		 */
		isMail() {
			return draftFields(this.draft?.draft).mail
		},

		/**
		 * Who the draft goes to, read-only: the mail's recipient, or the channel.
		 *
		 * @return {string}
		 */
		recipients() {
			const held = this.draft?.draft || {}
			if (held.kind === 'tool') {
				const args = held.arguments || {}
				return String(args.to || args.room || args.recipient || held.toolId || '—')
			}
			return String(held.channel || '—')
		},

		/**
		 * Whether the reviewer changed the text.
		 *
		 * @return {boolean}
		 */
		edited() {
			return Object.keys(draftEdits(this.draft?.draft, { subject: this.subject, text: this.text })).length > 0
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Start from the agent's text each time the dialog opens.
			 *
			 * @param {boolean} open Whether the dialog opened.
			 * @return {void}
			 */
			handler(open) {
				if (open) {
					const fields = draftFields(this.draft?.draft)
					this.subject = fields.subject
					this.text = fields.text
					this.error = ''
				}
			},
		},
	},

	methods: {
		/**
		 * Release the draft as written, or with the edits.
		 *
		 * @param {boolean} withEdits Whether to send the edits.
		 * @return {Promise<void>}
		 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
		 */
		async release(withEdits) {
			if (!this.draft) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const edits = withEdits
					? draftEdits(this.draft.draft, { subject: this.subject, text: this.text })
					: {}
				await releaseApproval(this.draft.id, edits)
				this.$emit('released', this.draft)
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.draft-review {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.draft-review__title {
	margin: 0;
	font-size: 20px;
}

.draft-review__recipients {
	display: flex;
	gap: 8px;
	margin: 0;
}

.draft-review__recipients dt {
	font-weight: bold;
}

.draft-review__recipients dd {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.draft-review__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
