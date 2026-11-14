// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the knowledge base routes
// (memory-knowledge-bases). Knowledge bases themselves are OpenRegister objects
// (useKnowledgeBaseStore); only the two actions that touch Files are Hermiq
// routes: uploading documents into the knowledge base's folder, and reading
// each source's indexing state and whether only the owner can read it.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The base URL of one knowledge base's Hermiq routes.
 *
 * @param {string} id The knowledge base uuid.
 * @return {string} The URL.
 */
function base(id) {
	return generateUrl('/apps/hermiq/api/knowledge-bases/{id}', { id })
}

/**
 * Upload documents into a knowledge base's folder in the owner's Files.
 *
 * @param {string} id The knowledge base uuid.
 * @param {Array<File>|FileList} files The documents.
 * @return {Promise<Array<object>>} The knowledge base's sources after the upload.
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
export async function uploadDocuments(id, files) {
	const body = new FormData()
	for (const file of Array.from(files || [])) {
		body.append('files[]', file)
	}
	const { data } = await axios.post(base(id) + '/documents', body)
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * The sources of a knowledge base with their state, and whether search is available.
 *
 * @param {string} id The knowledge base uuid.
 * @return {Promise<{sources: Array<object>, searchAvailable: boolean}>} The sources and the flag.
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
 */
export async function fetchSources(id) {
	const { data } = await axios.get(base(id) + '/sources')
	return {
		sources: Array.isArray(data?.results) ? data.results : [],
		searchAvailable: data?.searchAvailable === true,
	}
}
