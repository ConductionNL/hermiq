// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the chat page tells the user when starting a session fails (hermiq#1086).
//
// A non-admin who clicked "Start session" used to get a 500 and, as far as they
// could tell, nothing at all: the session list stayed "No sessions yet". The
// backend now answers a refusal with a 4xx and a reason, and this turns that
// answer into a sentence the page keeps on screen next to the agent list.

/**
 * The sentence to show for a failed session start.
 *
 * @param {object} error The axios error from createSession().
 * @param {function(string, string, object=): string} t The translate function.
 * @return {string} A translated, user-facing sentence.
 * @spec openspec/changes/session-frontend-rename/specs/session-surface/spec.md#requirement-starting-a-new-session-must-produce-a-visible-result
 */
export function sessionStartErrorMessage(error, t) {
	const status = error?.response?.status
	if (status === 400) {
		return t('hermiq', 'Choose an agent to start a session with.')
	}
	if (status === 403) {
		return t(
			'hermiq',
			'You do not have permission to start a session with this agent.',
		)
	}
	if (status === 404) {
		return t('hermiq', 'This agent does not exist or is not shared with you.')
	}
	const reason = error?.response?.data?.message
	if (typeof reason === 'string' && reason.trim() !== '') {
		return t('hermiq', 'Could not start the session: {reason}', {
			reason: reason.trim(),
		})
	}
	return t('hermiq', 'Could not start the session.')
}
