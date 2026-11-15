/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The message a failed request shows: the server's own error, the exception's
 * message, or the caller's (translated) fallback, in that order.
 */

/**
 * The message for a failed request.
 *
 * @param {object} error What was thrown.
 * @param {string} fallback The translated message when neither carries one.
 * @return {string} The message.
 * @spec exclude pure error-text pick shared by the UI's catch blocks, no domain behaviour
 */
export function errorMessage(error, fallback) {
	return error?.response?.data?.error || error?.message || fallback
}
