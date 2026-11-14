// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Plain (non-Pinia) API helper for the trace export settings
// (observability-export-traces): the OTLP endpoint, the broker credential by
// reference, the sources and how much of a run leaves the server.
//
// Mirrors src/api/runRetention.js: stateless functions, axios from
// @nextcloud/axios so the CSRF requesttoken travels with the write.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const TRACE_EXPORT = '/apps/hermiq/api/settings/trace-export'

/**
 * Read the trace export settings and their notices.
 *
 * @return {Promise<object>} `{settings, destinationNotice, copyNotice}`.
 * @spec openspec/changes/observability-export-traces/tasks.md#task-1-admin-settings-and-the-connection-row
 */
export async function getTraceExport() {
	const response = await axios.get(generateUrl(TRACE_EXPORT))
	return response.data
}

/**
 * Save the trace export settings.
 *
 * @param {object} settings The settings to store.
 * @return {Promise<object>} `{settings, destinationNotice, copyNotice}` as stored.
 * @spec openspec/changes/observability-export-traces/tasks.md#task-1-admin-settings-and-the-connection-row
 */
export async function setTraceExport(settings) {
	const response = await axios.put(generateUrl(TRACE_EXPORT), settings)
	return response.data
}
