// SPDX-FileCopyrightText: 2026 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2
//
// Which retrieval tier semantic and hybrid RAG run on (vector-rag), from the
// settings read-out. Read-only: the embedding backend is configured in
// Open Register, not here.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The live retrieval tier, or null when it cannot be read.
 *
 * @return {Promise<{tier: string, reason: string}|null>} The tier.
 * @spec openspec/changes/vector-rag/tasks.md#task-4-1
 */
export async function getRetrievalTier() {
	try {
		const { data } = await axios.get(generateUrl('/apps/hermiq/api/settings'))
		const tier = data && data.retrievalTier
		return tier && typeof tier.tier === 'string' ? tier : null
	} catch {
		return null
	}
}
