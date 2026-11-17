/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The fallback chain of a model policy as the Tenant operations editor holds
 * it: an ordered list of {provider, model} rows the admin adds, reorders and
 * removes. The server validates every entry against the policy and refuses
 * one it does not allow; these helpers only shape the list.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */

/** The most fallbacks a policy names, as the server enforces. */
export const MAX_FALLBACKS = 4

/**
 * The editable rows of a stored chain.
 *
 * @param {Array<object>|undefined} chain The policy's `fallbackChain`.
 * @return {Array<{provider: string, model: string}>} Fresh rows.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */
export function fallbackRows(chain) {
	if (!Array.isArray(chain)) {
		return []
	}
	return chain.map((entry) => ({
		provider: String(entry?.provider ?? ''),
		model: String(entry?.model ?? ''),
	}))
}

/**
 * The rows with one empty row added at the end, unless the list is full.
 *
 * @param {Array<object>} rows The rows.
 * @return {Array<object>} New rows.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */
export function addFallback(rows) {
	if (rows.length >= MAX_FALLBACKS) {
		return rows
	}
	return [...rows, { provider: '', model: '' }]
}

/**
 * The rows without the one at `index`.
 *
 * @param {Array<object>} rows The rows.
 * @param {number} index The row to remove.
 * @return {Array<object>} New rows.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */
export function removeFallback(rows, index) {
	return rows.filter((row, at) => at !== index)
}

/**
 * The rows with the one at `index` moved by `delta` places; a move past
 * either end leaves the rows as they are.
 *
 * @param {Array<object>} rows The rows.
 * @param {number} index The row to move.
 * @param {number} delta -1 for up, 1 for down.
 * @return {Array<object>} New rows.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */
export function moveFallback(rows, index, delta) {
	const target = index + delta
	if (index < 0 || index >= rows.length || target < 0 || target >= rows.length) {
		return rows
	}
	const moved = [...rows]
	const [row] = moved.splice(index, 1)
	moved.splice(target, 0, row)
	return moved
}

/**
 * The chain to save: trimmed rows, in order, without fully empty rows.
 * A half-filled row is kept so the server names it as refused.
 *
 * @param {Array<object>} rows The rows.
 * @return {Array<{provider: string, model: string}>} The `fallbackChain` payload.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */
export function fallbackPayload(rows) {
	return rows
		.map((row) => ({
			provider: String(row.provider ?? '').trim(),
			model: String(row.model ?? '').trim(),
		}))
		.filter((row) => row.provider !== '' || row.model !== '')
}

/**
 * A one-line summary of a chain, `provider/model → provider/model`.
 *
 * @param {Array<object>|undefined} chain The policy's `fallbackChain`.
 * @return {string} The summary, empty for no chain.
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-model-policy-may-name-a-fallback-chain-req-mfail-001
 */
export function fallbackSummary(chain) {
	return fallbackRows(chain)
		.map((entry) => `${entry.provider}/${entry.model}`)
		.join(' → ')
}
