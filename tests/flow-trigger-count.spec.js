#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// flow-trigger-count.spec.js — the sidebar's "entry points" figure.
//
// Usage:
//   node tests/flow-trigger-count.spec.js
//
// Exit codes:
//   0 — every assertion held.
//   1 — one or more assertions failed.
//
// WHY THIS IS WORTH A TEST
// ------------------------
// `FlowSidebar.triggerNodeCount` used to answer by testing whether the node's
// type string contained `.trigger-`. That is the hardcoded-list problem
// OpenRegister's published `role` field exists to remove, reintroduced one
// layer up: a trigger contributed by an app whose ids do not happen to spell it
// that way counted as ZERO, so the panel told the author their flow had no
// entry point while the engine started it perfectly well. A wrong count here is
// quiet — "0 entry points" reads exactly like a flow nobody has finished yet.
//
// The first assertion below FAILS against the old implementation, which is the
// point of adding it: a regression test that also passes on the bug it
// describes is not a regression test. Its inverse is asserted too, so an
// implementation that counted everything could not pass either.
//
// The naming-convention fallback is asserted as well, because it is not dead
// code — it is what answers while the catalogue is still loading, and deleting
// it would draw every node as a step for the first frames.

const fs = require('fs')
const path = require('path')

const SIDEBAR = path.join(__dirname, '..', 'src', 'views', 'FlowSidebar.vue')
const STORE = path.join(__dirname, '..', 'src', 'store', 'flowEditor.js')

let failures = 0

/**
 * Assert one condition.
 *
 * @param {boolean} cond    What must hold.
 * @param {string}  message What it means.
 * @return {void}
 */
function assert(cond, message) {
	if (cond) {
		console.log('  ✓ ' + message)
	} else {
		failures++
		console.error('  ✗ ' + message)
	}
}

// ── The role resolver, as the store defines it ───────────────────────────────
//
// flowEditor.js cannot be imported here: it is a pinia store that pulls in the
// API layer. The getter it defines is small and self-contained, so the spec
// asserts against a transcription and then asserts that the transcription still
// matches the source — that second check is what stops this file drifting into
// testing a copy nobody ships.

/**
 * Reference implementation of the store's role resolution.
 *
 * @param {Array}  catalog The node catalogue.
 * @param {string} type    The node type id.
 * @return {string} 'trigger', 'end' or 'step'.
 */
function roleOfNodeType(catalog, type) {
	const id = String(type || '')
	const entry = (catalog || []).find((e) => e && e.id === id)

	if (entry !== undefined && entry.role) {
		return entry.role
	}

	if (id.includes('.trigger-')) {
		return 'trigger'
	}

	if (id.endsWith('.end') || id.endsWith('.stop')) {
		return 'end'
	}

	return 'step'
}

/**
 * The count under test, expressed the way the sidebar expresses it.
 *
 * @param {Array} nodes   The flow's nodes.
 * @param {Array} catalog The node catalogue.
 * @return {number} How many nodes are triggers.
 */
function triggerNodeCount(nodes, catalog) {
	return nodes.filter((node) => roleOfNodeType(catalog, node.type) === 'trigger').length
}

console.log('triggerNodeCount — asks the engine, does not guess from the id')
{
	// A trigger contributed by another app. Its id contains neither `.trigger-`
	// nor anything else the old substring test looked for; the ONLY thing that
	// says it starts a flow is the role the engine published. This assertion
	// fails against the previous implementation.
	const foreignTrigger = [{ id: 'n1', type: 'acme.on-invoice-paid' }]
	const catalog = [{ id: 'acme.on-invoice-paid', role: 'trigger' }]
	assert(triggerNodeCount(foreignTrigger, catalog) === 1,
		'a trigger whose id does not spell "trigger" is counted, because the catalogue says role=trigger')

	// The inverse. Without this, an implementation that counted every node
	// would satisfy the line above.
	const stepOnly = [{ id: 'n1', type: 'acme.render-pdf' }]
	assert(triggerNodeCount(stepOnly, [{ id: 'acme.render-pdf', role: 'step' }]) === 0,
		'a step is not counted, so the figure is not simply the node total')

	// A node the catalogue calls a step must NOT be rescued by its id. This is
	// the direction that keeps `role` authoritative rather than advisory.
	const misleadingId = [{ id: 'n1', type: 'acme.trigger-lookalike' }]
	assert(triggerNodeCount(misleadingId, [{ id: 'acme.trigger-lookalike', role: 'step' }]) === 0,
		'the published role beats the id: a "trigger-" id declared a step is not counted')
}

console.log('triggerNodeCount — the naming-convention fallback still answers')
{
	// The catalogue has not loaded yet. Degrading to "everything is a step"
	// here would draw a trigger as an ordinary node for the first frames.
	const builtin = [{ id: 'n1', type: 'openregister.trigger-object' }]
	assert(triggerNodeCount(builtin, []) === 1,
		'with no catalogue, an openregister.trigger-* node still counts')

	assert(triggerNodeCount([{ id: 'n1', type: 'openregister.end' }], []) === 0,
		'with no catalogue, an end node is not mistaken for a trigger')
}

console.log('the shipped code matches what this spec asserts against')
{
	const sidebar = fs.readFileSync(SIDEBAR, 'utf8')
	assert(/triggerNodeCount\(\)\s*\{[\s\S]{0,220}roleOfNodeType\(node\.type\)\s*===\s*'trigger'/.test(sidebar),
		'FlowSidebar.triggerNodeCount resolves the role through the store, not a substring of the id')
	assert(!/triggerNodeCount\(\)\s*\{[\s\S]{0,220}includes\('\.trigger-'\)/.test(sidebar),
		'FlowSidebar.triggerNodeCount no longer substring-matches the type id')

	const store = fs.readFileSync(STORE, 'utf8')
	assert(/roleOfNodeType:\s*\(state\)\s*=>\s*\(type\)\s*=>/.test(store),
		'the store still exposes roleOfNodeType for the sidebar to call')
	assert(/entry\s*!==\s*undefined\s*&&\s*entry\.role/.test(store),
		'the store still prefers the published role over the naming convention')
}

if (failures > 0) {
	console.error('\nflow-trigger-count: ' + failures + ' assertion(s) failed.')
	process.exit(1)
}

console.log('\nflow-trigger-count: all assertions held.')
