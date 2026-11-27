// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The agent form's answer cache setting (models-prompt-and-answer-cache).
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-4
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import { answerCacheFields, answerCachePayload, answerCacheSummary, organisationScopeAllowed, questionBefore } from '../../src/utils/answerCache.js'

describe('answer cache form fields', () => {
	it('reads a stored setting, with the lifetime in days', () => {
		expect(answerCacheFields({ answerCache: { enabled: true, ttlMinutes: 10080, similarity: 'organisation' } }))
			.toEqual({ answerCacheEnabled: true, answerCacheDays: 7, answerCacheSimilarity: 'organisation' })
	})

	it('starts off, one day, exact repeats only', () => {
		expect(answerCacheFields({})).toEqual({ answerCacheEnabled: false, answerCacheDays: 1, answerCacheSimilarity: 'off' })
		expect(answerCacheFields({ answerCache: { enabled: 'yes', ttlMinutes: '9', similarity: 'all' } }))
			.toEqual({ answerCacheEnabled: false, answerCacheDays: 1, answerCacheSimilarity: 'off' })
	})
})

describe('answer cache payload', () => {
	it('stores seven days as minutes', () => {
		expect(answerCachePayload({ answerCacheEnabled: true, answerCacheDays: '7', answerCacheSimilarity: 'user' }))
			.toEqual({ enabled: true, ttlMinutes: 10080, similarity: 'user' })
	})

	it('keeps the lifetime within the register bounds', () => {
		expect(answerCachePayload({ answerCacheEnabled: true, answerCacheDays: 365 }).ttlMinutes).toBe(43200)
		expect(answerCachePayload({ answerCacheEnabled: true, answerCacheDays: 0 }).ttlMinutes).toBe(1440)
		expect(answerCachePayload({ answerCacheEnabled: true, answerCacheDays: 'x' }).ttlMinutes).toBe(1440)
	})

	it('refuses organisation scope while retrieval is on', () => {
		const form = { answerCacheEnabled: true, answerCacheDays: 1, answerCacheSimilarity: 'organisation', enableRag: true }
		expect(organisationScopeAllowed(form)).toBe(false)
		expect(answerCachePayload(form).similarity).toBe('user')
		expect(answerCachePayload({ ...form, enableRag: false }).similarity).toBe('organisation')
		expect(organisationScopeAllowed({ enableRag: false })).toBe(true)
	})

	it('stores a switched-off cache as off', () => {
		expect(answerCachePayload({ answerCacheEnabled: false, answerCacheDays: 3, answerCacheSimilarity: 'bogus' }))
			.toEqual({ enabled: false, ttlMinutes: 4320, similarity: 'off' })
	})
})

describe('the question a cached answer replied to', () => {
	const question = { role: 'user', content: '  Wanneer wordt het oud papier opgehaald? ' }
	const answer = { role: 'assistant', content: 'Op maandag.', fromCache: true }
	const later = { role: 'user', content: 'En glas?' }

	it('is the nearest user turn before the answer, trimmed', () => {
		expect(questionBefore([{ role: 'user', content: 'Hallo' }, { role: 'assistant', content: 'Hoi' }, question, answer, later], answer))
			.toBe('Wanneer wordt het oud papier opgehaald?')
	})

	it('is empty without a user turn before it or without a thread', () => {
		expect(questionBefore([answer, later], answer)).toBe('')
		expect(questionBefore(null, answer)).toBe('')
	})

	it('falls back to the last user turn when the answer is not in the thread', () => {
		expect(questionBefore([question, later], answer)).toBe('En glas?')
	})
})

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k] ?? m))
const plural = (app, one, many, count, vars) => translate(app, count === 1 ? one : many, vars)

describe('the answer cache on the agent page', () => {
	it('says it is on and for how long', () => {
		expect(answerCacheSummary({ enabled: true, ttlMinutes: 10080, similarity: 'off' }, plural, translate)).toBe('Answer cache: on, 7 days')
		expect(answerCacheSummary({ enabled: true, ttlMinutes: 1440 }, plural, translate)).toBe('Answer cache: on, 1 day')
	})

	it('says off when the cache is off or was never set', () => {
		expect(answerCacheSummary({ enabled: false, ttlMinutes: 10080 }, plural, translate)).toBe('Off')
		expect(answerCacheSummary(undefined, plural, translate)).toBe('Off')
		expect(answerCacheSummary({ enabled: 'yes' }, plural, translate)).toBe('Off')
	})
})

describe('the agent\'s Configuration card', () => {
	it('shows the answer cache through its formatter, read-only', () => {
		const cards = JSON.stringify(manifest)
		const card = manifest.pages
			.flatMap((page) => page.config?.widgets || [])
			.find((widget) => widget.content?.title === 'Configuration' && (widget.content?.include || []).includes('ensemble'))

		expect(cards).toContain('agentAnswerCache')
		expect(card.content.include).toContain('answerCache')
		// An object property is left out of the card unless its override names a widget.
		expect(card.content.overrides.answerCache).toEqual({
			label: 'Reuse answers to repeated questions',
			widget: 'json',
			formatter: 'agentAnswerCache',
			editable: false,
		})
		expect(card.content.overrides.ensemble.widget).toBe('json')
	})
})
