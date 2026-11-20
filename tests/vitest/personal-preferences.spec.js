/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * personal-preferences: the Voorkeuren section of the settings dialog reads
 * each of its four preferences on open and writes each one at once through
 * the per-user preferences endpoint; the settings dialog shows the section
 * above Talk delivery; the helpers decide switches and the default agent.
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchCollection = vi.fn()
vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({ registerObjectType: vi.fn(), fetchCollection }),
}))

const { getPreference, PREFERENCE_KEYS, setPreference } = await import('../../src/api/preferences.js')
const { agentIdOf, defaultAgentOf, switchIsOn, switchValue } = await import('../../src/utils/personalPreferences.js')
const { default: PersonalPreferences } = await import('../../src/components/PersonalPreferences.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const STORED = {
	'answer-language': 'nl-B1',
	'default-agent': 'agent-woo',
	'ask-before-send': '1',
	'notify-run-done': '',
}

/**
 * Mount the section with the given stored preferences and let it load.
 *
 * @param {object} stored The stored values by key.
 * @return {Promise<object>} The wrapper.
 */
async function render(stored = STORED) {
	axios.get = vi.fn(async (url) => {
		const key = decodeURIComponent(url.split('/').pop())
		return { data: { value: key in stored && stored[key] !== '' ? stored[key] : null } }
	})
	axios.put = vi.fn(async (url, body) => ({ data: { value: body.value === '' ? null : body.value } }))
	const w = mount(PersonalPreferences, { global: { mocks: { t } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	vi.clearAllMocks()
	fetchCollection.mockResolvedValue([
		{ uuid: 'agent-woo', name: 'Hulp bij Woo-besluit' },
		{ uuid: 'agent-mail', name: 'Mail helper' },
	])
})

describe('preferences API', () => {
	it('reads one key from its own endpoint and returns "" when none is set', async () => {
		axios.get = vi.fn(async () => ({ data: { value: null } }))
		expect(await getPreference('default-agent')).toBe('')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/hermiq/api/preferences/default-agent')
	})

	it('writes the value, and an empty value as "" so the server clears it', async () => {
		axios.put = vi.fn(async (url, body) => ({ data: { value: body.value || null } }))
		expect(await setPreference('answer-language', 'en')).toBe('en')
		expect(await setPreference('answer-language', null)).toBe('')
		expect(axios.put.mock.calls).toEqual([
			['/index.php/apps/hermiq/api/preferences/answer-language', { value: 'en' }],
			['/index.php/apps/hermiq/api/preferences/answer-language', { value: '' }],
		])
	})

	it('names the four keys as the server stores them', () => {
		expect(Object.values(PREFERENCE_KEYS)).toEqual(['answer-language', 'default-agent', 'ask-before-send', 'notify-run-done'])
	})
})

describe('PersonalPreferences', () => {
	it('reads every preference when it opens and shows the stored values', async () => {
		const w = await render()
		const requested = axios.get.mock.calls.map(([url]) => url.split('/').pop()).sort()
		expect(requested).toEqual(['answer-language', 'ask-before-send', 'default-agent', 'notify-run-done'])

		const [language, agent] = w.findAllComponents({ name: 'NcSelect' })
		expect(language.attributes('data-modelvalue')).toBe(JSON.stringify({ value: 'nl-B1', label: 'Dutch, B1' }))
		expect(language.attributes('data-inputlabel')).toBe('Language of answers')
		expect(agent.attributes('data-modelvalue')).toBe(JSON.stringify({ value: 'agent-woo', label: 'Hulp bij Woo-besluit' }))
		expect(agent.attributes('data-inputlabel')).toBe('Default agent in the chat')

		const [ask, notify] = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(ask.attributes('data-modelvalue')).toBe('true')
		expect(notify.attributes('data-modelvalue')).toBe('false')
	})

	it('writes the answer language at once, and clears it when emptied', async () => {
		const w = await render()
		const [language] = w.findAllComponents({ name: 'NcSelect' })
		language.vm.pick({ value: 'en', label: 'English' })
		await flushPromises()
		language.vm.pick(null)
		await flushPromises()
		expect(axios.put.mock.calls).toEqual([
			['/index.php/apps/hermiq/api/preferences/answer-language', { value: 'en' }],
			['/index.php/apps/hermiq/api/preferences/answer-language', { value: '' }],
		])
		expect(w.vm.answerLanguage).toBe('')
	})

	it('writes the default agent as the agent id', async () => {
		const w = await render()
		const [, agent] = w.findAllComponents({ name: 'NcSelect' })
		agent.vm.pick({ value: 'agent-mail', label: 'Mail helper' })
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/hermiq/api/preferences/default-agent', { value: 'agent-mail' })
		expect(w.vm.defaultAgent).toBe('agent-mail')
	})

	it('writes each switch as "1" when on and "" when off', async () => {
		const w = await render()
		const [ask, notify] = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		notify.vm.pick(true)
		await flushPromises()
		ask.vm.pick(false)
		await flushPromises()
		expect(axios.put.mock.calls).toEqual([
			['/index.php/apps/hermiq/api/preferences/notify-run-done', { value: '1' }],
			['/index.php/apps/hermiq/api/preferences/ask-before-send', { value: '' }],
		])
		expect(w.vm.notifyRunDone).toBe(true)
		expect(w.vm.askBeforeSend).toBe(false)
	})

	it('keeps the old value and says so when a save fails', async () => {
		const w = await render()
		axios.put = vi.fn(async () => { throw new Error('offline') })
		const [ask] = w.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		ask.vm.pick(false)
		await flushPromises()
		expect(w.vm.askBeforeSend).toBe(true)
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Your preference could not be saved.')
	})

	it('says so when the preferences cannot be read', async () => {
		fetchCollection.mockResolvedValue([])
		axios.get = vi.fn(async () => { throw new Error('offline') })
		const w = mount(PersonalPreferences, { global: { mocks: { t } } })
		await flushPromises()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Your preferences could not be loaded.')
		expect(w.vm.loading).toBe(false)
	})
})

describe('the settings dialog', () => {
	it('holds the Preferences section above Talk delivery', () => {
		const app = readFileSync(resolve(process.cwd(), 'src/App.vue'), 'utf8')
		const preferences = app.indexOf('id="preferences"')
		const talk = app.indexOf('id="talk-delivery"')
		expect(preferences).toBeGreaterThan(-1)
		expect(preferences).toBeLessThan(talk)
		expect(app.slice(preferences, talk)).toContain('<PersonalPreferences />')
	})
})

describe('personal preference helpers', () => {
	it('reads the stored switch values the server reads as on', () => {
		expect(['1', 'true', 'ON', ' yes '].map(switchIsOn)).toEqual([true, true, true, true])
		expect(['', '0', 'false', null, undefined].map(switchIsOn)).toEqual([false, false, false, false, false])
		expect([switchValue(true), switchValue(false)]).toEqual(['1', ''])
	})

	it('finds the default agent by uuid, id or @self id, and none when it is gone', () => {
		const agents = [{ uuid: 'a1', name: 'A' }, { id: 'a2' }, { '@self': { id: 'a3' } }]
		expect(defaultAgentOf(agents, 'a1')).toBe(agents[0])
		expect(defaultAgentOf(agents, 'a2')).toBe(agents[1])
		expect(defaultAgentOf(agents, 'a3')).toBe(agents[2])
		expect(defaultAgentOf(agents, 'gone')).toBeNull()
		expect(defaultAgentOf(agents, '')).toBeNull()
		expect(defaultAgentOf(null, 'a1')).toBeNull()
		expect([agentIdOf(agents[0]), agentIdOf(agents[2]), agentIdOf(null)]).toEqual(['a1', 'a3', ''])
	})
})
