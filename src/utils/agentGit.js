// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "Keep in git" on the agent page (agents-export-import-and-git-sync): which actions the
// modal offers and the repository link it shows, read from the agent's own stamp.
// Plain functions, so tests/agent-git.spec.js runs them under node.

/**
 * Whether the agent carries a repository stamp.
 *
 * @param {object} agent The agent's data.
 * @return {boolean}
 */
function stamped(agent) {
	return Boolean(agent?.gitOwner) && Boolean(agent?.gitRepo)
}

/**
 * The actions to offer: publish once, then push and pull.
 *
 * @param {object} agent The agent's data.
 * @return {Array<string>} `['publish']` or `['push', 'pull']`.
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */
export function gitActions(agent) {
	return stamped(agent) ? ['push', 'pull'] : ['publish']
}

/**
 * The stamped repository's URL, or '' when there is none.
 *
 * @param {object} agent The agent's data.
 * @return {string}
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */
export function repoLink(agent) {
	if (!stamped(agent)) {
		return ''
	}
	return `https://github.com/${encodeURIComponent(agent.gitOwner)}/${encodeURIComponent(agent.gitRepo)}`
}
