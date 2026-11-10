/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * What the copy-once secret dialogs (WebhookSecretDialog,
 * ScheduleWebhookSecretDialog) do with a revealed secret: select it on focus
 * for a manual copy, and copy it to the clipboard.
 */

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

/**
 * Select the full value in the readonly field for an easy manual copy.
 *
 * @param {FocusEvent} event The focus event.
 * @return {void}
 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
 */
export function selectAll(event) {
	if (event && event.target && typeof event.target.select === 'function') {
		event.target.select()
	}
}

/**
 * Copy a secret to the clipboard, with a graceful fallback message when the
 * Clipboard API is unavailable (e.g. an insecure context).
 *
 * @param {string} secret The plaintext secret.
 * @return {Promise<void>}
 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
 */
export async function copySecret(secret) {
	try {
		await navigator.clipboard.writeText(secret)
		showSuccess(t('hermiq', 'Secret copied to clipboard.'))
	} catch {
		showError(
			t(
				'hermiq',
				'Could not copy automatically — select and copy the value manually.',
			),
		)
	}
}
