/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The sources under a chat answer: a file source links to its file, with the
 * page cited; any other source shows its name only.
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-the-chat-page-lists-the-sources-of-every-answer-as-links-req-dchat-005
 */

import { describe, expect, it } from 'vitest'
import { sourceFileId, sourceName, sourcePage } from '../../src/utils/chatSources.js'

describe('chat sources', () => {
	it('links a file source by its file id, else its id', () => {
		expect(sourceFileId({ type: 'file', id: 'x', file_id: 42 })).toBe(42)
		expect(sourceFileId({ type: 'file', id: '17' })).toBe(17)
		expect(sourceFileId({ type: 'file', file_id: '0', id: 5 })).toBe(5)
	})

	it('does not link a non-file source, one without an id, or one that can no longer be opened', () => {
		expect(sourceFileId({ type: 'object', id: 42 })).toBeNull()
		expect(sourceFileId({ type: 'file', id: 'abc' })).toBeNull()
		expect(sourceFileId({ type: 'file', file_id: 42, available: false })).toBeNull()
		expect(sourceFileId(null)).toBeNull()
	})

	it('reads the cited page and the name', () => {
		expect(sourcePage({ page: 3 })).toBe(3)
		expect(sourcePage({ page: null })).toBeNull()
		expect(sourcePage({})).toBeNull()
		expect(sourceName({ name: 'besluit.pdf', id: 42 })).toBe('besluit.pdf')
		expect(sourceName({ id: 42 })).toBe('42')
		expect(sourceName(undefined)).toBe('')
	})
})
