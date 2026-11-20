// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Small pure helpers for the project layers of an agent's memory
// (memory-project-scopes): what the project picker hands to createSession(),
// and how a project is named on the page.

/**
 * The project a picker choice stands for.
 *
 * @param {string} choice The chosen radio value: a project key, or one of the markers.
 * @param {string} newName The typed name of a new project.
 * @param {{NEW: string, NONE: string}} markers The values of "New project" and "No project".
 * @return {{projectKey: string}|{projectName: string}|null} The project, or null for none.
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
export function projectChoice(choice, newName, markers) {
	if (!choice || choice === markers.NONE) {
		return null
	}
	if (choice === markers.NEW) {
		const name = String(newName || '').trim()
		return name ? { projectName: name } : null
	}
	return { projectKey: choice }
}

/**
 * The readable name of a project key, from the agent's project list; the key
 * itself when the list does not name it.
 *
 * @param {string} projectKey The project key.
 * @param {Array<{projectKey: string, projectName: string}>} projects The agent's projects.
 * @return {string} The name, or '' without a key.
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
export function projectNameOf(projectKey, projects) {
	if (!projectKey) {
		return ''
	}
	const found = (Array.isArray(projects) ? projects : []).find(
		(project) => project?.projectKey === projectKey,
	)
	return found?.projectName || projectKey
}
