/**
 * The tool taxonomy: which cluster and verb each tool belongs to.
 *
 * Separate from `toolOversight.js` deliberately. That module answers what one
 * AGENT holds; this one answers where a tool BELONGS, which is the same for
 * every agent on the instance. Joining them client-side keeps either endpoint
 * from growing a dependency on the other.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch every tool with its owning app, subject group and CRUD verb.
 *
 * @return {Promise<Array<{name: string, app: string, group: string, right: string}>>} The taxonomy rows.
 */
export async function getToolTaxonomy() {
	const response = await axios.get(generateUrl('/apps/hermiq/api/agents/tools'))

	return response.data?.results ?? []
}
