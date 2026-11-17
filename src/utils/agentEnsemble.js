/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * An agent's ensemble as the agent form holds it: a switch and two or three
 * reference models, each a {provider, model} pair. The server checks every
 * pair against the organisation's model policy and refuses one it does not
 * allow; these helpers only shape the setting.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */

/** The fewest reference models an enabled ensemble names, as the server enforces. */
export const MIN_REFERENCES = 2

/** The most reference models an ensemble names, as the server enforces. */
export const MAX_REFERENCES = 3

/**
 * The form's copy of a stored ensemble.
 *
 * @param {object|undefined} ensemble The agent's `ensemble`.
 * @return {{enabled: boolean, referenceModels: Array<{provider: string, model: string}>}} Fresh rows.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function ensembleOf(ensemble) {
	const references = Array.isArray(ensemble?.referenceModels) ? ensemble.referenceModels : []
	return {
		enabled: ensemble?.enabled === true,
		referenceModels: references.map((entry) => ({
			provider: String(entry?.provider ?? ''),
			model: String(entry?.model ?? ''),
		})),
	}
}

/**
 * The ensemble switched on or off. Switching on with fewer than two rows adds
 * empty rows up to two, so the owner sees what to fill in.
 *
 * @param {object} ensemble The form's ensemble.
 * @param {boolean} enabled The new switch position.
 * @return {object} A new ensemble.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function switchEnsemble(ensemble, enabled) {
	const references = [...ensemble.referenceModels]
	while (enabled && references.length < MIN_REFERENCES) {
		references.push({ provider: '', model: '' })
	}
	return { enabled, referenceModels: references }
}

/**
 * The ensemble with one empty reference row added, unless it is full.
 *
 * @param {object} ensemble The form's ensemble.
 * @return {object} A new ensemble.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function addReference(ensemble) {
	if (ensemble.referenceModels.length >= MAX_REFERENCES) {
		return ensemble
	}
	return { ...ensemble, referenceModels: [...ensemble.referenceModels, { provider: '', model: '' }] }
}

/**
 * The ensemble without the reference at `index`.
 *
 * @param {object} ensemble The form's ensemble.
 * @param {number} index The row to remove.
 * @return {object} A new ensemble.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function removeReference(ensemble, index) {
	return { ...ensemble, referenceModels: ensemble.referenceModels.filter((row, at) => at !== index) }
}

/**
 * The ensemble with one field of the reference at `index` changed. A new
 * provider clears the model, which belonged to the old one.
 *
 * @param {object} ensemble The form's ensemble.
 * @param {number} index The row.
 * @param {string} field `provider` or `model`.
 * @param {string} value The new value.
 * @return {object} A new ensemble.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function setReference(ensemble, index, field, value) {
	return {
		...ensemble,
		referenceModels: ensemble.referenceModels.map((row, at) => {
			if (at !== index) {
				return row
			}
			if (field === 'provider') {
				return { provider: value, model: row.provider === value ? row.model : '' }
			}
			return { ...row, model: value }
		}),
	}
}

/**
 * The ensemble to save: trimmed rows in order, without fully empty rows. A
 * half-filled row is kept so the server names it as refused.
 *
 * @param {object} ensemble The form's ensemble.
 * @return {{enabled: boolean, referenceModels: Array<{provider: string, model: string}>}} The `ensemble` payload.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function ensemblePayload(ensemble) {
	return {
		enabled: ensemble.enabled === true,
		referenceModels: ensemble.referenceModels
			.map((row) => ({
				provider: String(row.provider ?? '').trim(),
				model: String(row.model ?? '').trim(),
			}))
			.filter((row) => row.provider !== '' || row.model !== ''),
	}
}

/**
 * Whether the form should send `ensemble`: when it is on, or when the agent
 * already had one (so switching it off is saved). An agent that never had an
 * ensemble is saved without the field.
 *
 * @param {object} ensemble The form's ensemble.
 * @param {object|undefined} stored The stored `ensemble`, if any.
 * @return {boolean} True when the payload carries it.
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function sendsEnsemble(ensemble, stored) {
	return ensemble.enabled === true || (stored !== undefined && stored !== null)
}

/**
 * The agent's ensemble in one line, for the agent's Configuration card.
 *
 * @param {object|undefined} ensemble The agent's `ensemble`.
 * @param {function(string, string, string, number, object): string} n The app's plural function, `n('hermiq', ...)`.
 * @param {function(string, string): string} t The app's translate function, `t('hermiq', text)`.
 * @return {string} "Ensemble: 2 reference models", or "Off".
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
 */
export function ensembleSummary(ensemble, n, t) {
	const setting = ensembleOf(ensemble)
	const count = setting.referenceModels.length
	if (!setting.enabled || count === 0) {
		return t('hermiq', 'Off')
	}
	return n('hermiq', 'Ensemble: {count} reference model', 'Ensemble: {count} reference models', count, { count })
}
