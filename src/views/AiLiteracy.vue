<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AiLiteracy: the course Working with AI (compliance-ai-literacy).

  Six short lessons, each ending with a check question. A right answer records
  the lesson as done for its current version; a wrong one explains why and lets
  the person try again. An organisation admin also sees who finished the course,
  can export that as CSV, and can require the course before first use.

  @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
  @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
-->
<template>
	<div class="ai-literacy" data-walkthrough-id="ai-literacy-page">
		<h2 class="ai-literacy__heading">
			{{ t('hermiq', 'Working with AI') }}
		</h2>
		<p class="ai-literacy__intro">
			{{
				t(
					'hermiq',
					'Six short lessons on working with AI agents. Each takes a few minutes and ends with one question.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<p class="ai-literacy__progress" data-testid="ai-literacy-progress">
				{{ t('hermiq', '{done} of {total} done', { done, total }) }}
			</p>
			<NcNoteCard v-if="required && done < total" type="info">
				{{
					t(
						'hermiq',
						'Your organisation asks you to finish this course before you use an agent.',
					)
				}}
			</NcNoteCard>

			<ol class="ai-literacy__lessons">
				<li
					v-for="lesson in lessons"
					:key="lesson.slug"
					class="ai-literacy__lesson"
					:data-testid="`ai-literacy-lesson-${lesson.slug}`">
					<button
						type="button"
						class="ai-literacy__lesson-head"
						:aria-expanded="openSlug === lesson.slug ? 'true' : 'false'"
						@click="toggle(lesson.slug)">
						<span class="ai-literacy__lesson-title">{{
							lesson.title
						}}</span>
						<span
							class="ai-literacy__lesson-state"
							:class="{
								'ai-literacy__lesson-state--done': lesson.done,
							}">
							{{
								lesson.done
									? t('hermiq', 'Done')
									: t('hermiq', 'Not done')
							}}
						</span>
					</button>

					<div
						v-if="openSlug === lesson.slug"
						class="ai-literacy__lesson-body">
						<p
							v-for="(paragraph, index) in paragraphs(lesson.body)"
							:key="index">
							{{ paragraph }}
						</p>
						<fieldset class="ai-literacy__check">
							<legend>{{ lesson.checkQuestion }}</legend>
							<NcCheckboxRadioSwitch
								v-for="(option, index) in lesson.checkOptions"
								:key="index"
								v-model="choice"
								type="radio"
								:value="String(index)"
								:name="`check-${lesson.slug}`">
								{{ option }}
							</NcCheckboxRadioSwitch>
						</fieldset>
						<NcButton
							variant="primary"
							:disabled="choice === null || checking"
							@click="check(lesson)">
							{{ t('hermiq', 'Check answer') }}
						</NcButton>
						<NcNoteCard
							v-if="feedback"
							:type="feedback.correct ? 'success' : 'warning'">
							{{ feedback.text }}
						</NcNoteCard>
					</div>
				</li>
			</ol>

			<section v-if="mayAdminister" class="ai-literacy__admin">
				<h3>{{ t('hermiq', 'Your organisation') }}</h3>
				<NcCheckboxRadioSwitch
					:modelValue="required"
					type="switch"
					@update:modelValue="toggleRequired">
					{{ t('hermiq', 'Require the AI course before first use') }}
				</NcCheckboxRadioSwitch>
				<table v-if="people.length > 0" class="ai-literacy__people">
					<thead>
						<tr>
							<th scope="col">{{ t('hermiq', 'Person') }}</th>
							<th scope="col">{{ t('hermiq', 'Lessons done') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="person in people" :key="person.userId">
							<td>{{ person.userId }}</td>
							<td>
								{{
									t('hermiq', '{done} of {total} done', {
										done: person.done,
										total: person.total,
									})
								}}
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="ai-literacy__intro">
					{{
						t(
							'hermiq',
							'Nobody in your organisation has done a lesson yet.',
						)
					}}
				</p>
				<a :href="csvUrl" download>{{ t('hermiq', 'Download as CSV') }}</a>
			</section>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import {
	answerLesson,
	getLessons,
	getOverview,
	overviewCsvUrl,
	setRequirement,
} from '../api/literacy.js'

export default {
	name: 'AiLiteracy',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			error: '',
			lessons: [],
			done: 0,
			total: 0,
			required: false,
			mayAdminister: false,
			people: [],
			openSlug: null,
			choice: null,
			checking: false,
			feedback: null,
			csvUrl: overviewCsvUrl(),
		}
	},

	/**
	 * Load the lessons when the page opens.
	 *
	 * @return {Promise<void>}
	 *
	 * @spec exclude Trivial lifecycle hook delegating to load(); no behavioural spec.
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Load the lessons, and the organisation report for an admin.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
		 */
		async load() {
			try {
				const page = await getLessons()
				this.lessons = page.lessons || []
				this.done = page.done || 0
				this.total = page.total || 0
				this.required = page.required === true
				this.mayAdminister = page.mayAdminister === true
				if (this.mayAdminister) {
					this.people = (await getOverview()).people || []
				}
			} catch {
				this.error = this.t('hermiq', 'The course could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open or close a lesson.
		 *
		 * @param {string} slug The lesson.
		 * @return {void}
		 *
		 * @spec exclude Trivial display toggle; no behavioural spec.
		 */
		toggle(slug) {
			this.openSlug = this.openSlug === slug ? null : slug
			this.choice = null
			this.feedback = null
		},

		/**
		 * The lesson text as paragraphs.
		 *
		 * @param {string} body The lesson text.
		 * @return {Array<string>} The paragraphs.
		 *
		 * @spec exclude Trivial display helper; no behavioural spec.
		 */
		paragraphs(body) {
			return (body || '').split(/\n\s*\n/).filter((p) => p.trim() !== '')
		},

		/**
		 * Check the chosen answer; a right one marks the lesson done.
		 *
		 * @param {object} lesson The lesson.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
		 */
		async check(lesson) {
			this.checking = true
			try {
				const result = await answerLesson(lesson.slug, Number(this.choice))
				if (result.correct) {
					this.feedback = {
						correct: true,
						text: this.t('hermiq', 'Right. This lesson is done.'),
					}
					if (!lesson.done) {
						lesson.done = true
						this.done += 1
					}
				} else {
					this.feedback = {
						correct: false,
						text: this.t(
							'hermiq',
							'Not quite. {explanation} Try again.',
							{
								explanation: result.explanation || '',
							},
						),
					}
				}
			} catch {
				this.feedback = {
					correct: false,
					text: this.t('hermiq', 'Your answer could not be checked.'),
				}
			} finally {
				this.checking = false
			}
		},

		/**
		 * Switch whether the organisation requires the course.
		 *
		 * @param {boolean} value The new value.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
		 */
		async toggleRequired(value) {
			try {
				this.required = (await setRequirement(value)).required === true
			} catch {
				this.error = this.t(
					'hermiq',
					'The course requirement could not be saved.',
				)
			}
		},
	},
}
</script>

<style scoped>
.ai-literacy {
	padding: 20px 20px 20px 56px;
	max-width: 860px;
}

.ai-literacy__heading {
	margin: 0 0 8px;
}

.ai-literacy__intro,
.ai-literacy__progress {
	color: var(--color-text-maxcontrast);
}

.ai-literacy__lessons {
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ai-literacy__lesson {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.ai-literacy__lesson-head {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	width: 100%;
	padding: 12px 16px;
	background: none;
	border: none;
	text-align: start;
	color: var(--color-main-text);
	cursor: pointer;
}

.ai-literacy__lesson-title {
	font-weight: bold;
}

.ai-literacy__lesson-state {
	color: var(--color-text-maxcontrast);
}

.ai-literacy__lesson-state--done {
	color: var(--color-success-text);
}

.ai-literacy__lesson-body {
	padding: 0 16px 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ai-literacy__check {
	border: none;
	padding: 0;
}

.ai-literacy__check legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.ai-literacy__admin {
	margin-top: 32px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ai-literacy__people {
	border-collapse: collapse;
}

.ai-literacy__people th,
.ai-literacy__people td {
	padding: 6px 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
