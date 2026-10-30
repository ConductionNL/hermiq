// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Working with AI (compliance-ai-literacy): the lessons and answer check for the
// signed-in person, and the organisation report and requirement for its admin.
// Stateless helpers, no store.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/hermiq/api/literacy'

/**
 * The lessons in the person's language with their progress.
 *
 * @return {Promise<object>} `{ lessons, done, total, required, mayAdminister }`.
 */
export async function getLessons() {
	const { data } = await axios.get(generateUrl(`${BASE}/lessons`))
	return data
}

/**
 * Check an answer to one lesson.
 *
 * @param {string} slug The lesson.
 * @param {number} choice The zero-based option index.
 * @return {Promise<object>} `{ correct, explanation? }`.
 */
export async function answerLesson(slug, choice) {
	const { data } = await axios.post(
		generateUrl(`${BASE}/lessons/${encodeURIComponent(slug)}/answer`),
		{ choice },
	)
	return data
}

/**
 * Completion per person in the admin's organisation.
 *
 * @return {Promise<object>} `{ organisation, required, people }`.
 */
export async function getOverview() {
	const { data } = await axios.get(generateUrl(`${BASE}/overview`))
	return data
}

/**
 * The URL of the CSV export.
 *
 * @return {string} The URL.
 */
export function overviewCsvUrl() {
	return generateUrl(`${BASE}/overview.csv`)
}

/**
 * Switch whether the organisation requires the course.
 *
 * @param {boolean} required Whether it is required.
 * @return {Promise<object>} `{ organisation, required }`.
 */
export async function setRequirement(required) {
	const { data } = await axios.put(generateUrl(`${BASE}/requirement`), {
		required,
	})
	return data
}
