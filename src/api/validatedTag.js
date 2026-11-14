// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the validated tag setting
// (memory-retrieval-scope D2): which restricted Nextcloud system tag marks a
// document as validated, and which groups may assign it. Hermiq stores only the
// tag id; who may assign the tag stays Nextcloud's own tag permission.
//
// Mirrors src/api/runRetention.js: stateless functions, axios from
// @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Hermiq validated tag settings path. */
const VALIDATED_TAG = '/apps/hermiq/api/settings/validated-tag'

/**
 * Read the validated tag setting.
 *
 * @return {Promise<{tagId: ?string, name: ?string, groups: Array<string>}>} The setting.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */
export async function getValidatedTag() {
	const response = await axios.get(generateUrl(VALIDATED_TAG))
	return response.data
}

/**
 * Create the restricted tag (or take the existing tag of that name), let the
 * given groups assign it, and name it as the validated tag.
 *
 * @param {string} name The tag's name.
 * @param {Array<string>} groups The groups that may assign it.
 * @return {Promise<{tagId: ?string, name: ?string, groups: Array<string>}>} The setting.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */
export async function createValidatedTag(name, groups) {
	const response = await axios.post(generateUrl(VALIDATED_TAG), { name, groups })
	return response.data
}

/**
 * Keep the named tag and set which groups may assign it.
 *
 * @param {string} tagId The tag.
 * @param {Array<string>} groups The groups that may assign it.
 * @return {Promise<{tagId: ?string, name: ?string, groups: Array<string>}>} The setting.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */
export async function updateValidatedTag(tagId, groups) {
	const response = await axios.put(generateUrl(VALIDATED_TAG), { tagId, groups })
	return response.data
}
