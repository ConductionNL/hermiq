<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AiLabelSection — the "AI label" section of the AI oversight page (board
  hermiq/HmToezicht): how many files and notes an agent wrote that the viewer can
  read, the share a person has marked as reviewed, and a link that opens Files
  filtered on the label.

  The label is shown as "Made with AI" (nl: "Gemaakt met AI"), never as the stored
  tag name, which stays an untranslated identifier (REQ-AILBL-004). The link
  filters on the stored tag by its id.
-->
<template>
	<div class="ai-label-section">
		<div v-if="loading" class="ai-label-section__loading">
			<NcLoadingIcon :size="28" />
		</div>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<p class="ai-label-section__intro">
				{{ t('hermiq', 'Everything an agent writes carries the label {label}.', { label: labelText }) }}
			</p>
			<dl class="ai-label-section__counts">
				<div class="ai-label-section__count">
					<dt>{{ t('hermiq', 'Notes and files') }}</dt>
					<dd data-testid="ai-label-files">
						{{ stats.files }}
					</dd>
				</div>
				<div class="ai-label-section__count">
					<dt>{{ t('hermiq', 'Reviewed afterwards') }}</dt>
					<dd data-testid="ai-label-reviewed">
						{{ stats.reviewedShare }}%
					</dd>
				</div>
			</dl>
			<p v-if="stats.truncated" class="ai-label-section__note">
				{{ t('hermiq', 'Counted over the first {count} labelled files.', { count: stats.files }) }}
			</p>
			<a
				v-if="filesUrl"
				class="ai-label-section__link"
				data-testid="ai-label-files-link"
				:href="filesUrl">
				{{ t('hermiq', 'Show the files labelled {label} in Files', { label: labelText }) }}
			</a>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { aiLabelFilesUrl, getAiLabelStats } from '../api/aiLabel.js'
import { errorMessage } from '../utils/errorMessage.js'

export default {
	name: 'AiLabelSection',
	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			stats: { files: 0, reviewed: 0, reviewedShare: 0, tagId: null, truncated: false },
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The label as people read it, never the stored tag name.
		 *
		 * @return {string}
		 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-hermiq-shows-the-ai-label-as-gemaakt-met-ai-req-ailbl-004
		 */
		labelText() {
			return this.t('hermiq', 'Made with AI')
		},

		/**
		 * The Files view filtered on the stored tag.
		 *
		 * @return {string}
		 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
		 */
		filesUrl() {
			return aiLabelFilesUrl(this.stats.tagId)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the counts for the viewer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.stats = await getAiLabelStats()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Could not load the AI label counts.'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.ai-label-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ai-label-section__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.ai-label-section__intro,
.ai-label-section__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.ai-label-section__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 24px;
	margin: 0;
}

.ai-label-section__count dt {
	color: var(--color-text-maxcontrast);
}

.ai-label-section__count dd {
	margin: 0;
	font-size: 1.5em;
	font-weight: bold;
	color: var(--color-main-text);
}

.ai-label-section__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
