/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The branch arithmetic of a flow canvas, as pure functions.
 *
 * WHY THIS IS ITS OWN MODULE
 * --------------------------
 * Three things depend on "which branches does this node have", and they must
 * agree exactly:
 *
 *   - the out-ports drawn on a routing node
 *   - the branch recorded on a connection the author draws (`edge.fromExit`)
 *   - the detection of an edge whose branch no longer exists
 *
 * Derive that list twice and the third eventually disagrees with the first: an
 * edge gets marked unassigned while the port it points at is still on screen,
 * which reads as a bug in the canvas rather than in the arithmetic. One source,
 * imported by all of them.
 *
 * It is also the half worth unit-testing, and `flowEditor.js` cannot be loaded
 * in a test without pinia and the whole API layer coming with it.
 *
 * @spec openspec/specs/flow-canvas/spec.md
 */

/**
 * Step types that send items down a named branch.
 *
 * The registered id is `openregister.route`, NOT `...router` — the node class
 * is `RouterNode`, and guessing the id from the class name yields a type no
 * flow uses, which would silently mean no branches were ever derived and every
 * routing node drew a single anonymous exit.
 */
export const ROUTER_STEP_TYPES = ['openregister.route']

/**
 * The branches a node sends items down, in order, deduplicated.
 *
 * Read from `config.rules[].output` plus `config.default`, which is what
 * RouterNode itself reads.
 *
 * `config.routes` is deliberately NOT honoured. It is the most common way to
 * author this node wrong — its entries are `when`/`to` where the node reads
 * `condition`/`output` — and deriving ports from it would draw a correct-looking
 * canvas over a configuration the engine ignores.
 *
 * @param {object} node The node.
 * @return {Array<string>} The branch names.
 * @spec openspec/specs/flow-canvas/spec.md
 */
export function branchesOfNode(node) {
	if (!node || !ROUTER_STEP_TYPES.includes(node.type)) {
		return []
	}

	const rules = ((node.config || {}).rules) || []
	if (!Array.isArray(rules)) {
		return []
	}

	const names = []
	rules.forEach((rule) => {
		const output = String(((rule || {}).output) ?? '').trim()
		if (output !== '' && !names.includes(output)) {
			names.push(output)
		}
	})

	const fallback = String(((node.config || {}).default) ?? '').trim()
	if (fallback !== '' && !names.includes(fallback)) {
		names.push(fallback)
	}

	return names
}

/**
 * The branch name behind a canvas port id, or `''` for an unbranched exit.
 *
 * The canvas names a branch port `out:<branch>`, a plain exit `out`, and a
 * loop's body ports `body-out`/`body-in`. Only the first carries a branch, so
 * everything else answers empty rather than inventing one — a `fromExit` of
 * `body-out` matches no rule in the routing node and would route the token
 * nowhere at all.
 *
 * @param {string} [portId] The port the author connected from.
 * @return {string} The branch name, or an empty string.
 * @spec openspec/specs/flow-canvas/spec.md
 */
export function branchOfPort(portId) {
	const id = String(portId ?? '')

	return id.startsWith('out:') ? id.slice(4).trim() : ''
}

/**
 * Edges whose branch no longer exists on the node they leave.
 *
 * Editing a routing node's `rules[]` can remove a branch that edges were
 * already drawn from. Those edges are NOT deleted anywhere in this codebase:
 * silently removing a connection the author drew, because a value changed in a
 * different panel, loses work with no trace and leaves them unable to tell an
 * edge they forgot from one the editor took away.
 *
 * An edge with no `fromExit` is never orphaned — it leaves the unbranched exit,
 * which every node has.
 *
 * @param {Array<object>} nodes The flow's nodes.
 * @param {Array<object>} edges The flow's edges, with `from` normalised to an array.
 * @return {Array<string>} The offending edge ids, in document order.
 * @spec openspec/specs/flow-canvas/spec.md
 */
export function orphanedBranchEdgeIds(nodes, edges) {
	const branches = {}
	for (const node of (nodes || [])) {
		branches[node.id] = branchesOfNode(node)
	}

	const orphans = []
	for (const edge of (edges || [])) {
		const exit = String((edge || {}).fromExit || '').trim()
		if (exit === '') {
			continue
		}

		const from = Array.isArray(edge.from) ? edge.from : [edge.from]

		// EVERY source it leaves must still offer the branch. A split edge with
		// several sources is orphaned when ANY of them lost it, because the
		// token would silently stop reaching that one while the line still
		// looked intact.
		const lost = from.some((id) => (branches[id] || []).includes(exit) === false)
		if (lost === true) {
			orphans.push(edge.id)
		}
	}

	return orphans
}
