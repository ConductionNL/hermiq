// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The agent's retrieval scope on the agent form, and the scope an answer was given
// on the Chat page (memory-retrieval-scope). The form holds the file filters
// (`ragFileFilters`: owners, file name pattern, modified after and before) and the
// validated mode (`answerOnlyFromValidated`, `ragMinSimilarity`,
// `abstentionMessage`); the server enforces both, this only reads and writes them.

import { getCanonicalLocale } from '@nextcloud/l10n'

/** The floor the register defaults to when an agent sets none. */
export const DEFAULT_MIN_SIMILARITY = 0.5

/**
 * A date the way the register stores it, or ''.
 *
 * @param {string|null|undefined} value A stored or typed date.
 * @return {string} `YYYY-MM-DD`, or '' when the value is not one.
 */
function isoDate(value) {
	return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value.trim())
		? value.trim()
		: ''
}

/**
 * The stored owners of one type, as picker options.
 *
 * @param {Array<object|null>} owners The stored owners.
 * @param {string} type 'user' or 'group'.
 * @return {Array<{label: string, value: string}>} The options.
 */
function ownerOptions(owners, type) {
	return owners
		.filter((owner) => owner && typeof owner.id === 'string' && owner.id !== '')
		.filter((owner) => (owner.type || 'user') === type)
		.map((owner) => ({ label: owner.id, value: owner.id }))
}

/**
 * The form fields for the agent's file filters and validated mode.
 *
 * @param {object} source The stored agent or draft.
 * @return {object} The fields.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */
export function retrievalScopeFields(source) {
	const filters = storedFileFilters(source)
	const owners = Array.isArray(filters.owners) ? filters.owners : []
	return {
		fileOwnerUsers: ownerOptions(owners, 'user'),
		fileOwnerGroups: ownerOptions(owners, 'group'),
		fileNamePattern: textOrEmpty(filters.namePattern),
		fileModifiedAfter: isoDate(filters.modifiedAfter),
		fileModifiedBefore: isoDate(filters.modifiedBefore),
		answerOnlyFromValidated: source?.answerOnlyFromValidated === true,
		ragMinSimilarity: source?.ragMinSimilarity ?? '',
		abstentionMessage: textOrEmpty(source?.abstentionMessage),
	}
}

/**
 * An agent's stored file filters, or an empty object when it has none.
 *
 * @param {object|null|undefined} source The stored agent.
 * @return {object} The filters.
 */
function storedFileFilters(source) {
	const filters = source?.ragFileFilters
	return typeof filters === 'object' && filters !== null ? filters : {}
}

/**
 * A stored string, or '' for anything else.
 *
 * @param {*} value The stored value.
 * @return {string}
 */
function textOrEmpty(value) {
	return typeof value === 'string' ? value : ''
}

/**
 * The `ragFileFilters` the form expresses, holding only the filters that are set.
 *
 * @param {object} form The form.
 * @return {object} The filters; empty when none is set.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 */
export function fileFiltersOf(form) {
	const owners = [
		...(form.fileOwnerUsers || []).map((option) => ({ type: 'user', id: option.value })),
		...(form.fileOwnerGroups || []).map((option) => ({ type: 'group', id: option.value })),
	]
	const filters = {}
	if (owners.length > 0) {
		filters.owners = owners
	}
	const pattern = (form.fileNamePattern || '').trim()
	if (pattern !== '') {
		filters.namePattern = pattern
	}
	const after = isoDate(form.fileModifiedAfter)
	if (after !== '') {
		filters.modifiedAfter = after
	}
	const before = isoDate(form.fileModifiedBefore)
	if (before !== '') {
		filters.modifiedBefore = before
	}
	return filters
}

/**
 * The similarity floor typed into the form, when it is a number from 0 to 1.
 *
 * @param {string|number|null} raw The field's value.
 * @return {number|undefined} The floor, or undefined to leave the key out.
 */
