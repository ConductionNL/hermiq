/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How GuardrailPolicySettings turns a policy into its edit draft and one-line
 * summary, and a draft back into the policy's filters and tool rules.
 */

import { t } from '@nextcloud/l10n'

/**
 * A policy's tool rules, or [] when it holds none as a list.
 *
 * @param {object} policy The GuardrailPolicy record.
 * @return {Array<object>} The rules.
 * @spec openspec/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
 */
function toolRulesOf(policy) {
	return Array.isArray(policy.toolPolicy) ? policy.toolPolicy : []
}

/**
 * The input and output filters a draft describes.
 *
 * @param {object} draft The form draft.
 * @return {{inputFilters: object, outputFilters: object}} The filters.
 * @spec openspec/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
 */
export function filtersFromDraft(draft) {
	return {
		inputFilters: {
			piiAction: draft.inputPiiAction,
			promptInjectionAction: draft.inputPromptInjectionAction,
		},
		outputFilters: {
			piiAction: draft.outputPiiAction,
		},
	}
}

/**
 * Parse the tool-policy text (one `toolId: classification` per line) into the
 * schema's `toolPolicy` array.
 *
 * @param {string} text The textarea's text.
 * @return {Array<object>} The `{ toolId, classification }` rules.
 * @spec openspec/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
 */
export function parseToolPolicy(text) {
	return text
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line !== '')
		.map((line) => {
			const [toolId, classification] = line.split(':')
			return {
				toolId: (toolId || '').trim(),
				classification: (classification || 'auto').trim(),
			}
		})
		.filter((entry) => entry.toolId !== '')
}

/**
 * The edit draft seeded from a policy. Tool-policy draft format: one line per
 * rule — `toolId: classification`.
 *
 * @param {object} policy The GuardrailPolicy record.
 * @return {object} The draft.
 * @spec openspec/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
 */
export function draftFromPolicy(policy) {
	return {
		inputPiiAction: policy.inputFilters?.piiAction || 'off',
		inputPromptInjectionAction: policy.inputFilters?.promptInjectionAction || 'off',
		outputPiiAction: policy.outputFilters?.piiAction || 'off',
		toolPolicyText: toolRulesOf(policy)
			.map((entry) => `${entry.toolId}: ${entry.classification}`)
			.join('\n'),
	}
}

/**
 * One-line summary of a guardrail policy's active filters and tool rules.
 *
 * @param {object} policy The GuardrailPolicy record.
 * @return {string} The summary line.
 * @spec openspec/specs/inapp-settings-section/spec.md#requirement-guardrail-policy-administration-must-exist-in-exactly-one-place
 */
export function policySummary(policy) {
	const input = policy.inputFilters || {}
	const output = policy.outputFilters || {}
	const piiLabel = t('hermiq', 'PII')
	const parts = [
		t('hermiq', 'Input') + ': ' + piiLabel + ' ' + (input.piiAction || 'off')
			+ ', ' + t('hermiq', 'prompt injection') + ' ' + (input.promptInjectionAction || 'off'),
		t('hermiq', 'Output') + ': ' + piiLabel + ' ' + (output.piiAction || 'off'),
	]
	const toolRules = toolRulesOf(policy)
	if (toolRules.length > 0) {
		parts.push(toolRules.length + ' ' + t('hermiq', 'tool rules'))
	}
	if (policy.enabled === false) {
		return t('hermiq', 'Disabled') + ' — ' + parts.join(' · ')
	}
	return parts.join(' · ')
}
