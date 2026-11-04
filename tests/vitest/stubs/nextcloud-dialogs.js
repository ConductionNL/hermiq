/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @nextcloud/dialogs stand-in: toasts are recorded on `shown` for a test to read.
 */

export const shown = []

export const showError = (message) => shown.push(['error', message])
export const showSuccess = (message) => shown.push(['success', message])
export const showWarning = (message) => shown.push(['warning', message])
export const showInfo = (message) => shown.push(['info', message])
