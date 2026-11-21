// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the ONE bespoke agent-evals action: run an
// EvalDataset against an Agent. EvalDataset/EvalRun CRUD go through the generic
// createObjectStore object path (src/store/store.js), exactly like schedule/agent —
// only this trigger has no OpenRegister equivalent (OR exposes no agent-trigger),
// so it is a thin owner-guarded Hermiq endpoint (EvalRunController), mirroring
// src/api/agents.js runScheduleNow().
//
// Deliberately a stateless function (no defineStore) — the hard rule is "no custom
// Pinia stores". axios from @nextcloud/axios adds the CSRF requesttoken.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq evals base path. */
const EVALS_BASE = '/apps/hermiq/api/evals'

/**
 * Run an EvalDataset against an Agent. The run is fully governed (kill-switch,
 * budget, model policy) and non-delivering — no case result is sent to Talk.
 *
 * With `options.baseline === true` (skill-evals) the run executes as a PAIRED
 * baseline: the with-half exposes the agent's installed skills plus the
 * dataset's linked skills, the without-half(s) detach them per the agent's
 * evalBaselineMode (joint: one shared half at ~2x cost; per-skill: one half
 * per linked skill at (N+1)x cost) — every half counts toward the same
 * budgets. Requires a dataset with non-empty skillRefs (400 otherwise) and
 * ownership of dataset + agent + every linked skill (404 otherwise).
 *
 * @param {string} datasetId The EvalDataset object UUID.
 * @param {string} agentId The target Agent UUID.
 * @param {object} [options] Optional { agentVersionId, regressionThresholdPercent, baseline }.
 * @return {Promise<object>} { evalRunId, status, passRate, regressionGateResult, previousPassRate }.
 */
export async function runEval(datasetId, agentId, options = {}) {
	const response = await axios.post(
		generateUrl(`${EVALS_BASE}/${datasetId}/run`),
		{
			agentId,
			...(options.agentVersionId
				? { agentVersionId: options.agentVersionId }
				: {}),
			...(options.regressionThresholdPercent != null
				? { regressionThresholdPercent: options.regressionThresholdPercent }
				: {}),
			...(options.baseline === true ? { baseline: true } : {}),
		},
	)
	return response.data
}

/**
 * Copy a seeded, read-only eval dataset template into a new dataset the caller
 * owns, linked to one of their agents ("Use for this agent").
 *
 * @param {string} datasetId The template's uuid.
 * @param {string} agentId The agent the copy is made for.
 * @return {Promise<object>} { id, name } of the new dataset.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */
export async function useEvalTemplate(datasetId, agentId) {
	const response = await axios.post(generateUrl(`${EVALS_BASE}/${datasetId}/use`), { agentId })
	return response.data
}

/**
 * The caller's schedules of a dataset they own.
 *
 * @param {string} datasetId The dataset's uuid.
 * @return {Promise<Array<object>>} The schedules.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export async function listEvalSchedules(datasetId) {
	const response = await axios.get(generateUrl(`${EVALS_BASE}/${datasetId}/schedules`))
	return response.data?.results || []
}

/**
 * Add a schedule to a dataset, or change one when `scheduleId` is given.
 *
 * @param {string} datasetId The dataset's uuid.
 * @param {object} body { agentId, cronExpr, enabled, regressionThresholdPercent }.
 * @param {string} [scheduleId] The schedule to change.
 * @return {Promise<object>} The stored schedule, with its next run.
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
export async function saveEvalSchedule(datasetId, body, scheduleId = '') {
	const base = `${EVALS_BASE}/${datasetId}/schedules`
	const response = scheduleId
		? await axios.put(generateUrl(`${base}/${scheduleId}`), body)
		: await axios.post(generateUrl(base), body)
	return response.data
}
