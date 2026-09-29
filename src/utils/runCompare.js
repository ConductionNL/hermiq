// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Run comparison helpers (observability-compare-two-runs).
 *
 * Plain functions without Vue or Nextcloud imports, so `tests/run-compare.spec.js`
 * runs them under node. The views pass in their own `t` for every sentence.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */

/** How many runs a comparison takes. */
export const COMPARE_SIZE = 2

/**
 * Tick or untick a run for the comparison.
 *
 * A third tick is refused rather than silently dropping the first one: the person
 * chose two, and replacing one behind their back compares something they did not pick.
 *
 * @param {Array<string>} selected The run ids ticked so far.
 * @param {string} runId The run ticked or unticked.
 * @return {{selected: Array<string>, refused: boolean}} The new selection, and whether the tick was refused.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */
export function toggleSelection(selected, runId) {
	if (selected.includes(runId)) {
		return { selected: selected.filter((id) => id !== runId), refused: false }
	}
	if (selected.length >= COMPARE_SIZE) {
		return { selected: [...selected], refused: true }
	}
	return { selected: [...selected, runId], refused: false }
}

/**
 * A duration in milliseconds as seconds with one decimal ("4.2 s").
 *
 * @param {number} ms The duration in milliseconds.
 * @return {string} The formatted duration.
 */
function seconds(ms) {
	return `${(Math.abs(ms) / 1000).toFixed(1)} s`
}

/**
 * The one-line summary at the top of the comparison.
 *
 * "Same outcome." or "Different outcome.", then the extra calls on either side and
 * the time difference, or where the failing run stopped.
 *
 * @param {object} left The left run (status, durationMs).
 * @param {object} right The right run (status, durationMs).
 * @param {object} comparison The server's comparison ({steps: [{mark, left, right}]}).
 * @param {function(string, string, object=): string} t The translate function, called as t('hermiq', text, vars).
 * @return {string} The summary line.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */
export function summaryLine(left, right, comparison, t) {
	const parts = []
	if (left.status === right.status) {
		parts.push(t('hermiq', 'Same outcome.'))
	} else if (isFailure(left.status) !== isFailure(right.status)) {
		const [ok, failed, failedName] = isFailure(right.status)
			? ['A', 'B', 'right']
			: ['B', 'A', 'left']
		const stoppedAt = lastStep(comparison, failedName)
		parts.push(
			stoppedAt
				? t(
						'hermiq',
						'Different outcome. Run {ok} succeeded, run {failed} failed at {step}.',
						{ ok, failed, step: stoppedAt },
					)
				: t(
						'hermiq',
						'Different outcome. Run {ok} succeeded, run {failed} failed.',
						{ ok, failed },
					),
		)
	} else {
		parts.push(t('hermiq', 'Different outcome.'))
	}

	const extra = extraCalls(comparison)
	for (const [side, label] of [
		['right', 'B'],
		['left', 'A'],
	]) {
		for (const [name, count] of Object.entries(extra[side])) {
			parts.push(
				count === 1
					? t('hermiq', 'Run {run} called {tool} once more.', {
							run: label,
							tool: name,
						})
					: t('hermiq', 'Run {run} called {tool} {count} times more.', {
							run: label,
							tool: name,
							count,
						}),
			)
		}
	}

	const diff = Number(right.durationMs) - Number(left.durationMs)
	if (Number.isFinite(diff) && Math.abs(diff) >= 100) {
		parts.push(
			diff > 0
				? t('hermiq', 'Run B took {time} longer.', { time: seconds(diff) })
				: t('hermiq', 'Run B took {time} less.', { time: seconds(diff) }),
		)
	}

	return parts.join(' ')
}

/**
 * Whether a recorded status is a failure.
 *
 * @param {string} status The run status.
 * @return {boolean} True for a failed run.
 */
function isFailure(status) {
	return ['error', 'failed', 'failure', 'timeout'].includes(
		String(status || '').toLowerCase(),
	)
}

/**
 * The last tool step one side ran, which is where a failed run stopped.
 *
 * @param {object} comparison The comparison.
 * @param {string} side 'left' or 'right'.
 * @return {string} The step name, or ''.
 */
function lastStep(comparison, side) {
	const steps = (comparison?.steps || []).filter((row) => row[side])
	return steps.length ? String(steps[steps.length - 1][side].name || '') : ''
}

/**
 * Count the steps only one side ran, per tool name.
 *
 * @param {object} comparison The comparison.
 * @return {{left: object, right: object}} Tool name to count, per side.
 */
function extraCalls(comparison) {
	const extra = { left: {}, right: {} }
	for (const row of comparison?.steps || []) {
		const side = { 'only-left': 'left', 'only-right': 'right' }[row.mark]
		if (!side) {
			continue
		}
		const name = String(row[side]?.name || '')
		extra[side][name] = (extra[side][name] || 0) + 1
	}
	return extra
}

/**
 * Read a flow run's log into node entries, or null when it cannot be read.
 *
 * OpenRegister writes the node id as `transition` (older entries: `node`), with a
 * status and a duration. Only those three are read, so a change elsewhere in the log
 * shape does not break the comparison; a log with no node id at all is unreadable.
 *
 * @param {object} run The flow run as `GET /apps/openregister/api/flow-runs/{uuid}` returns it.
 * @return {Array<{node: string, status: string, durationMs: (number|null)}>|null} The node entries.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
 */
export function readFlowRunNodes(run) {
	const log = Array.isArray(run?.log) ? run.log : []
	const nodes = log
		.map((entry) => ({
			node: String(entry?.transition ?? entry?.node ?? entry?.nodeId ?? ''),
			status: String(entry?.status ?? ''),
			durationMs: Number.isFinite(entry?.durationMs) ? entry.durationMs : null,
		}))
		.filter((entry) => entry.node !== '')
	return nodes.length ? nodes : null
}

/**
 * Line two flow runs up by node id.
 *
 * Rows follow the left run's node order, then the nodes only the right run reached.
 * A node that ran more than once keeps its last entry, which is its outcome.
 *
 * @param {object} leftRun The left flow run.
 * @param {object} rightRun The right flow run.
 * @return {{rows: Array<object>, unreadable: Array<string>, versions: (Array<number>|null)}} The aligned rows, the sides that could not be read, and the two versions when they differ.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-two-runs-of-a-flow-node-by-node-req-rcmp-003
 */
export function compareFlowRuns(leftRun, rightRun) {
	const left = readFlowRunNodes(leftRun)
	const right = readFlowRunNodes(rightRun)
	const unreadable = [
		[left, 'left'],
		[right, 'right'],
	]
		.filter(([nodes]) => nodes === null)
		.map(([, side]) => side)

	let versions = null
	const known = (value) => value !== null && value !== undefined
	if (
		known(leftRun?.flowVersion)
		&& known(rightRun?.flowVersion)
		&& leftRun.flowVersion !== rightRun.flowVersion
	) {
		versions = [leftRun.flowVersion, rightRun.flowVersion]
	}

	if (unreadable.length) {
		return { rows: [], unreadable, versions }
	}

	const byNode = (nodes) => new Map(nodes.map((entry) => [entry.node, entry]))
	const leftBy = byNode(left)
	const rightBy = byNode(right)
	const order = [
		...new Set([...left.map((e) => e.node), ...right.map((e) => e.node)]),
	]
	const rows = order.map((node) => {
		const l = leftBy.get(node) || null
		const r = rightBy.get(node) || null
		return {
			node,
			left: l,
			right: r,
			differs: !l || !r || l.status !== r.status,
		}
	})
	return { rows, unreadable, versions }
}
