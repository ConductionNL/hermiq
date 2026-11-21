/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Client-side download of a JSON payload, shared by the compliance pages and
 * the run trace.
 */

/**
 * Download a JSON object as a pretty-printed file.
 *
 * @param {object} data The payload to download.
 * @param {string} filename The download filename.
 * @return {void}
 * @spec exclude browser download plumbing shared by the export buttons, no domain behaviour
 */
export function downloadJson(data, filename) {
	const blob = new Blob([JSON.stringify(data, null, 2)], {
		type: 'application/json',
	})
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = filename
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
	URL.revokeObjectURL(url)
}
