// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The chat page's deep research controls (tools-deep-research-report task 6),
// mixed into Chat.vue: the "Deep research" switch beside Send, starting a run
// instead of a turn, the progress of the session's running run with its "Stop
// research" button, and re-reading the thread while the run goes. The page
// provides `activeSession`, `currentAgent`, `handoffs`, `sendError`, `sending`,
// `currentMessage`, `finishTurn()` and `refreshThread()`.
//
// @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001

import { startResearch, stopResearch } from '../api/research.js'
import {
	canDeepResearch,
	RESEARCH_POLL_MS,
	researchId,
	researchProgressLine,
	runningResearch,
} from '../utils/deepResearch.js'

export const deepResearchControls = {
	data() {
		return {
			deepResearch: false,
			researchTimer: null,
			stoppingResearch: false,
		}
	},

	computed: {
		/**
		 * Whether the "Deep research" switch is offered for the open session's agent.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
		 */
		canDeepResearch() {
			return Boolean(this.activeSession) && canDeepResearch(this.currentAgent)
		},

		/**
		 * The session's research run that is still going, or null.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
		 */
		activeResearch() {
			return runningResearch(this.handoffs)
		},

		/**
		 * The progress line of the running research.
		 *
		 * @return {string}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
		 */
		researchProgress() {
			return researchProgressLine(this.activeResearch, this.t)
		},
	},

	watch: {
		/**
		 * Re-read the thread while a research run goes, and stop once it ends.
		 *
		 * @return {void}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
		 */
		activeResearch() {
			this.syncResearchPolling()
		},
	},

	/**
	 * Stop polling when the page goes.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	beforeUnmount() {
		clearInterval(this.researchTimer)
	},

	methods: {
		/**
		 * Start a research run on the question instead of a chat turn. The run
		 * posts its own plan and report in the conversation; the thread is re-read
		 * so its handoff, and with it the progress and the stop button, appear.
		 *
		 * @param {string} text The question.
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
		 */
		async sendAsResearch(text) {
			const uuid = this.activeSession.uuid
			this.sendError = ''
			this.sending = true
			try {
				await startResearch({
					agentId: this.currentAgent?.uuid || this.currentAgent?.id || this.activeSession.agentId || '',
					conversationId: uuid,
					question: text,
				})
				this.currentMessage = ''
				this.deepResearch = false
			} catch {
				this.sendError = this.t('hermiq', 'The research could not be started.')
			} finally {
				await this.finishTurn(uuid)
			}
		},

		/**
		 * Ask the running research to stop at its next step.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
		 */
		async onStopResearch() {
			const id = researchId(this.activeResearch)
			if (id === '' || this.stoppingResearch) {
				return
			}
			this.stoppingResearch = true
			try {
				await stopResearch(id)
			} catch {
				this.sendError = this.t('hermiq', 'The research could not be stopped.')
			} finally {
				this.stoppingResearch = false
				if (this.activeSession) {
					await this.refreshThread(this.activeSession.uuid)
				}
			}
		},

		/**
		 * Poll the thread while a research run goes; stop polling once none does.
		 *
		 * @return {void}
		 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
		 */
		syncResearchPolling() {
			if (this.activeResearch && this.researchTimer === null) {
				this.researchTimer = setInterval(() => {
					if (this.activeSession) {
						this.refreshThread(this.activeSession.uuid)
					}
				}, RESEARCH_POLL_MS)
				return
			}
			if (!this.activeResearch && this.researchTimer !== null) {
				clearInterval(this.researchTimer)
				this.researchTimer = null
			}
		},
	},
}