function floorOf(raw) {
	if (raw === '' || raw === null || raw === undefined) {
		return undefined
	}
	const value = Number(raw)
	return Number.isNaN(value) ? undefined : Math.min(1, Math.max(0, value))
}

/**
 * The payload's retrieval scope fields.
 *
 * A field the form leaves at its default is only written when the stored agent
 * already holds it, so editing an agent for an unrelated reason does not write
 * the defaults onto it, while clearing a stored value still clears it.
 *
 * @param {object} form The form.
 * @param {object} base The editable copy of the stored agent ({} on a create).
 * @return {object} The fields; one that is not to be written is undefined.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-owner-can-limit-file-retrieval-by-owner-file-name-and-modified-date-req-rscope-001
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-an-agent-in-validated-mode-refuses-without-asking-the-model-when-nothing-validated-matches-req-rscope-003
 */
export function retrievalScopePayload(form, base) {
	const stored = base || {}
	const filters = fileFiltersOf(form)
	const message = (form.abstentionMessage || '').trim()
	const keep = (key, isSet) => isSet || stored[key] !== undefined
	return {
		ragFileFilters: keep('ragFileFilters', Object.keys(filters).length > 0)
			? filters
			: undefined,
		answerOnlyFromValidated: keep(
			'answerOnlyFromValidated',
			form.answerOnlyFromValidated === true,
		)
			? form.answerOnlyFromValidated === true
			: undefined,
		ragMinSimilarity: floorOf(form.ragMinSimilarity),
		abstentionMessage: keep('abstentionMessage', message !== '') ? message : undefined,
	}
}

/**
 * The scope an answer was given, read for display, or null when there is nothing to say.
 *
 * A turn without a record, or one that was neither filtered nor in validated mode,
 * shows nothing: that is how every answer was given before this change.
 *
 * @param {object|string|null|undefined} scope The turn's `retrievalScope`.
 * @return {{validated: boolean, abstained: boolean, filtered: boolean, floor: number|null, passed: number, best: number|null}|null} The display record.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-each-answer-records-the-scope-it-was-given-req-rscope-004
 */
export function scopeDisplay(scope) {
	if (!scope || typeof scope !== 'object') {
		return null
	}
	const filtered = hasFilters(scope.fileFilters)
	const validated = scope.validated === true
	if (!validated && !filtered) {
		return null
	}
	return {
		validated,
		abstained: scope.abstained === true,
		filtered,
		floor: finiteOrNull(scope.floor),
		passed: Number.isInteger(scope.passed) ? scope.passed : 0,
		best: finiteOrNull(scope.bestSimilarity),
	}
}

/**
 * Whether a recorded filter object holds any filter.
 *
 * @param {*} filters The recorded filters.
 * @return {boolean}
 */
function hasFilters(filters) {
	return typeof filters === 'object' && filters !== null && Object.keys(filters).length > 0
}

/**
 * A finite number, or null for anything else.
 *
 * @param {*} value The recorded value.
 * @return {number|null}
 */
function finiteOrNull(value) {
	return typeof value === 'number' && Number.isFinite(value) ? value : null
}

/**
 * The reader's locale, or undefined (the runtime's own) when Nextcloud cannot say.
 *
 * @return {string|undefined} For example 'nl-NL'.
 */
function readerLocale() {
	try {
		return getCanonicalLocale()
	} catch {
		return undefined
	}
}

/**
 * A similarity as the two decimals the record is read in, written in the reader's
 * locale (0,41 in Dutch), or '–' when unknown or not a number.
 *
 * @param {number|null} value The similarity.
 * @param {string} [locale] The locale to write it in; the reader's own by default.
 * @return {string} For example '0.41'.
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-each-answer-records-the-scope-it-was-given-req-rscope-004
 */
export function similarityText(value, locale = readerLocale()) {
	if (value === null || value === undefined || value === '') {
		return '–'
	}
	const number = Number(value)
	if (!Number.isFinite(number)) {
		return '–'
	}
	try {
		return new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(number)
	} catch {
		return number.toFixed(2)
	}
}
