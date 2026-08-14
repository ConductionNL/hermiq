<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal :show="show" size="large" :name="heading" @close="$emit('close')">
		<div class="run-log" data-testid="flow-run-log">
			<h2 class="run-log__title">
				{{ heading }}
			</h2>

			<p v-if="!detail" class="run-log__hint">
				{{ t('hermiq', 'Loading this run…') }}
			</p>

			<template v-else>
				<p class="run-log__hint">
					{{
						t('hermiq', 'Status: {status}', {
							status: detail.status || '—',
						})
					}}
				</p>

				<NcNoteCard v-if="detail.error" type="error">
					{{ detail.error }}
				</NcNoteCard>

				<p v-if="log.length === 0" class="run-log__hint">
					{{
						t(
							'hermiq',
							'This run recorded no steps. A run that was refused before it started has no step log — the reason is on the run itself.',
						)
					}}
				</p>

				<ol v-else class="run-log__steps">
					<li
						v-for="(entry, index) in log"
						:key="index"
						class="run-log__step">
						<button
							class="run-log__step-head"
							:aria-expanded="openStep === index ? 'true' : 'false'"
							@click="openStep = openStep === index ? -1 : index">
							<span
								:class="`run-log__status run-log__status--${entry.status || 'unknown'}`">
								{{ entry.status || t('hermiq', 'unknown') }}
							</span>
							<span class="run-log__step-name">{{
								entry.transition || '—'
							}}</span>
							<span class="run-log__step-type">{{
								entry.type || ''
							}}</span>
							<span
								v-if="entry.durationMs !== undefined"
								class="run-log__step-ms">
								{{
									t('hermiq', '{ms} ms', { ms: entry.durationMs })
								}}
							</span>
						</button>

						<div v-if="openStep === index" class="run-log__step-body">
							<NcNoteCard v-if="entry.error" type="error">
								{{ entry.error }}
							</NcNoteCard>
							<p v-if="entry.reason" class="run-log__hint">
								{{ entry.reason }}
							</p>

							<!--
								The provider's own links for this entry: an agent
								step's session, an openconnector call's contract.
								Opened in a NEW TAB — the editor holds unsaved
								state, and navigating away in place would discard
								an author's in-progress flow to show them a record
								they wanted to glance at.
							-->
							<div
								v-if="(actions[index] || []).length > 0"
								class="run-log__actions">
								<a
									v-for="action in actions[index]"
									:key="action.href"
									:href="action.href"
									target="_blank"
									rel="noopener noreferrer"
									class="run-log__action">
									{{ action.label }}
								</a>
							</div>

							<template v-if="entry.input">
								<h3 class="run-log__subtitle">
									{{ t('hermiq', 'Received') }}
									<span
										v-if="entry.input.truncated"
										class="run-log__sampled">
										{{
											t('hermiq', 'sample of {count}', {
												count: entry.input.count,
											})
										}}
									</span>
								</h3>
								<pre class="run-log__json">{{
									pretty(entry.input)
								}}</pre>
							</template>

							<template v-if="entry.output">
								<h3 class="run-log__subtitle">
									{{ t('hermiq', 'Returned') }}
									<span
										v-if="entry.output.truncated"
										class="run-log__sampled">
										{{
											t('hermiq', 'sample of {count}', {
												count: entry.output.count,
											})
										}}
									</span>
								</h3>
								<pre class="run-log__json">{{
									pretty(entry.output)
								}}</pre>
							</template>

							<p
								v-if="!entry.input && !entry.output"
								class="run-log__hint">
								{{
									t(
										'hermiq',
										'This step recorded no payload. Steps that stopped, suspended or failed before running record their reason instead.',
									)
								}}
							</p>
						</div>
					</li>
				</ol>
			</template>

			<div class="run-log__footer">
				<NcButton type="primary" @click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { useFlowEditorStore } from '../../store/flowEditor.js'

/**
 * RunLogModal — one run, every step, what each received and returned.
 *
 * The Runs tab shows the same log inline, but a sidebar 346px wide cannot hold
 * a JSON payload: the pane is narrower than most single lines of it. This is
 * where a run is actually read.
 *
 * @spec openspec/specs/flow-canvas/spec.md#requirement-selecting-a-run-replays-its-path-on-the-canvas
 */
