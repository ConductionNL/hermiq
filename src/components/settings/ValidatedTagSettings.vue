<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ValidatedTagSettings — which restricted Nextcloud tag marks a document as
  validated (memory-retrieval-scope D2).

  Hermiq keeps no validated status of its own: a document is validated when it
  carries this system tag, visible on the file in Files, and who may assign the tag
  is Nextcloud's own tag permission, set here as the groups that may assign it. An
  agent in "Answer only from validated documents" mode searches only tagged files.

  Creating a tag whose name already exists takes that tag instead of making a
  second one, so the same button also chooses an existing tag by name.

  All strings via t(); server data comes from the admin-gated API, never a DOM read.

  @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
-->
<template>
	<div class="validated-tag" data-testid="validated-tag-settings">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Validated tag error')">
			{{ error }}
		</NcNoteCard>

		<p class="validated-tag__current" data-testid="validated-tag-current">
			<span v-if="tagId">
				{{
					t('hermiq', 'Validated documents carry the tag “{name}”.', {
						name: tagName || tagId,
					})
				}}
			</span>
			<span v-else>
				{{
					t(
						'hermiq',
						'No validated tag is set. Agents in validated mode find no documents until one is.',
					)
				}}
			</span>
		</p>

		<NcTextField
			v-if="!tagId"
			v-model="newName"
			data-testid="validated-tag-name"
			:label="t('hermiq', 'Tag name')"
			:disabled="loading || busy" />

		<NcSelect
			v-model="groups"
			data-testid="validated-tag-groups"
			:inputLabel="t('hermiq', 'Groups that may mark a document as validated')"
			:options="groupOptions"
			:multiple="true"
			:filterable="false"
			:disabled="loading || busy"
			label="label"
			trackBy="value"
			@search="onSearchGroups" />

		<div class="validated-tag__actions">
			<NcButton
				v-if="!tagId"
				variant="primary"
				data-testid="validated-tag-create"
				:disabled="loading || busy || !newName.trim()"
				@click="create">
				{{ t('hermiq', 'Create the Validated tag') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				data-testid="validated-tag-save"
				:disabled="loading || busy"
				@click="save">
				{{ t('hermiq', 'Save who may assign it') }}
			</NcButton>
		</div>

		<p class="validated-tag__note">
			{{
				t(
					'hermiq',
					'The tag is restricted: everyone sees it on a file, only these groups (and administrators) can assign it in Files.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { searchGroups } from '../../api/chat.js'
import {
	createValidatedTag,
	getValidatedTag,
	updateValidatedTag,
} from '../../api/validatedTag.js'
import { settingsCall } from './settingsCall.js'

/**
 * Group ids as picker options.
 *
 * @param {Array<string>|null|undefined} ids The ids.
 * @return {Array<{label: string, value: string}>} The options.
 */
function groupIdOptions(ids) {
	return (Array.isArray(ids) ? ids : []).map((id) => ({ label: id, value: id }))
}

export default {
	name: 'ValidatedTagSettings',

	components: {
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [settingsCall],

	data() {
		return {
			tagId: null,
			tagName: null,
			newName: translate('hermiq', 'Validated'),
			groups: [],
			groupOptions: [],
		}
	},

	methods: {
		/**
		 * Show a setting the server answered with.
		 *
		 * @param {{tagId: ?string, name: ?string, groups: Array<string>}} data The setting.
		 * @return {void}
		 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
		 */
		apply(data) {
			this.tagId = data?.tagId || null
			this.tagName = data?.name || null
			this.groups = groupIdOptions(data?.groups)
		},

		/**
		 * Read the setting.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
		 */
		async load() {
			await this.withFlag('loading', async () => {
				this.apply(await getValidatedTag())
			})
		},

		/**
		 * Create the restricted tag, assignable by the chosen groups, and name it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#scenario-an-administrator-sets-up-validation
		 */
		async create() {
			await this.withFlag('busy', async () => {
				this.apply(
					await createValidatedTag(
						this.newName.trim(),
						this.groups.map((option) => option.value),
					),
				)
			})
		},

		/**
		 * Save which groups may assign the named tag.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
		 */
		async save() {
			await this.withFlag('busy', async () => {
				this.apply(
					await updateValidatedTag(
						this.tagId,
						this.groups.map((option) => option.value),
					),
				)
			})
		},

		/**
		 * Offer the groups matching the typed text.
		 *
		 * @param {string} search The typed text.
		 * @return {Promise<void>}
		 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
		 */
		async onSearchGroups(search) {
			if (!search) {
				return
			}
			try {
				this.groupOptions = (await searchGroups(search)).map((group) => ({
					label: group.displayName,
					value: group.gid,
				}))
			} catch {
				this.groupOptions = []
			}
		},
	},
}
</script>

<style scoped>
.validated-tag {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.validated-tag__current {
	margin: 0;
}

.validated-tag__note {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
