<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  AgentQuestionCard (oversight-ask-and-hold): the question an agent asked the
  person with hermiq.askPerson, shown under its answer. One button per
  suggested answer, and a text box when the agent allows an answer of the
  person's own. Choosing emits the answer; the chat sends it as the next
  message.

  @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
-->
<template>
	<section
		class="agent-question"
		:aria-label="t('hermiq', 'Question from the agent')"
		data-testid="chat-agent-question">
		<p class="agent-question__text">
			{{ question.text }}
		</p>
		<div
			v-if="options.length > 0"
			class="agent-question__options"
			role="group"
			:aria-label="t('hermiq', 'Suggested answers')">
			<NcButton
				v-for="(option, index) in options"
				:key="index"
				variant="secondary"
				:disabled="disabled"
				:data-testid="`chat-agent-question-option-${index}`"
				@click="choose(option)">
				{{ option }}
			</NcButton>
		</div>
		<form
			v-if="question.allowOther"
			class="agent-question__other"
			data-testid="chat-agent-question-other"
			@submit.prevent="sendOther">
			<NcTextField
				v-model="other"
				:label="t('hermiq', 'Your own answer')"
				:disabled="disabled"
				maxlength="2000" />
			<NcButton
				type="submit"
				variant="primary"
				:disabled="disabled || other.trim() === ''">
				{{ t('hermiq', 'Send answer') }}
			</NcButton>
		</form>
	</section>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'

/**
 * The suggested answers of a question: non-empty strings, at most four.
 *
 * @param {object} question The question, `{text, options, allowOther}`.
 * @return {Array<string>} The options.
 */
export function questionOptions(question) {
	const raw = Array.isArray(question?.options) ? question.options : []
	return raw
		.filter((option) => typeof option === 'string' && option.trim() !== '')
		.slice(0, 4)
}

export default {
	name: 'AgentQuestionCard',

	components: {
		NcButton,
		NcTextField,
	},

	props: {
		/** The question, `{text, options, allowOther}`, from the turn's final payload. */
		question: {
			type: Object,
			required: true,
		},

		/** True while a turn is being sent: the answers cannot be chosen twice. */
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['answer'],

	data() {
		return {
			other: '',
		}
	},

	computed: {
		/**
		 * The buttons to show.
		 *
		 * @return {Array<string>}
		 */
		options() {
			return questionOptions(this.question)
		},
	},

	methods: {
		/**
		 * Answer with a suggested answer.
		 *
		 * @param {string} option The chosen answer.
		 * @return {void}
		 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
		 */
		choose(option) {
			if (this.disabled) {
				return
			}
			this.$emit('answer', option)
		},

		/**
		 * Answer with the person's own text.
		 *
		 * @return {void}
		 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
		 */
		sendOther() {
			const text = this.other.trim()
			if (this.disabled || text === '') {
				return
			}
			this.$emit('answer', text)
			this.other = ''
		},
	},
}
</script>

<style scoped>
.agent-question {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 8px 0 0;
	padding: 12px;
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
}

.agent-question__text {
	margin: 0;
	font-weight: bold;
}

.agent-question__options {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.agent-question__other {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
</style>
