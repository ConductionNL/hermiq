/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of GuardrailPolicySettings' one-line policy summary and of
 * what a failed create or save shows.
 */

import * as dialogs from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const api = vi.hoisted(() => ({
	listGuardrailPolicies: vi.fn(),
	createGuardrailPolicy: vi.fn(),
	updateGuardrailPolicy: vi.fn(),
}))
vi.mock('../../src/api/guardrailPolicy.js', () => api)

const { default: Settings } = await import('../../src/views/GuardrailPolicySettings.vue')

/**
 * A page context with its methods bound.
 *
 * @param {object} state The data.
 * @return {object} The context.
 */
function settings(state) {
	return {
		...Settings.methods,
		t,
		guardrailPolicyDraft: { inputPiiAction: 'off', inputPromptInjectionAction: 'off', outputPiiAction: 'off', toolPolicyText: '' },
		createOrganisation: 'org-a',
		loadGuardrailPolicies: vi.fn(async () => {}),
		...state,
	}
}

beforeEach(() => {
	vi.mocked(dialogs.showError).mockClear()
	vi.mocked(dialogs.showSuccess).mockClear()
})

describe('guardrailPolicySummary', () => {
	const summary = (policy) => Settings.methods.guardrailPolicySummary.call({ t }, policy)

	it('summarises the filters, the tool rules and a disabled policy', () => {
		expect(summary({})).toBe('Input: PII off, prompt injection off · Output: PII off')
		expect(summary({
			inputFilters: { piiAction: 'redact', promptInjectionAction: 'block' },
			outputFilters: { piiAction: 'quarantine' },
			toolPolicy: [{}, {}],
		})).toBe('Input: PII redact, prompt injection block · Output: PII quarantine · 2 tool rules')
		expect(summary({ enabled: false, toolPolicy: 'odd' })).toBe('Disabled — Input: PII off, prompt injection off · Output: PII off')
	})
})

describe('a failed save', () => {
	it.each([
		['saveNewGuardrailPolicy', 'createGuardrailPolicy', 'Could not create the guardrail policy.'],
		['saveGuardrailPolicy', 'updateGuardrailPolicy', 'Could not save the guardrail policy.'],
	])('%s shows the server error, or its fallback', async (method, call, fallback) => {
		const ctx = settings({})
		api[call].mockRejectedValueOnce({ response: { data: { error: 'Denied' } } })
		await ctx[method]({ id: 'gp-1' })
		expect(dialogs.showError).toHaveBeenLastCalledWith('Denied')
		expect(ctx.guardrailPolicySaving).toBe(false)

		api[call].mockRejectedValueOnce(new Error('plain'))
		await ctx[method]({ id: 'gp-1' })
		expect(dialogs.showError).toHaveBeenLastCalledWith(fallback)
		expect(ctx.loadGuardrailPolicies).not.toHaveBeenCalled()
	})

	it('a successful create closes the form and reloads', async () => {
		api.createGuardrailPolicy.mockResolvedValueOnce({})
		const ctx = settings({ creatingPolicy: true })
		await ctx.saveNewGuardrailPolicy()
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Guardrail policy created.')
		expect(ctx.creatingPolicy).toBe(false)
		expect(ctx.loadGuardrailPolicies).toHaveBeenCalled()
		expect(ctx.guardrailPolicySaving).toBe(false)
	})

	it('a successful save closes the editor and reloads', async () => {
		api.updateGuardrailPolicy.mockResolvedValueOnce({})
		const ctx = settings({ editingGuardrailPolicyId: 'gp-1' })
		await ctx.saveGuardrailPolicy({ id: 'gp-1', enabled: false })
		expect(api.updateGuardrailPolicy.mock.calls.at(-1)[1].enabled).toBe(false)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Guardrail policy saved.')
		expect(ctx.editingGuardrailPolicyId).toBe(null)
		expect(ctx.loadGuardrailPolicies).toHaveBeenCalled()
	})
})
