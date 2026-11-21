// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Who can use an agent (agents-sharing-and-catalog-columns): the three choices
// on the agent form, read from and written to the three stored fields
// isPrivate, invitedUsers and groups. Mirrors AgentCatalog::sharing().

/**
 * The sharing choice an agent's stored fields express.
 *
 * @param {object} data The agent payload.
 * @return {string} 'only-me', 'people-and-groups' or 'organisation'.
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001
 */
export function sharingOf(data) {
	if (!data || data.isPrivate !== true) {
		return 'organisation'
	}
	const people = Array.isArray(data.invitedUsers) ? data.invitedUsers : []
	const groups = Array.isArray(data.groups) ? data.groups : []
	return people.length > 0 || groups.length > 0 ? 'people-and-groups' : 'only-me'
}

/**
 * The stored fields for a sharing choice.
 *
 * @param {string} choice 'only-me', 'people-and-groups' or 'organisation'.
 * @param {Array<string>} people Chosen user ids.
 * @param {Array<string>} groups Chosen group ids.
 * @return {{isPrivate: boolean, invitedUsers: Array<string>, groups: Array<string>}} The fields.
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-an-agent-owner-decides-who-can-use-the-agent-req-agshare-001
 */
export function sharingFields(choice, people, groups) {
	if (choice === 'only-me') {
		return { isPrivate: true, invitedUsers: [], groups: [] }
	}
	return {
		isPrivate: choice !== 'organisation',
		invitedUsers: [...(people || [])],
		groups: [...(groups || [])],
	}
}

/**
 * The agent catalog's cell formatters: who can use an agent, and whether it is
 * switched on. Registered on CnAppRoot as `formatters`.
 *
 * @param {Function} t The app's translate function, `t('hermiq', text)`.
 * @return {object} Formatter id to function.
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */
export function agentCatalogFormatters(t) {
	const sharingLabels = {
		'only-me': t('hermiq', 'Only the owner'),
		'people-and-groups': t('hermiq', 'Chosen people and groups'),
		organisation: t('hermiq', 'Everyone in the organisation'),
	}
	return {
		agentSharing: (value, row) => sharingLabels[sharingOf(row || {})],
		agentStatus: (value) =>
			value === false ? t('hermiq', 'Switched off') : t('hermiq', 'On'),
	}
}
