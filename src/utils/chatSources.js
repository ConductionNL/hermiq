/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The sources under a chat answer: which ones open a file, and the page cited.
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-chat-page-lists-the-sources-of-every-answer-as-links-req-dchat-005
 */

/**
 * The positive integer in a value (a number or a string of digits), else null.
 *
 * @param {number|string|null|undefined} value The value.
 * @return {number|null} The integer.
 */
function positiveInt(value) {
	const number = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value
	return Number.isInteger(number) && number > 0 ? number : null
}

/**
 * The file a source opens: its `file_id`, else its id, for a file source the
 * person can still open; null for any other source, which shows its name only.
 *
 * @param {object} source One source of an answer.
 * @return {number|null} The Nextcloud file id.
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-chat-page-lists-the-sources-of-every-answer-as-links-req-dchat-005
 */
export function sourceFileId(source) {
	if (!source || source.type !== 'file' || source.available === false) {
		return null
	}
	return positiveInt(source.file_id) ?? positiveInt(source.id)
}

/**
 * The page a source cites, when known.
 *
 * @param {object} source One source of an answer.
 * @return {number|null} The page number.
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-chat-page-lists-the-sources-of-every-answer-as-links-req-dchat-005
 */
export function sourcePage(source) {
	return positiveInt(source?.page)
}

/**
 * The name a source is listed under.
 *
 * @param {object} source One source of an answer.
 * @return {string} Its name, else its id.
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-chat-page-lists-the-sources-of-every-answer-as-links-req-dchat-005
 */
export function sourceName(source) {
	return String(source?.name || source?.id || '')
}
