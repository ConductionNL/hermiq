/**
 * Reading a tool id as a verb and a subject.
 *
 * Lifted out of ToolGrantMatrix.vue so it can be tested. It was the one piece
 * of that component with no DOM in it and the only piece that had been WRONG
 * without anyone noticing: a single-file component's methods cannot be reached
 * from `node tests/*.spec.js`, so 35 of 87 tools parsed incorrectly through a
 * green build, a green lint and a passing e2e suite. Untestable logic is where
 * that hides.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

/**
 * The canonical columns, in reading order.
 *
 * Every cluster shows all five even where a verb is unused, so a row means the
 * same thing in every foldout and the eye can run down a column. `list` and
 * `read` are separate because "list the leads" and "open this lead" are
 * different rights.
 *
 * @type {Array<string>}
 */
export const CANONICAL_VERBS = ['create', 'read', 'update', 'list', 'delete']

/**
 * How a tool's own verb maps onto a canonical column.
 *
 * ⚠️ The taxonomy's `right` field is too coarse to drive this: it reports both
 * `lead_search` and `lead_get` as `read`, so both would land in one column and
 * one would overwrite the other. The verb in the tool's NAME is the finer
 * signal, so it wins, and `right` is only the fallback.
 *
 * @type {Object<string, string>}
 */
export const VERB_ALIASES = {
	create: 'create', add: 'create', new: 'create',
	list: 'list', search: 'list', find: 'list',
	read: 'read', get: 'read', fetch: 'read',
	update: 'update', edit: 'update', set: 'update', upsert: 'update',
	delete: 'delete', remove: 'delete', destroy: 'delete',
}

/**
 * Words that are genuinely VERBS but have no canonical column.
 *
 * They still split verb-from-subject, so `delegateAgent` becomes agent/delegate.
 *
 * ⚠️ The split only happens for a word on this list or in VERB_ALIASES.
 * Splitting on any leading lowercase run turned `webFetch` into subject "fetch"
 * with special "web" — "web" is not a verb, and the row named a thing that does
 * not exist. When the leading word is not a known verb the whole name IS the
 * action, and saying so is better than inventing a subject.
 *
 * @type {Set<string>}
 */
export const SPECIAL_VERBS = new Set([
	'delegate', 'recommend', 'remember', 'recall', 'forget',
	'send', 'convert', 'generate', 'log', 'promote', 'request',
	'start', 'publish', 'render', 'sign', 'validate',
])

/**
 * The key both endpoints agree on.
 *
 * `.` and `_` are the same separator wearing different hats —
 * `pipelinq.lead.search` and `pipelinq_lead_search` are one tool. Only the
 * separator is normalised, deliberately: stripping or lowercasing more would
 * start merging tools that genuinely differ.
 *
 * @param {string} id The tool id in either spelling.
 * @return {string} The normalised join key.
 */
export function joinKey(id) {
	return String(id ?? '').replace(/\./g, '_')
}

/**
 * Singularise an English plural, well enough for a row label.
 *
 * @param {string} word The possibly-plural word.
 * @return {string} The singular form.
 */
export function singularise(word) {
	if (/[^aeiou]ies$/.test(word) === true) {
		return word.slice(0, -3) + 'y'
	}

	// ⚠️ `s` is deliberately NOT in this group. With it, "courses" became
	// "cours" — the rule assumed a sibilant stem plus "es", but the word is
	// "course" plus "s". Genuine sibilant stems (box, church) keep the
	// two-character strip; anything ending in "ses" falls through to the plain
	// rule below and loses only its "s".
	if (/(x|z|ch|sh)es$/.test(word) === true) {
		return word.slice(0, -2)
	}

	if (/[^s]s$/.test(word) === true) {
		return word.slice(0, -1)
	}

	return word
}

/**
 * Put a raw verb into its canonical column, or mark it special.
 *
 * @param {string} verb    The verb as written in the tool id.
 * @param {string} subject The subject the verb acts on.
 * @return {{verb: string, subject: string, specialLabel: string|null}} The classification.
 */
export function classify(verb, subject) {
	const canonical = VERB_ALIASES[verb] ?? null
	if (canonical !== null) {
		return { verb: canonical, subject, specialLabel: null }
	}

	return { verb: 'special', subject, specialLabel: verb }
}

/**
 * Find the segment that is a verb, and take the rest as the subject.
 *
 * Locating the verb rather than assuming its position is what lets one rule
 * cover both orderings. The two shapes are told apart by the only thing that
 * actually distinguishes them: `app_subject_verb` puts a known verb LAST with
 * at least two segments ahead of it, so the lead segment can be dropped as the
 * app prefix. Anything else with a verb segment is verb-first, and everything
 * after the verb is the subject.
 *
 * ⚠️ Only an EXACT segment match counts as a verb. Matching a prefix would make
 * `listFiles` a verb segment and leave no subject behind it; that name is
 * camelCase and belongs to the caller's next branch.
 *
 * ⚠️ Only the verb-FIRST subject is singularised. A schema-derived subject is
 * already singular by construction, and the rule is lossy on a stem that merely
 * ends in "s" — it would turn a `status` subject into "statu". Plurals only
 * ever arrive from the hand-written side (`list_registers`), so that is the
 * only side that gets the rule.
 *
 * @param {Array<string>} parts The id split on separators.
 * @return {{verb: string, subject: string}|null} The split, or null when no segment is a verb.
 */
