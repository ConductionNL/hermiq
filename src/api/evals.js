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
 * @param {string} datasetId The EvalDataset object UUID.
 * @param {string} agentId The target Agent UUID.
 * @param {object} [options] Optional { agentVersionId, regressionThresholdPercent }.
 * @return {Promise<object>} { evalRunId, status, passRate, regressionGateResult, previousPassRate }.
 */
export async function runEval(datasetId, agentId, options = {}) {
	const response = await axios.post(generateUrl(`${EVALS_BASE}/${datasetId}/run`), {
		agentId,
		...(options.agentVersionId ? { agentVersionId: options.agentVersionId } : {}),
		...(options.regressionThresholdPercent != null ? { regressionThresholdPercent: options.regressionThresholdPercent } : {}),
	})
	return response.data
}
