// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the curator's skill merge proposals
// (skills-curator-merges-duplicates): list the pending proposals, approve one
// (the duplicate is archived and its agents move to the survivor) or reject one
// (both skills stay). SkillMergeController gates every route on the
// skill.merge-duplicates action. axios from @nextcloud/axios adds the CSRF
// requesttoken.
//
// @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq skill merges API base. */
const MERGES_BASE = '/apps/hermiq/api/skill-merges'

/**
 * The pending merge proposals.
 *
 * @return {Promise<Array<object>>} Per proposal { duplicate, survivor, reason, proposedAt }.
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
 */
export async function listMergeProposals() {
	const response = await axios.get(generateUrl(MERGES_BASE))
	return Array.isArray(response.data?.proposals) ? response.data.proposals : []
}

/**
 * Approve the merge proposed on a duplicate skill.
 *
 * @param {string} duplicateId The duplicate skill's UUID.
 * @return {Promise<object>} { merged, survivor, agentsMoved }.
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-an-approved-merge-archives-the-duplicate-and-moves-its-agents
 */
export async function approveMerge(duplicateId) {
	const response = await axios.post(generateUrl(`${MERGES_BASE}/${duplicateId}/approve`))
	return response.data
}

/**
 * Reject the merge proposed on a duplicate skill: both skills stay.
 *
 * @param {string} duplicateId The duplicate skill's UUID.
 * @return {Promise<object>} { rejected }.
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-a-rejected-merge-changes-nothing
 */
export async function rejectMerge(duplicateId) {
	const response = await axios.post(generateUrl(`${MERGES_BASE}/${duplicateId}/reject`))
	return response.data
}