export function splitOnVerbSegment(parts) {
	const index = parts.findIndex(part =>
		VERB_ALIASES[part] !== undefined || SPECIAL_VERBS.has(part) === true)

	if (index === -1) {
		return null
	}

	const isLast = index === (parts.length - 1)
	if (isLast === true && index >= 2) {
		return { verb: parts[index], subject: parts.slice(1, index).join('_') }
	}

	const subject = parts.slice(index + 1).join('_')
	if (subject === '') {
		return null
	}

	return { verb: parts[index], subject: singularise(subject) }
}

/**
 * Split a tool id into the column it belongs in and the thing it acts on.
 *
 * ⚠️ A tool id carries a VERB and a SUBJECT, and the first cut of this widget
 * used the whole thing as the subject — so `createNote`, `createCalendarEvent`
 * and `listFiles` each became their own row with a single tick in one column,
 * instead of `note`, `calendarEvent` and `file` rows with the verb in its
 * column. The grid degenerated into the flat list it was meant to replace, one
 * tool per line.
 *
 * Three id shapes exist and all are parsed here:
 *   `pipelinq.lead.search`  → app, subject, verb          (schema-derived)
 *   `hermiq.createNote`     → app, camelCase verb+subject (hand-written)
 *   `list_registers`        → snake verb+subject          (hand-written)
 *
 * 🔴 The third shape used to be parsed as the FIRST, which inverted it. The
 * verb-last rule was written for schema-derived ids — but every schema-derived
 * tool now DECLARES its subject and action and returns before reaching it, so
 * verb-last no longer receives the shape it was written for. What it does
 * receive is hand-written snake ids, where the verb comes FIRST, and it read
 * them backwards: `cms_create_page` became the subject "create" with a special
 * verb "page". Measured on the live catalogue, 35 of 87 undeclared tools parsed
 * wrong — 5 inverted this way, and 30 two-segment ones (`list_registers`,
 * `get_register`, the entire OpenRegister core) lost their verb altogether and
 * rendered as 30 one-off rows instead of 6 subjects with 5 columns.
 *
 * A verb with no canonical column is a SPECIAL: it keeps its own name so the
 * checkbox can be labelled with what it actually grants.
 *
 * @param {string} id       The tool id, in any of the three spellings.
 * @param {object} taxonomy The taxonomy row for this tool, if any.
 * @return {{verb: string, subject: string, specialLabel: string|null}} The parse.
 */
export function parseVerbAndSubject(id, taxonomy = {}) {
	// 🔑 If the producer DECLARED its subject and action, use them. The
	// structure is data, not something to be recovered from a string:
	// OpenRegister composes `{app}.{subject}.{action}` and now publishes all
	// three as fields, so 90 of 177 tools need no parsing at all.
	//
	// Everything below this point is a FALLBACK for tools that declare nothing,
	// and it is guesswork. The fix for those is to declare their subject and
	// action at the source, not to improve the guessing.
	if (typeof taxonomy.subject === 'string' && taxonomy.subject !== ''
		&& typeof taxonomy.action === 'string' && taxonomy.action !== '') {
		return classify(taxonomy.action, taxonomy.subject)
	}

	const parts = joinKey(id).split('_').filter(Boolean)

	// A verb that is its OWN segment needs no camelCase splitting.
	const segmented = splitOnVerbSegment(parts)
	if (segmented !== null) {
		return classify(segmented.verb, segmented.subject)
	}

	// `app_camelCaseName` — hand-written. Split the leading lowercase run off as
	// the verb; whatever follows is the subject.
	const name = parts.length === 2 ? parts[1] : (parts[0] ?? String(id))
	const match = name.match(/^([a-z]+)([A-Z].*)$/)
	const leading = match === null ? null : match[1]
	const isVerb = leading !== null
		&& (VERB_ALIASES[leading] !== undefined || SPECIAL_VERBS.has(leading) === true)

	if (isVerb === true) {
		const subject = match[2].charAt(0).toLowerCase() + match[2].slice(1)
		return classify(leading, singularise(subject))
	}

	// Not a verb+subject at all (`webFetch`, `pipelineForecast`): the whole name
	// is the action, and it names itself.
	if (match !== null) {
		return { verb: 'special', subject: name, specialLabel: name }
	}

	// No verb to find — fall back to the taxonomy's coarse right, and let the
	// tool name stand as its own subject rather than inventing one.
	return classify(taxonomy.right ?? 'special', name)
}
