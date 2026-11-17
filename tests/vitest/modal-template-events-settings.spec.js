/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The web research settings, template import and AI feature binding modals
 * driven through their rendered controls: every field, picker, file input and
 * button the template wires is exercised.
 *
 * Mounted with the shared @nextcloud/vue stand-ins (tests/vitest/stubs), whose
 * `pick(value)` emits `update:modelValue`; the APIs are doubled.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/api/webResearch.js', () => ({ getWebResearchSettings: vi.fn(), patchWebResearchSettings: vi.fn() }))
vi.mock('../../src/api/agentTemplates.js', () => ({ importAgentTemplate: vi.fn() }))
vi.mock('../../src/api/aiFeatures.js', () => ({ bindAiFeature: vi.fn() }))
vi.mock('../../src/api/modelPolicy.js', () => ({ getEffectiveModelPolicy: vi.fn() }))

const web = await import('../../src/api/webResearch.js')
const templates = await import('../../src/api/agentTemplates.js')
const features = await import('../../src/api/aiFeatures.js')
const policy = await import('../../src/api/modelPolicy.js')
const { default: WebResearchSettingsModal } = await import('../../src/modals/WebResearchSettingsModal.vue')
const { default: TemplateImportModal } = await import('../../src/modals/TemplateImportModal.vue')
const { default: AiFeatureBindingModal } = await import('../../src/modals/AiFeatureBindingModal.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount a component with the stand-ins rendered and let it settle.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = mount(component, { props, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The stand-in of a kind carrying an attribute value.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} attr The attribute.
 * @param {string} value Its value.
 * @return {object|undefined} The stand-in.
 */
function by(w, name, attr, value) {
	return w.findAllComponents({ name }).find((c) => c.vm.$attrs[attr] === value)
}

/**
 * Click the button whose text is given.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function click(w, text) {
	const button = w.findAllComponents({ name: 'NcButton' }).find((b) => b.text().trim() === text)
	if (!button) {
		throw new Error(`no button ${text}`)
	}
	button.vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('WebResearchSettingsModal controls', () => {
	beforeEach(() => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { results: [{ id: 'c1', name: 'Search key' }] } })
		web.getWebResearchSettings.mockResolvedValue({ searchProvider: 'searxng', searchEndpoint: 'https://s.example' })
	})

	it('writes every field of a generic JSON provider and saves the parsed settings', async () => {
		web.patchWebResearchSettings.mockResolvedValue({})
		const w = await mountIt(WebResearchSettingsModal, { show: true })

		by(w, 'NcSelect', 'inputLabel', 'Search provider').vm.pick({ value: 'generic-json', label: 'Generic JSON search API' })
		await flushPromises()
		by(w, 'NcTextField', 'label', 'Search endpoint').vm.pick('https://api.example/search')
		by(w, 'NcTextField', 'label', 'Results path (dot-separated)').vm.pick('data.items')
		by(w, 'NcTextField', 'label', 'Title field').vm.pick('heading')
		by(w, 'NcTextField', 'label', 'URL field').vm.pick('link')
		by(w, 'NcTextField', 'label', 'Snippet field').vm.pick('summary')
		by(w, 'NcSelect', 'inputLabel', 'API credential (optional)').vm.pick({ value: 'c1', label: 'Search key' })
		by(w, 'NcTextArea', 'label', 'Allowlist (one host per line — empty means any public host is reachable)').vm.pick(' a.example \n\nb.example')
		by(w, 'NcTextArea', 'label', 'Denylist (one host per line)').vm.pick('bad.example')
		w.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.pick(true)
		by(w, 'NcTextField', 'label', 'Max response size (bytes)').vm.pick('1000')
		by(w, 'NcTextField', 'label', 'Timeout (seconds)').vm.pick('x')
		await flushPromises()
		await click(w, 'Save')

		expect(web.patchWebResearchSettings).toHaveBeenCalledWith({
			searchProvider: 'generic-json',
			searchEndpoint: 'https://api.example/search',
			searchFieldMapping: { resultsPath: 'data.items', titleField: 'heading', urlField: 'link', snippetField: 'summary' },
			fetchAllowlist: ['a.example', 'b.example'],
			fetchDenylist: ['bad.example'],
			allowInsecureHttp: true,
			maxResponseBytes: 1000,
			timeoutSeconds: 10,
			searchCredentialId: 'c1',
		})
		expect(w.emitted('saved')).toEqual([['generic-json']])
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('shows a failed save and closes from Cancel and the modal', async () => {
		web.patchWebResearchSettings.mockRejectedValue(new Error('500'))
		const w = await mountIt(WebResearchSettingsModal, { show: true })

		await click(w, 'Save')
		expect(w.find('.web-research__error').text()).toBe('Could not save the web-research configuration.')

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(2)
	})
})

describe('TemplateImportModal controls', () => {
	/**
	 * Choose a file with the given text in the file input.
	 *
	 * @param {object} w The wrapper.
	 * @param {string} text The file text.
	 * @return {Promise<void>}
	 */
	async function choose(w, text) {
		const input = w.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [{ text: async () => text }], configurable: true })
		await input.trigger('change')
		await flushPromises()
	}

	it('reads an exported agent file into the package and imports it for the organisation', async () => {
		templates.importAgentTemplate.mockResolvedValue({})
		const w = await mountIt(TemplateImportModal, { mode: 'agent' })
		expect(w.find('h2').text()).toBe('Import an agent')

		await choose(w, '{"name":"Triage"}')
		expect(w.find('textarea').element.value).toBe('{"name":"Triage"}')
		await click(w, 'Import agent')

		expect(templates.importAgentTemplate).toHaveBeenCalledWith('{"name":"Triage"}', 'org')
		expect(w.emitted('imported')).toHaveLength(1)
	})

	it('names a file without a name or that is not an agent, and ignores an empty choice', async () => {
		const w = await mountIt(TemplateImportModal)

		await choose(w, '{"name":""}')
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('This agent file has no name.')
		await choose(w, 'not json')
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('This file is not an exported agent.')
		const input = w.find('input[type="file"]')
		Object.defineProperty(input.element, 'files', { value: [], configurable: true })
		await input.trigger('change')
		expect(w.find('textarea').element.value).toBe('')
	})

	it('imports a pasted template locally or quarantined, shows a refusal, and closes', async () => {
		templates.importAgentTemplate.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Duplicate'))
		const w = await mountIt(TemplateImportModal)

		await w.find('textarea').setValue('{"name":"T"}')
		await click(w, 'Import')
		await click(w, 'Import from another organisation (quarantine)')

		expect(templates.importAgentTemplate.mock.calls).toEqual([['{"name":"T"}', 'local'], ['{"name":"T"}', 'org']])
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('Duplicate')

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(w.emitted('close')).toHaveLength(3)
	})
})