export default {
	name: 'RunLogModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
	},

	props: {
		/** Whether the modal is open. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			// Which step is folded open. -1 is none: 0 is a valid index, so
			// `null`-vs-`0` truthiness would open the first step by accident.
			openStep: -1,
			// Provider links per step index, fetched from the engine.
			actions: {},
		}
	},

	computed: {
		/**
		 * The run being read.
		 *
		 * @return {object|null} The run detail.
		 */
		detail() {
			const id = this.editor.logModalRunId

			return id === null ? null : this.editor.runDetail[id] || null
		},

		/**
		 * The run's step entries.
		 *
		 * @return {Array<object>} The log.
		 */
		log() {
			return this.detail?.log || []
		},

		/**
		 * The modal heading.
		 *
		 * @return {string} The heading.
		 */
		heading() {
			return this.t('hermiq', 'Run log')
		},
	},

	watch: {
		/**
		 * Fetch the provider links whenever a different run is opened.
		 *
		 * @return {void}
		 */
		log() {
			this.actions = {}
			this.loadActions()
		},
	},

	methods: {
		t,

		/**
		 * Format a payload envelope, showing the items rather than the
		 * bookkeeping — `count`/`truncated` are stated in words beside the
		 * heading.
		 *
		 * @param {object} envelope The `{count, truncated, items}` envelope.
		 * @return {string} Pretty JSON.
		 */
		pretty(envelope) {
			return JSON.stringify(envelope?.items ?? envelope, null, 2)
		},

		/**
		 * Ask each node for the links its own log entry earns.
		 *
		 * The ENGINE answers, by asking the node that wrote the entry — so an
		 * openconnector call offers its source and call log, and hermiq's
		 * editor needs to know nothing about either. This replaces a hard-coded
		 * agent-session case that only this app could ever have extended.
		 *
		 * One request per step, and deliberately: a log is opened rarely, the
		 * result is small, and batching would need an endpoint shape that the
		 * per-entry contract does not have. If a long log ever makes this
		 * noticeable, the fix is a batch endpoint, not a cache that goes stale
		 * against a provider whose routes moved.
		 *
		 * A failure yields no links rather than an error: this decorates a log
		 * an operator is already reading.
		 *
		 * @return {Promise<void>}
		 */
		async loadActions() {
			const found = {}
			await Promise.all(
				this.log.map(async (entry, index) => {
					try {
						const { data } = await axios.post(
							generateUrl('/apps/openregister/api/flow/log-actions'),
							{ entry },
						)
						found[index] = data?.results || []
					} catch (e) {
						found[index] = []
					}
				}),
			)
			this.actions = found
		},
	},
}
</script>

<style scoped>
.run-log {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.run-log__title,
.run-log__subtitle {
	margin: 0;
}

.run-log__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.run-log__steps {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 55vh;
	overflow-y: auto;
}

.run-log__step-head {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
}

.run-log__step-head:hover,
.run-log__step-head:focus-visible {
	background-color: var(--color-background-hover);
}

/* Status as the WORD as well as the colour (WCAG 2.1 AA 1.4.1). */
.run-log__status {
	font-weight: bold;
}

.run-log__status--failed,
.run-log__status--dead_letter {
	color: var(--color-error);
}

.run-log__status--completed {
	color: var(--color-success);
}

.run-log__status--suspended,
.run-log__status--stopped {
	color: var(--color-warning-text, var(--color-main-text));
}

.run-log__step-type,
.run-log__step-ms {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.run-log__step-ms {
	margin-inline-start: auto;
}

.run-log__step-body {
	padding: 8px 10px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.run-log__sampled {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
	font-weight: normal;
}

.run-log__json {
	max-height: 30vh;
	overflow: auto;
	padding: 10px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
	font-family: monospace;
	font-size: 0.85em;
	white-space: pre;
}

.run-log__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.run-log__footer {
	display: flex;
	justify-content: flex-end;
}
</style>
