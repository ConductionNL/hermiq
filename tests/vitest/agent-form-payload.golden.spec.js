/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Golden: the form AgentFormModal seeds from a stored agent, and the payload it
 * builds from a form, byte for byte. Key order is part of the record. Set
 * RECORD_GOLDEN=1 to write the fixture; a run that records compares nothing new.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIconPicker: { name: 'CnIconPicker', render: () => null },
	fromOpenGemeenten: () => [],
	createObjectStore: () => () => ({}),
}))

vi.mock('../../src/store/store.js', () => ({
	useAgentStore: () => ({ registerObjectType: () => {}, errors: {} }),
}))

vi.mock('../../src/api/toolOversight.js', () => ({ updateToolGrants: vi.fn() }))

vi.mock('../../src/api/agents.js', () => ({
	listTools: async () => [],
	setAppAssistant: async () => ({}),
}))

vi.mock('../../src/api/chat.js', () => ({
	searchGroups: async () => [],
	searchUsers: async () => [],
}))

vi.mock('../../src/api/modelPolicy.js', () => ({
	getEffectiveModelPolicy: async () => null,
}))

const { default: AgentFormModal } =
	await import('../../src/modals/AgentFormModal.vue')

const FIXTURE = resolve(
	process.cwd(),
	'tests/Fixtures/Golden/agent-form-payload.json',
)

const VALUES = {
	name: [undefined, '', 'Digest'],
	description: [undefined, 'Reads mail'],
	icon: [undefined, '', 'RobotOutline'],
	provider: [undefined, 'ollama'],
	model: [undefined, 'llama3'],
	prompt: [undefined, 'Be brief'],
	startFields: [undefined, [{ key: 'a', label: 'A', type: 'text' }], 'wrong'],
	temperature: [undefined, null, 0, 0.7, 'hot', ''],
	maxTokens: [undefined, null, 0, 512, 1.5, 'many', ''],
	maxToolCalls: [undefined, null, 0, 3, 500, 2.5, ''],
	applicationSlug: [undefined, '', 'dossiq', { slug: 'x' }],
	appAssistantFor: [undefined, 'dossiq', 'other'],
	isPrivate: [undefined, true, false],
	invitedUsers: [undefined, [], ['bob'], 'wrong'],
	groups: [undefined, [], ['staff'], 'wrong'],
	tools: [undefined, [], ['a.b'], { a: { b: { c: 'a.b.c' } } }],
	delegationAllowlist: [undefined, [], ['agent-2'], 'wrong'],
	enableRag: [undefined, true, false, 'yes'],
	searchObjects: [undefined, true, false],
	searchFiles: [undefined, true, false],
	ragNumSources: [undefined, null, 0, 5, 2.5, ''],
	voiceInputEngine: [undefined, '', 'local'],
	voiceOutputEngine: [undefined, '', 'browser'],
	voiceSilenceTimeout: [undefined, null, -1, 0, 2500, 1.5, ''],
	voiceConversationEnabled: [undefined, true, 'yes'],
	credentialIds: [undefined, null, { ollama: 'c1' }],
	extra: [undefined, 'kept'],
	'@self': [undefined, { id: 'x' }],
}

const FORM_EDITS = {
	temperature: ['', '0.5', 'hot', 1, null],
	maxTokens: ['', '100', '1.5', 7, null],
	maxToolCalls: ['', '0', '1000', '7', 2.5, null],
	ragNumSources: ['', '4', '1.5', null],
	voiceSilenceTimeout: ['', '-5', '0', '100', 1.5, null],
	icon: ['', 'X'],
	voiceInputEngine: ['', 'off'],
	voiceOutputEngine: ['', 'off'],
	sharing: ['only-me', 'people-and-groups', 'organisation'],
	delegationAllowlist: [null, [], [{ label: 'A', value: 'a' }]],
	invitedUsers: [null, [{ label: 'B', value: 'b' }]],
	groups: [null, [{ label: 'G', value: 'g' }]],
	applicationSlug: [null, { label: 'D', value: 'dossiq' }],
}

/**
 * A small seeded generator, so the cases do not move between runs.
 *
 * @param {number} seed The seed.
 * @return {Function} Picks one element from a list.
 */
function picker(seed) {
	let state = seed
	return (list) => {
		state = (state * 1664525 + 1013904223) % 4294967296
		return list[Math.floor((state / 4294967296) * list.length)]
	}
}

/**
 * One stored agent, leaving out what the generator picked as undefined.
 *
 * @param {Function} pick The generator.
 * @param {string|undefined} id The agent id.
 * @return {object} The agent.
 */
function source(pick, id) {
	const agent = id ? { id } : {}
	for (const [key, list] of Object.entries(VALUES)) {
		const value = pick(list)
		if (value !== undefined) {
			agent[key] = value
		}
	}
	return agent
}

/**
 * Mount the modal around one prop set.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The component instance.
 */
async function modal(props) {
	const wrapper = mount(
		{ ...AgentFormModal, render: () => null },
		{
			props: { show: false, ...props },
			global: { mocks: { t: (app, text) => text, $route: { params: {} } } },
		},
	)
	await flushPromises()
	return wrapper.vm
}

/**
 * One case: the seeded form, then the payload, untouched and after form edits.
 *
 * @param {number} seed The case number.
 * @return {Promise<object>} What the case produced.
 */
async function run(seed) {
	const pick = picker(seed * 7919 + 13)
	const mode = ['agent', 'draft', 'none'][seed % 3]
	const stored = source(pick, mode === 'agent' ? `agent-${seed}` : undefined)
	const vm = await modal(mode === 'none' ? {} : { [mode]: stored })
	vm.resetForm()
	const form = JSON.stringify(vm.form)
	const untouched = JSON.stringify(vm.buildPayload())
	for (const [key, list] of Object.entries(FORM_EDITS)) {
		vm.form[key] = pick(list)
	}
	return { seed, form, untouched, edited: JSON.stringify(vm.buildPayload()) }
}

describe('AgentFormModal form and payload golden', () => {
	it('seeds and builds what was recorded', async () => {
		const got = []
		for (let seed = 0; seed < 240; seed++) {
			got.push(await run(seed))
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(FIXTURE, JSON.stringify(got, null, '\t') + '\n')
		}
		const recorded = JSON.parse(readFileSync(FIXTURE, 'utf8'))
		expect(got.length).toBe(recorded.length)
		for (let i = 0; i < got.length; i++) {
			expect(got[i]).toEqual(recorded[i])
		}
	})
})