describe('AiFeatureBindingModal controls', () => {
	const allowed = { allowed: [{ provider: 'openai', models: ['gpt-a', 'gpt-b'] }, { provider: 'ollama', models: [] }] }

	it('binds a provider and listed model with a residency requirement', async () => {
		policy.getEffectiveModelPolicy.mockResolvedValue(allowed)
		features.bindAiFeature.mockResolvedValue({})
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1', featureName: 'Summaries' })

		w.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.pick(false)
		await flushPromises()
		by(w, 'NcSelect', 'inputLabel', 'Provider').vm.pick({ value: 'openai', label: 'openai' })
		await flushPromises()
		by(w, 'NcSelect', 'inputLabel', 'Model').vm.pick({ value: 'gpt-b', label: 'gpt-b' })
		by(w, 'NcSelect', 'inputLabel', 'Where the provider must run').vm.pick({ value: 'eu', label: 'In the EU' })
		await flushPromises()
		await click(w, 'Save')

		expect(features.bindAiFeature).toHaveBeenCalledWith('f1', { provider: 'openai', model: 'gpt-b', requiredResidency: 'eu' })
		expect(w.emitted('saved')).toHaveLength(1)
	})

	it('takes a free model for a provider that allows any, and drops a model the new provider lacks', async () => {
		policy.getEffectiveModelPolicy.mockResolvedValue(allowed)
		features.bindAiFeature.mockResolvedValue({})
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1', current: { provider: 'openai', model: 'gpt-a' } })
		expect(w.vm.modelOption).toEqual({ value: 'gpt-a', label: 'gpt-a' })

		by(w, 'NcSelect', 'inputLabel', 'Provider').vm.pick({ value: 'ollama', label: 'ollama' })
		await flushPromises()
		expect(w.vm.modelOption).toBeNull()
		by(w, 'NcTextField', 'label', 'Model').vm.pick(' llama3 ')
		await flushPromises()
		await click(w, 'Save')

		expect(features.bindAiFeature).toHaveBeenCalledWith('f1', { provider: 'ollama', model: 'llama3', requiredResidency: '' })
	})

	it('shows a policy that cannot be read and a refused save, and closes from Cancel and the dialog', async () => {
		policy.getEffectiveModelPolicy.mockRejectedValue(new Error('No policy'))
		features.bindAiFeature.mockRejectedValue({ response: { data: { error: 'Not allowed' } } })
		const w = await mountIt(AiFeatureBindingModal, { featureId: 'f1' })
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('No policy')

		await click(w, 'Save')
		expect(features.bindAiFeature).toHaveBeenCalledWith('f1', { provider: '', model: '', requiredResidency: '' })
		expect(by(w, 'NcNoteCard', 'type', 'error').text()).toBe('Not allowed')

		await click(w, 'Cancel')
		w.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(w.emitted('close')).toHaveLength(2)
	})
})
