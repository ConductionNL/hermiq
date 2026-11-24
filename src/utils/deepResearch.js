// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The chat's deep research controls (tools-deep-research-report task 6): whether
// the "Deep research" switch is offered for an agent, which research run of the
// session is still going, and the progress line shown above "Stop research".
//
// @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001

import { flattenGrants } from '../api/toolTaxonomy.js'

/** The two tools a research run needs: it searches, then reads what it found. */
export const RESEARCH_TOOLS = ['hermiq.webSearch', 'hermiq.webFetch']

/** How often the chat re-reads a running research, in milliseconds. */
export const RESEARCH_POLL_MS = 5000

/**
 * Whether an agent holds both web research tools, so the switch is shown.
 *
 * A grant may carry constraints (`hermiq.webFetch?host=in:a,b`); the tool id is
 * the part before them. An agent with no grants at all may use every tool, as
 * the engine reads it (`ToolLoop::listAgentFunctions()`: an empty whitelist
 * allows all), so it holds both.
 *
 * @param {object|null} agent The agent of the open session.
 * @return {boolean} True when the switch is offered.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
export function canDeepResearch(agent) {
	if (agent === null || typeof agent !== 'object') {
		return false
	}
	const ids = flattenGrants(agent.tools).map((grant) => grant.split('?')[0])
	if (ids.length === 0) {
		return true
	}
	return RESEARCH_TOOLS.every((tool) => ids.includes(tool))
}

/**
 * The session's research run that is still going: the latest waiting research handoff.
 *
 * @param {Array<object>} handoffs The session's handoffs.
 * @return {object|null} The run, or null when none is going.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
export function runningResearch(handoffs) {
	const running = (Array.isArray(handoffs) ? handoffs : []).filter(
		(handoff) => handoff && handoff.kind === 'research' && handoff.status === 'waiting',
	)
	return running.length > 0 ? running[running.length - 1] : null
}

/**
 * The id a research run is addressed by.
 *
 * @param {object|null} run The research handoff.
 * @return {string} Its id, or '' when it has none.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
export function researchId(run) {
	return String(run?.id || run?.uuid || '')
}

/**
 * The progress line of a running research.
 *
 * @param {object|null} run The research handoff.
 * @param {Function} t The translate function, called as t(app, text, vars).
 * @return {string} The line, or '' without a run.
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
export function researchProgressLine(run, t) {
	if (!run) {
		return ''
	}
	if (run.stopRequested === true) {
		return t('hermiq', 'Stopping the research…')
	}
	const progress = run.progress && typeof run.progress === 'object' ? run.progress : {}
	const sources = Number(progress.sources) || 0
	const toolCalls = Number(progress.toolCalls) || 0
	switch (progress.phase) {
	case 'gather':
		return t('hermiq', 'Researching: {sources} sources read, {toolCalls} tool calls', { sources, toolCalls })
	case 'write':
		return t('hermiq', 'Writing the report from {sources} sources…', { sources })
	default:
		return t('hermiq', 'Planning the research…')
	}
}
