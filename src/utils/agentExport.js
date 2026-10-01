// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The agent page's Export and the catalog's Import agent (agents-export-import-and-git-sync):
// the name of the downloaded file and the check an imported file must pass before it is sent.
// Plain functions, so tests/agent-export.spec.js runs them under node.

/**
 * The file name an exported agent downloads as: `<agent-name>.hermiq-agent.json`.
 *
 * @param {string} name The agent's name.
 * @return {string} The file name.
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-exported-to-a-file-from-its-page-req-agexp-001
 */
export function exportFileName(name) {
	const slug = String(name || '')
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
	return `${slug || 'agent'}.hermiq-agent.json`
}

/**
 * The package text of an imported file, or an error saying why it is not an exported agent.
 *
 * @param {string} text The file's contents.
 * @return {string} The package text, unchanged.
 * @throws {Error} When the file is not a JSON object or has no name.
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-gallery/spec.md#requirement-an-exported-agent-is-imported-through-review-req-agexp-002
 */
export function packageFromFile(text) {
	let parsed
	try {
		parsed = JSON.parse(text)
	} catch {
		parsed = null
	}
	if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
		throw Object.assign(new Error('This file is not an exported agent.'), {
			code: 'not-an-agent',
		})
	}
	if (typeof parsed.name !== 'string' || parsed.name.trim() === '') {
		throw Object.assign(new Error('This agent file has no name.'), {
			code: 'no-name',
		})
	}
	return text
}
