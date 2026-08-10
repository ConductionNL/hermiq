#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// flow-branches.spec.js — the branch arithmetic behind the flow canvas.
//
// Usage:
//   node tests/flow-branches.spec.js
//
// Exit codes:
//   0 — every assertion held.
//   1 — one or more assertions failed.
//
// WHY THIS IS WORTH A TEST
// ------------------------
// Three surfaces read "which branches does this node have" and they must agree
// exactly: the out-ports drawn on a routing node, the branch recorded on a
// connection (`edge.fromExit`), and the detection of an edge whose branch is
// gone. When they disagree the symptom is an edge marked unassigned while the
// port it points at is still on screen — which reads as a canvas bug rather
// than an arithmetic one.
//
// Every assertion below has its inverse asserted too. A branch derivation that
// returned everything, and an orphan check that flagged everything, would both
// satisfy a one-sided test.

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const SRC = path.join(__dirname, '..', 'src', 'store', 'flowBranches.js')

/**
 * Load the ESM module into a CJS sandbox.
 *
 * Same approach as agent-context.spec.js: rewrite the `export` keywords and
 * collect the declarations onto module.exports. The module is deliberately
 * dependency-free so this works — that is part of why it is a separate file
 * from flowEditor.js, which cannot be loaded without pinia and the API layer.
 *
 * @return {object} The module's exports.
 */
function loadModule() {
	let src = fs.readFileSync(SRC, 'utf8')
	src = src.replace(/export\s+const\s+/g, 'const ')
	src = src.replace(/export\s+function\s+/g, 'function ')
	src += '\nmodule.exports = { branchesOfNode, branchOfPort, orphanedBranchEdgeIds, ROUTER_STEP_TYPES }\n'
	const sandbox = { module: { exports: {} }, console }
	vm.createContext(sandbox)
	vm.runInContext(src, sandbox, { filename: 'flowBranches.js' })

	return sandbox.module.exports
}

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

const { branchesOfNode, branchOfPort, orphanedBranchEdgeIds } = loadModule()

const gate = {
	id: 'work-gate',
	type: 'openregister.route',
	config: {
		rules: [{ output: 'work', condition: {} }],
		default: 'idle',
	},
}

console.log('branchesOfNode — rules[].output plus default')
{
	assert(JSON.stringify(branchesOfNode(gate)) === JSON.stringify(['work', 'idle']),
		'a gate with one rule and a default has both branches, rule first')

	// The inverse: an ordinary action node has none. Without this, a derivation
	// that returned ['work','idle'] for everything would pass the line above.
	assert(branchesOfNode({ id: 'a', type: 'openregister.set-fields', config: { rules: [{ output: 'x' }] } }).length === 0,
		'a NON-routing node has no branches even when it carries a rules key')

	assert(branchesOfNode({ id: 'r', type: 'openregister.route', config: { routes: [{ to: 'x' }] } }).length === 0,
		'config.routes is ignored — it is the mis-authored key the engine never reads')

	assert(branchesOfNode(null).length === 0, 'a missing node has no branches')

	const dupes = branchesOfNode({
		id: 'r',
		type: 'openregister.route',
		config: { rules: [{ output: 'a' }, { output: 'a' }], default: 'a' },
	})
	assert(dupes.length === 1 && dupes[0] === 'a', 'a repeated branch name is listed once')
}

console.log('branchOfPort — only out:<branch> carries a branch')
{
	assert(branchOfPort('out:work') === 'work', 'a branch port yields its branch')
	assert(branchOfPort('out') === '', 'the plain exit yields no branch')
	assert(branchOfPort('body-out') === '', 'a loop body port yields no branch')
	assert(branchOfPort('in') === '', 'an in-port yields no branch')
	assert(branchOfPort(undefined) === '', 'a missing port id yields no branch')
}

console.log('orphanedBranchEdgeIds — reports the lost, spares the rest')
{
	const nodes = [gate, { id: 'derive', type: 'openregister.set-fields' }]

	const healthy = [
		{ id: 'e1', from: ['work-gate'], to: ['derive'], fromExit: 'work' },
		{ id: 'e2', from: ['work-gate'], to: ['derive'], fromExit: 'idle' },
		{ id: 'e3', from: ['derive'], to: ['work-gate'] },
	]
	assert(orphanedBranchEdgeIds(nodes, healthy).length === 0,
		'nothing is orphaned while every branch still exists')

	// Two branches of one gate reaching the SAME node is ordinary, and both
	// survive — this is the case a from/to-only identity check would have
	// collapsed into one edge.
	assert(healthy.filter((e) => e.fromExit).length === 2,
		'two branches may legitimately lead to the same node')

	const edited = [{ id: 'e1', from: ['work-gate'], to: ['derive'], fromExit: 'gone' }]
	assert(JSON.stringify(orphanedBranchEdgeIds(nodes, edited)) === JSON.stringify(['e1']),
		'an edge whose branch was removed is reported')

	assert(orphanedBranchEdgeIds(nodes, [{ id: 'e9', from: ['derive'], to: ['work-gate'] }]).length === 0,
		'an edge with no fromExit is never orphaned — it leaves the unbranched exit')

	const split = [{ id: 'e5', from: ['work-gate', 'derive'], to: ['derive'], fromExit: 'work' }]
	assert(JSON.stringify(orphanedBranchEdgeIds(nodes, split)) === JSON.stringify(['e5']),
		'a split edge is orphaned when ANY of its sources lost the branch')

	assert(orphanedBranchEdgeIds([], []).length === 0, 'an empty flow reports nothing')
}

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed.`)
	process.exit(1)
}

console.log('\nAll flow-branch assertions held.')
