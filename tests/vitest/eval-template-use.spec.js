/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "Use for this agent" on the eval run panel (reuse-scheduled-bias-evals,
 * REQ-BIASEVAL-002): a template offers the copy instead of a run, and a copy
 * opens the new dataset.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-ready-made-bias-test-set-can-be-used-for-an-agent-req-biaseval-002
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({}),
	useEvalRunStore: () => ({}),
	useEvalDatasetStore: () => ({}),
}))
vi.mock('../../src/api/skills.js', () => ({ listSkills: vi.fn() }))
vi.mock('../../src/api/evals.js', () => ({ runEval: vi.fn(), useEvalTemplate: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const evalsApi = await import('../../src/api/evals.js')
const dialogs = await import('@nextcloud/dialogs')
const { default: EvalRunPanelWidget } = await import('../../src/widgets/EvalRunPanelWidget.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
}

/**
 * A panel context for the template ds-1 with agent a1 selected.
 *
 * @param {object|null} selectedAgent The selected agent option.
 * @return {object} The context.
 */
function context(selectedAgent = { label: 'Permit intake helper', value: 'a1' }) {
	return {
		...EvalRunPanelWidget.methods,
		t,
		datasetId: 'ds-1',
		selectedAgent,
		copying: false,
		$router: { push: vi.fn() },
	}
}

describe('EvalRunPanelWidget on a template', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('tells a template apart from an ordinary dataset', () => {
		const isTemplate = EvalRunPanelWidget.computed.isTemplate
		expect(isTemplate.call({ dataset: { template: true } })).toBe(true)
		expect(isTemplate.call({ dataset: { template: false, templateKey: 'bias-public-services' } })).toBe(false)
		expect(isTemplate.call({ dataset: null })).toBe(false)
	})

	it('copies the template for the selected agent and opens the copy', async () => {
		evalsApi.useEvalTemplate.mockResolvedValue({ id: 'copy-1', name: 'Bias check: public services — Permit intake helper' })
		const ctx = context()

		await EvalRunPanelWidget.methods.useForAgent.call(ctx)

		expect(evalsApi.useEvalTemplate).toHaveBeenCalledWith('ds-1', 'a1')
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'EvalDatasetDetail', params: { id: 'copy-1' } })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Copied as "Bias check: public services — Permit intake helper". You can now edit and run it.')
		expect(ctx.copying).toBe(false)
	})

	it('shows the server\'s refusal and stays on the template', async () => {
		evalsApi.useEvalTemplate.mockRejectedValue({ response: { data: { error: 'Agent not found' } } })
		const ctx = context()

		await EvalRunPanelWidget.methods.useForAgent.call(ctx)

		expect(dialogs.showError).toHaveBeenCalledWith('Agent not found')
		expect(ctx.$router.push).not.toHaveBeenCalled()
		expect(ctx.copying).toBe(false)
	})

	it('does nothing without a selected agent', async () => {
		const ctx = context(null)

		await EvalRunPanelWidget.methods.useForAgent.call(ctx)

		expect(evalsApi.useEvalTemplate).not.toHaveBeenCalled()
	})
})
