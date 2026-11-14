// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Whose rights an agent works with (agent-acting-identity-picker): the two
// choices "Namens wie werkt de agent" on the agent form, read from and written
// to the one stored field `actingUser` (design D1). Empty means the person who
// uses the agent; a user id means the agent's own service account.

/** The choice: on behalf of the person who uses the agent. */
export const ACTING_AS_PERSON = 'person'

/** The choice: as its own service account. */
export const ACTING_AS_SERVICE_ACCOUNT = 'service-account'

/**
 * The trimmed declared acting user of an agent payload, or '' when none.
 *
 * @param {object} data The agent payload.
 * @return {string} The user id.
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-agent-edit-page-sets-whose-rights-the-agent-works-with-req-agid-001
 */
export function actingUserOf(data) {
	const value = data ? data.actingUser : undefined
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * The choice an agent's stored `actingUser` expresses.
 *
 * @param {object} data The agent payload.
 * @return {string} ACTING_AS_PERSON or ACTING_AS_SERVICE_ACCOUNT.
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-agent-edit-page-sets-whose-rights-the-agent-works-with-req-agid-001
 */
export function actingChoiceOf(data) {
	return actingUserOf(data) === '' ? ACTING_AS_PERSON : ACTING_AS_SERVICE_ACCOUNT
}

/**
 * The stored `actingUser` for a choice: empty for the person who uses the
 * agent, the chosen user id for a service account (empty while none is picked).
 *
 * @param {string} choice ACTING_AS_PERSON or ACTING_AS_SERVICE_ACCOUNT.
 * @param {{value: string}|string|null} account The picked user, as a picker option or an id.
 * @return {{actingUser: string}} The field.
 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-the-agent-edit-page-sets-whose-rights-the-agent-works-with-req-agid-001
 */
export function actingUserField(choice, account) {
	if (choice !== ACTING_AS_SERVICE_ACCOUNT) {
		return { actingUser: '' }
	}
	const id = account && typeof account === 'object' ? account.value : account
	return { actingUser: typeof id === 'string' ? id.trim() : '' }
}
