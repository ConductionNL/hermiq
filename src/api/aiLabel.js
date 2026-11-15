// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The AI label on what agents write (agent-authored-marking): the counts for the
// AI oversight page, marking a labelled file as reviewed, and the Files link
// filtered on the stored tag.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq AI label endpoints. */
const AI_LABEL_BASE = '/apps/hermiq/api/ai-label'

/**
 * The label counts for the signed-in person, limited to the files they can read.
 *
 * @return {Promise<{files: number, reviewed: number, reviewedShare: number, tagId: string|null, truncated: boolean}>} The counts.
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */
export async function getAiLabelStats() {
	const response = await axios.get(generateUrl(`${AI_LABEL_BASE}/stats`))
	return response.data
}

/**
 * Mark an agent-authored file as reviewed.
 *
 * @param {number} fileId The Nextcloud file id.
 * @return {Promise<{fileId: number, reviewed: boolean}>} The result.
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */
export async function markAiLabelReviewed(fileId) {
	const response = await axios.post(generateUrl(`${AI_LABEL_BASE}/files/${fileId}/reviewed`))
	return response.data
}

/**
 * The Files view of every file carrying the stored agent-authored tag.
 *
 * @param {string|null} tagId The system tag id from the counts.
 * @return {string} The Files URL, or '' when the tag was never created.
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-hermiq-shows-the-ai-label-as-gemaakt-met-ai-req-ailbl-004
 */
export function aiLabelFilesUrl(tagId) {
	if (!tagId) {
		return ''
	}
	return generateUrl('/apps/files/tags?dir=/{tagId}', { tagId })
}
