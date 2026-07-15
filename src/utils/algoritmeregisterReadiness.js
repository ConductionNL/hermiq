// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure publish-readiness gate for the Settings "Algorithm register" page
// (inapp-settings-section, delta spec on algoritmeregister-publication).
//
// Mirrors `AlgoritmekaderMapper::MANDATORY_FIELDS` server-side and the
// UX-hint conditions `AiFeatureRegister.vue` already checks
// (`missingConditions()`/`publishReady()`/`publishBlockedReason()`) — the
// server-side gate remains authoritative either way; this is client-side
// UX only (disables the Publish button with a named reason before the
// caller even tries).
//
// A fresh copy, NOT shared with `AiFeatureRegister.vue` — that file is owned
// by the concurrently in-flight `ai-features-to-admin` change; editing it
// here would risk a merge conflict or a false inter-change dependency (see
// design.md Decision 5). Consolidating both call sites onto one shared util
// is a natural follow-up once `ai-features-to-admin` has landed.
//
// Unlike `AiFeatureRegister.vue`'s version, this module does not check
// `riskCategory` — the Algorithm register page only ever lists
// `riskCategory: "high"` features (already filtered before this gate runs),
// so re-checking it here would always be a no-op.

/** The Algoritmekader fields mandatory to publish (mirrors AlgoritmekaderMapper::MANDATORY_FIELDS). */
export const MANDATORY_ALGORITMEKADER_FIELDS = [
	'doel',
	'wettelijkeGrondslag',
	'impacttoetsen',
	'dataBronnen',
	'menselijkeTussenkomst',
	'verantwoordelijke',
	'publicatiecategorie',
]

/**
 * Condition identifier for "the feature must be enabled" — translated by the
 * caller (component) when rendering, not by this pure-logic module.
 *
 * @type {string}
 */
export const CONDITION_ENABLED = 'enabled'

/**
 * Condition identifier for "the feature must be DPO-acknowledged".
 *
 * @type {string}
 */
export const CONDITION_DPO_ACK = 'dpoAck'

/**
 * The list of failing publish-readiness conditions for an AiFeature record.
 * Entries are either a condition identifier (`CONDITION_ENABLED`,
 * `CONDITION_DPO_ACK` — translate these for display) or a raw Algoritmekader
 * field name (already language-agnostic, shown as-is, mirroring
 * `AiFeatureRegister.vue`'s behaviour).
 *
 * @param {object} feature The AiFeature record (already known to be `riskCategory: "high"`).
 * @return {Array<string>} The failing condition identifiers/field names (empty = ready).
 */
export function missingConditions(feature) {
	const record = feature || {}
	const missing = []
	if ((record.lifecycle || 'disabled') !== 'enabled') {
		missing.push(CONDITION_ENABLED)
	}
	if (!record.dpoAckAt) {
		missing.push(CONDITION_DPO_ACK)
	}
	for (const field of MANDATORY_ALGORITMEKADER_FIELDS) {
		const value = record[field]
		const present = Array.isArray(value) ? value.length > 0 : Boolean(value)
		if (!present) {
			missing.push(field)
		}
	}
	return missing
}

/**
 * Whether a feature satisfies the publish-readiness gate client-side.
 *
 * @param {object} feature The AiFeature record.
 * @return {boolean} True when no condition is missing.
 */
export function isPublishReady(feature) {
	return missingConditions(feature).length === 0
}
