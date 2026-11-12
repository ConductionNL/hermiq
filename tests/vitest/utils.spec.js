/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The pure helpers under src/utils: run comparison, tool naming, agent context
 * allowlists, start fields, drafts, speech state machines, sharing and the
 * small formatters, each asserted on what it returns for a given input.
 */

import { describe, expect, it, vi } from 'vitest'
import { answersInItsApp, appOptions, appSlugOf } from '../../src/utils/agentApp.js'
import { AGENT_CONTEXT_KEYWORD, buildAgentContext } from '../../src/utils/agentContext.js'
import { credentialOptions, setPin } from '../../src/utils/agentCredentials.js'
import * as draft from '../../src/utils/agentDraft.js'
import { exportFileName, packageFromFile } from '../../src/utils/agentExport.js'
import { gitActions, repoLink } from '../../src/utils/agentGit.js'
import { agentCatalogFormatters, sharingFields, sharingOf } from '../../src/utils/agentSharing.js'
import * as vars from '../../src/utils/instructionVariables.js'
import { loadReadyMadePrompts, withPrompt } from '../../src/utils/readyMadePrompts.js'
import * as summary from '../../src/utils/recordSummary.js'
import * as runCompare from '../../src/utils/runCompare.js'
import { sessionStartErrorMessage } from '../../src/utils/sessionStartError.js'
import * as speech from '../../src/utils/speech.js'
import * as taxonomy from '../../src/utils/toolTaxonomy.js'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} values The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, values = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in values ? String(values[k]) : m))

describe('runCompare', () => {
	it('toggles a run in and out of a selection of at most two', () => {
		expect(runCompare.toggleSelection([], 'a')).toEqual({ selected: ['a'], refused: false })
		expect(runCompare.toggleSelection(['a'], 'a')).toEqual({ selected: [], refused: false })
		expect(runCompare.toggleSelection(['a', 'b'], 'c')).toEqual({ selected: ['a', 'b'], refused: true })
	})

	it('says the outcome is the same, or different between two non-failures', () => {
		expect(runCompare.summaryLine({ status: 'ok', durationMs: 100 }, { status: 'ok', durationMs: 150 }, {}, t)).toBe('Same outcome.')
		expect(runCompare.summaryLine({ status: 'ok' }, { status: 'skipped' }, {}, t)).toBe('Different outcome.')
	})

	it('names which run failed and at which step', () => {
		const comparison = { steps: [{ left: { name: 'fetch' }, right: { name: 'fetch' } }, { right: { name: 'write' }, mark: 'only-right' }] }
		expect(runCompare.summaryLine({ status: 'ok' }, { status: 'FAILED' }, comparison, t))
			.toBe('Different outcome. Run A succeeded, run B failed at write. Run B called write once more.')
		expect(runCompare.summaryLine({ status: 'timeout' }, { status: 'ok' }, { steps: [] }, t))
			.toBe('Different outcome. Run B succeeded, run A failed.')
	})

	it('counts extra calls per side and reports a duration difference of a tenth of a second or more', () => {
		const comparison = { steps: [
			{ mark: 'only-left', left: { name: 'search' } },
			{ mark: 'only-left', left: { name: 'search' } },
			{ mark: 'same', left: { name: 'x' }, right: { name: 'x' } },
		] }
		expect(runCompare.summaryLine({ status: 'ok', durationMs: 3000 }, { status: 'ok', durationMs: 1500 }, comparison, t))
			.toBe('Same outcome. Run A called search 2 times more. Run B took 1.5 s less.')
		expect(runCompare.summaryLine({ status: 'ok', durationMs: 1000 }, { status: 'ok', durationMs: 1250 }, null, t))
			.toBe('Same outcome. Run B took 0.3 s longer.')
		expect(runCompare.summaryLine({ status: 'ok', durationMs: 1000 }, { status: 'ok', durationMs: 1099 }, null, t)).toBe('Same outcome.')
		expect(runCompare.summaryLine({ status: 'ok', durationMs: 'x' }, { status: 'ok', durationMs: 1 }, null, t)).toBe('Same outcome.')
	})

	it('reads a flow run log into named nodes, or null when it has none', () => {
		expect(runCompare.readFlowRunNodes({ log: [{ transition: 'a', status: 'ok', durationMs: 5 }, { node: 'b' }, { nodeId: 'c', durationMs: 'x' }, {}] }))
			.toEqual([
				{ node: 'a', status: 'ok', durationMs: 5 },
				{ node: 'b', status: '', durationMs: null },
				{ node: 'c', status: '', durationMs: null },
			])
		expect(runCompare.readFlowRunNodes({ log: [{}] })).toBeNull()
		expect(runCompare.readFlowRunNodes(null)).toBeNull()
	})

	it('compares two flow runs node by node, in first-seen order', () => {
		const left = { flowVersion: 1, log: [{ node: 'a', status: 'ok' }, { node: 'b', status: 'ok' }] }
		const right = { flowVersion: 2, log: [{ node: 'a', status: 'ok' }, { node: 'b', status: 'failed' }, { node: 'c', status: 'ok' }] }
		const result = runCompare.compareFlowRuns(left, right)
		expect(result.versions).toEqual([1, 2])
		expect(result.unreadable).toEqual([])
		expect(result.rows.map((r) => [r.node, r.differs])).toEqual([['a', false], ['b', true], ['c', true]])
		expect(result.rows[2].left).toBeNull()
	})

	it('reports the unreadable side and keeps versions only when both are known and differ', () => {
		expect(runCompare.compareFlowRuns({ log: [] }, { flowVersion: 1, log: [{ node: 'a' }] })).toEqual({ rows: [], unreadable: ['left'], versions: null })
		expect(runCompare.compareFlowRuns({ flowVersion: 1, log: [{ node: 'a' }] }, { flowVersion: 1, log: [{ node: 'a' }] }).versions).toBeNull()
	})
})

describe('toolTaxonomy', () => {
	it('joins dotted ids and singularises English plurals', () => {
		expect(taxonomy.joinKey('a.b.c')).toBe('a_b_c')
		expect(taxonomy.joinKey(null)).toBe('')
		expect(['entries', 'boxes', 'matches', 'objects', 'class', 'keys'].map(taxonomy.singularise))
			.toEqual(['entry', 'box', 'match', 'object', 'class', 'key'])
	})

	it('classifies a verb as canonical through its alias, or as special', () => {
		expect(taxonomy.classify('fetch', 'file')).toEqual({ verb: 'read', subject: 'file', specialLabel: null })
		expect(taxonomy.classify('sign', 'doc')).toEqual({ verb: 'special', subject: 'doc', specialLabel: 'sign' })
	})

	it('splits on the verb segment, with the subject after it or, for a trailing verb, before it', () => {
		expect(taxonomy.splitOnVerbSegment(['app', 'list', 'objects'])).toEqual({ verb: 'list', subject: 'object' })
		expect(taxonomy.splitOnVerbSegment(['app', 'case', 'type', 'delete'])).toEqual({ verb: 'delete', subject: 'case_type' })
		expect(taxonomy.splitOnVerbSegment(['app', 'delete'])).toBeNull()
		expect(taxonomy.splitOnVerbSegment(['app', 'thing'])).toBeNull()
	})

	it('parses a declared subject and action first', () => {
		expect(taxonomy.parseVerbAndSubject('x', { subject: 'case', action: 'remove' })).toEqual({ verb: 'delete', subject: 'case', specialLabel: null })
	})

	it('parses segmented, camelCase and opaque tool ids', () => {
		expect(taxonomy.parseVerbAndSubject('openregister.search_objects')).toEqual({ verb: 'list', subject: 'object', specialLabel: null })
		expect(taxonomy.parseVerbAndSubject('mail.sendMessages')).toEqual({ verb: 'special', subject: 'message', specialLabel: 'send' })
		expect(taxonomy.parseVerbAndSubject('mail.doThings')).toEqual({ verb: 'special', subject: 'doThings', specialLabel: 'doThings' })
		expect(taxonomy.parseVerbAndSubject('weather', { right: 'read' })).toEqual({ verb: 'read', subject: 'weather', specialLabel: null })
		expect(taxonomy.parseVerbAndSubject('weather')).toEqual({ verb: 'special', subject: 'weather', specialLabel: 'special' })
	})
})

describe('buildAgentContext', () => {
	const data = { title: 'Long title here', bsn: '123', empty: '', none: null, notes: 'abc' }

	it('passes only allowlisted, non-empty fields, capped at their maximum length', () => {
		const schema = { [AGENT_CONTEXT_KEYWORD]: { title: { maxLength: 4 }, empty: {}, none: true, notes: null, missing: {}, '': {} } }
		expect(buildAgentContext(data, schema)).toEqual({ title: 'Long…', notes: 'abc' })
	})

	it('reads a list allowlist from the schema configuration', () => {
		const schema = { configuration: { [AGENT_CONTEXT_KEYWORD]: ['notes', '', 7, { property: 'title', maxLength: 50 }, { property: '' }] } }
		expect(buildAgentContext(data, schema)).toEqual({ notes: 'abc', title: 'Long title here' })
	})

	it('passes nothing without an allowlist or without data', () => {
		expect(buildAgentContext(data, {})).toEqual({})
		expect(buildAgentContext(data, { [AGENT_CONTEXT_KEYWORD]: 'title' })).toEqual({})
		expect(buildAgentContext(null, null)).toEqual({})
	})

	it('leaves a value whose cap does not apply untouched', () => {
		const schema = { [AGENT_CONTEXT_KEYWORD]: { count: { maxLength: 1 }, title: { maxLength: 0 } } }
		expect(buildAgentContext({ count: 12345, title: 'abc' }, schema)).toEqual({ count: 12345, title: 'abc' })
	})
})

describe('speech helpers', () => {
	it('offers dictation and read-aloud per agent and capability', () => {
		expect(speech.speechControls(null, { available: true }, true)).toEqual({ dictate: false, readAloud: false })
		expect(speech.speechControls({}, { available: false }, true)).toEqual({ dictate: false, readAloud: false })
		expect(speech.speechControls({}, { available: true }, true)).toEqual({ dictate: true, readAloud: true })
		expect(speech.speechControls({ voiceInputEngine: 'off', voiceOutputEngine: 'off' }, { available: true }, true)).toEqual({ dictate: false, readAloud: false })
		expect(speech.speechControls({}, { available: true }, false).dictate).toBe(false)
	})

	it('appends a transcript with one space, ignoring an empty one', () => {
		expect(speech.appendTranscript('Hello  ', ' world ')).toBe('Hello world')
		expect(speech.appendTranscript('', 'world')).toBe('world')
		expect(speech.appendTranscript('Hello', '  ')).toBe('Hello')
		expect(speech.appendTranscript(null, null)).toBe(null)
	})

	it('strips markdown down to speakable text', () => {
		const md = '# Title\n\n> quote\n- **bold** and _it_ ~~gone~~\n1. `code` [link](http://x) ![alt](i.png)\n```\nblock\n```\n<b>tag</b>'
		expect(speech.plainTextForSpeech(md)).toBe('Title quote bold and it gone code link alt tag')
		expect(speech.plainTextForSpeech(null)).toBe('')
	})

	it('runs dictation from idle to listening to transcribing and back', async () => {
		const states = []
		const transcripts = []
		const dictation = speech.createDictation({
			onState: (s) => states.push(s),
			onError: (e) => states.push('error:' + e),
			onTranscript: (text) => transcripts.push(text),
			startRecording: async () => ({ stop: async () => 'BLOB' }),
			transcribe: async (blob) => `heard ${blob}`,
		})
		expect(dictation.state()).toBe('idle')
		await dictation.toggle()
		expect(dictation.state()).toBe('listening')
		await dictation.toggle()
		expect(states).toEqual(['listening', 'transcribing', 'idle'])
		expect(transcripts).toEqual(['heard BLOB'])
	})

	it('reports a refused microphone and a failed transcription, and ignores a toggle while transcribing', async () => {
		const errors = []
		const refused = speech.createDictation({ onState: () => {}, onError: (e) => errors.push(e), startRecording: async () => { throw new Error('no') } })
		await refused.toggle()
		expect(refused.state()).toBe('idle')

		let release
		const failing = speech.createDictation({
			onState: () => {},
			onError: (e) => errors.push(e),
			onTranscript: () => {},
			startRecording: async () => ({ stop: () => new Promise((resolve, reject) => { release = () => reject(new Error('x')) }) }),
			transcribe: async () => '',
		})
		await failing.toggle()
		const pending = failing.toggle()
		expect(failing.state()).toBe('transcribing')
		await failing.toggle()
		release()
		await pending
		expect(failing.state()).toBe('idle')
		expect(errors).toEqual(['refused', 'unavailable'])
	})

	it('plays one message at a time, stops on a second toggle and on the end of the audio', async () => {
		const changes = []
		const audios = []
		const speaker = speech.createSpeaker({
			onChange: (key) => changes.push(key),
			synthesise: async (text) => `blob:${text}`,
			createAudio: (blob) => {
				const audio = { blob, paused: false, play: async () => {}, pause() { this.paused = true } }
				audios.push(audio)
				return audio
			},
		})
		await speaker.toggle('m1', 'Hi')
		expect(speaker.playing()).toBe('m1')
		expect(audios[0].blob).toBe('blob:Hi')
		await speaker.toggle('m1', 'Hi')
		expect(speaker.playing()).toBeNull()
		expect(audios[0].paused).toBe(true)
		await speaker.toggle('m2', 'Yo')
		audios[1].onended()
		expect(speaker.playing()).toBeNull()
		expect(changes).toEqual([null, 'm1', null, null, 'm2', null])
	})

	it('drops a synthesis that finished after another message started, and rethrows a failed one', async () => {
		let finish
		const speaker = speech.createSpeaker({
			onChange: () => {},
			synthesise: (text) => (text === 'slow' ? new Promise((resolve) => { finish = resolve }) : Promise.resolve('b')),
			createAudio: () => ({ play: async () => {}, pause() {} }),
		})
		const slow = speaker.toggle('m1', 'slow')
		speaker.stop()
		finish('late')
		await slow
		expect(speaker.playing()).toBeNull()

		const broken = speech.createSpeaker({ onChange: () => {}, synthesise: async () => { throw new Error('tts down') } })
		await expect(broken.toggle('m', 'x')).rejects.toThrow('tts down')
		expect(broken.playing()).toBeNull()
	})
})

describe('instruction variables and start fields', () => {
	const agent = { startFields: [
		{ key: 'topic', label: ' Topic ', type: 'select', options: [' a ', '', 'b'], required: true, default: 3 },
		{ key: 'topic', label: 'duplicate' },
		{ key: 'Bad Key' },
		{ key: 'when', type: 'colour' },
		{ key: 'notes' },
	] }

	it('keeps the valid start fields once each, normalised', () => {
		expect(vars.startFieldsOf(agent)).toEqual([
			{ key: 'topic', label: 'Topic', type: 'select', options: ['a', 'b'], required: true, default: '3' },
			{ key: 'notes', label: 'notes', type: 'text', options: [], required: false, default: '' },
		])
		expect(vars.startFieldsOf({})).toEqual([])
	})

	it('stops at the maximum number of fields', () => {
		const many = { startFields: Array.from({ length: 12 }, (_, i) => ({ key: `f${i}` })) }
		expect(vars.startFieldsOf(many)).toHaveLength(vars.MAX_FIELDS)
	})

	it('asks the start fields only before the first message of an unanswered session', () => {
		expect(vars.pendingStartFields(agent, null, 0)).toEqual([])
		expect(vars.pendingStartFields(agent, {}, 1)).toEqual([])
		expect(vars.pendingStartFields(agent, { startValues: { topic: 'a' } }, 0)).toEqual([])
		expect(vars.pendingStartFields(agent, { startValues: {} }, 0).map((f) => f.key)).toEqual(['topic', 'notes'])
	})

	it('names the required fields left blank, and fills the defaults', () => {
		const fields = vars.startFieldsOf(agent)
		expect(vars.missingRequired(fields, { topic: '  ' })).toEqual(['topic'])
		expect(vars.missingRequired(fields, { topic: 'a' })).toEqual([])
		expect(vars.initialAnswers(fields)).toEqual({ topic: '3', notes: '' })
	})

	it('summarises the answers by label, skipping blanks', () => {
		expect(vars.startValueSummary(vars.startFieldsOf(agent), { topic: 'a', notes: '', other: 'x' })).toEqual(['Topic: a', 'other: x'])
		expect(vars.startValueSummary([], null)).toEqual([])
	})

	it('inserts a placeholder at the selection, or at the end', () => {
		expect(vars.insertPlaceholder('Hello there', '{{today}}', 6, 11)).toEqual({ text: 'Hello {{today}}', cursor: 15 })
		expect(vars.insertPlaceholder('Hi', '{{now}}')).toEqual({ text: 'Hi{{now}}', cursor: 9 })
		expect(vars.insertPlaceholder(null, 'X', 0)).toEqual({ text: 'X', cursor: 1 })
	})
})

describe('agent drafts', () => {
	it('extracts the fenced draft block', () => {
		expect(draft.draftBlockOf('text\n```hermiq-agent-draft\n{"a":1}\n```\nmore')).toBe('{"a":1}')
		expect(draft.draftBlockOf('~~~ hermiq-agent-draft json\nX\n~~~')).toBe('X')
		expect(draft.draftBlockOf(null)).toBe('')
	})

	it('maps a sharing mode onto the form choice', () => {
		expect(draft.sharingChoiceOf({ mode: 'organisation', groups: ['g'] })).toEqual({ choice: 'organisation', groups: [] })
		expect(draft.sharingChoiceOf({ mode: 'groups', groups: ['g'] })).toEqual({ choice: 'people-and-groups', groups: ['g'] })
		expect(draft.sharingChoiceOf({ mode: 'groups', groups: [] })).toEqual({ choice: 'only-me', groups: [] })
		expect(draft.sharingChoiceOf(null)).toEqual({ choice: 'only-me', groups: [] })
	})

	it('builds an agent from a draft, private unless shared with the organisation', () => {
		const tools = ['t']
		const agent = draft.agentFromDraft({ name: 'N', tools, startFields: [{ key: 'k' }], sharing: { mode: 'groups', groups: ['g'] } })
		expect(agent).toEqual({ name: 'N', description: '', prompt: '', provider: '', model: '', tools: ['t'], startFields: [{ key: 'k' }], isPrivate: true, invitedUsers: [], groups: ['g'] })
		expect(agent.tools).not.toBe(tools)
		expect(draft.agentFromDraft({ sharing: { mode: 'organisation' } }).isPrivate).toBe(false)
		expect(draft.agentFromDraft(null).tools).toEqual([])
	})

	it('builds a schedule from a draft, defaulting an unknown kind to cron', () => {
		expect(draft.scheduleFromDraft({ name: 'N', schedule: { kind: 'interval', intervalMinutes: 15, prompt: 'P' } }))
			.toEqual({ name: 'N', kind: 'interval', cronExpr: '', intervalMinutes: 15, runAt: '', prompt: 'P' })
		expect(draft.scheduleFromDraft({ schedule: { kind: 'weird', cronExpr: '* * * * *' } }))
			.toEqual({ name: '', kind: 'cron', cronExpr: '* * * * *', intervalMinutes: null, runAt: '', prompt: '' })
		expect(draft.scheduleFromDraft({ schedule: 'daily' })).toBeNull()
	})

	it('groups draft findings by field', () => {
		expect(draft.findingsByField([{ field: 'name', message: 'm' }, { message: 'x', suggestion: 's' }, { field: 'name' }])).toEqual({
			name: [{ message: 'm', suggestion: '' }, { message: '', suggestion: '' }],
			other: [{ message: 'x', suggestion: 's' }],
		})
		expect(draft.findingsByField(null)).toEqual({})
	})
})

describe('ready-made prompts', () => {
	it('loads the prompts of a scope, skipping blank ones', async () => {
		const calls = []
		const fetchImpl = async (url, init) => {
			calls.push([url, init])
			return { ok: true, json: async () => ({ results: [{ id: 1, label: 'L', prompt: 'P' }, { uuid: 'u', prompt: 'Q' }, { prompt: '  ' }, {}] }) }
		}
		expect(await loadReadyMadePrompts('chat x', fetchImpl)).toEqual([
			{ id: '1', label: 'L', prompt: 'P' },
			{ id: 'u', label: 'Q', prompt: 'Q' },
		])
		expect(calls[0][0]).toBe('/index.php/apps/hermiq/api/assistant-prompts?scope=chat%20x')
		expect(calls[0][1]).toEqual({ method: 'GET', headers: { requesttoken: 'tok' } })
	})

	it('loads nothing without a scope, on a refusal, on a malformed body or a failure', async () => {
		expect(await loadReadyMadePrompts('', async () => { throw new Error('unreached') })).toEqual([])
		expect(await loadReadyMadePrompts('s', async () => ({ ok: false }))).toEqual([])
		expect(await loadReadyMadePrompts('s', async () => ({ ok: true, json: async () => ({ results: 'x' }) }))).toEqual([])
		expect(await loadReadyMadePrompts('s', async () => { throw new Error('down') })).toEqual([])
	})

	it('puts a prompt on a new line after the draft', () => {
		expect(withPrompt('Draft  ', 'P')).toBe('Draft\nP')
		expect(withPrompt('', 'P')).toBe('P')
	})
})

describe('session start errors', () => {
	it.each([
		[{ response: { status: 400 } }, 'Choose an agent to start a session with.'],
		[{ response: { status: 403 } }, 'You do not have permission to start a session with this agent.'],
		[{ response: { status: 404 } }, 'This agent does not exist or is not shared with you.'],
		[{ response: { status: 500, data: { message: ' quota ' } } }, 'Could not start the session: quota'],
		[{ response: { status: 500, data: { message: ' ' } } }, 'Could not start the session.'],
		[null, 'Could not start the session.'],
	])('explains %j', (error, message) => {
		expect(sessionStartErrorMessage(error, t)).toBe(message)
	})
})

describe('record summary', () => {
	it('shows the section when enabled with an agent or a summary, and the button only without a summary', () => {
		expect(summary.showsSummarySection(null)).toBe(false)
		expect(summary.showsSummarySection({ enabled: true })).toBe(false)
		expect(summary.showsSummarySection({ enabled: true, summary: 's' })).toBe(true)
		expect(summary.offersSummaryButton({ enabled: true, agent: 'a' })).toBe(true)
		expect(summary.offersSummaryButton({ enabled: true, agent: 'a', summary: 's' })).toBe(false)
		expect(summary.offersSummaryButton({ enabled: true, summary: 's' })).toBe(false)
	})

	it('formats the date in UTC and refuses an unparseable one', () => {
		expect(summary.summaryDate('2026-03-04T23:30:00Z', 'en-GB')).toBe('4 March 2026')
		expect(summary.summaryDate('nope', 'en')).toBe('')
		expect(summary.summaryDate('', 'en')).toBe('')
	})

	it('maps a refusal status to its reason', () => {
		expect([404, 403, 409, 422, 500].map(summary.summaryRefusal)).toEqual(['not-found', 'switched-off', 'no-agent', 'blocked', 'failed'])
	})
})

describe('agent export', () => {
	it('slugs the file name, falling back to agent', () => {
		expect(exportFileName('Café Bot 2!')).toBe('cafe-bot-2.hermiq-agent.json')
		expect(exportFileName('***')).toBe('agent.hermiq-agent.json')
	})

	it('accepts a named agent package and refuses anything else with a code', () => {
		expect(packageFromFile('{"name":"A"}')).toBe('{"name":"A"}')
		for (const [text, code] of [['nope', 'not-an-agent'], ['[]', 'not-an-agent'], ['null', 'not-an-agent'], ['{"name":" "}', 'no-name']]) {
			expect(() => packageFromFile(text)).toThrow(expect.objectContaining({ code }))
		}
	})
})

describe('credentials, app and sharing', () => {
	it('offers the credentials of the provider that hermiq may use', () => {
		const creds = [
			{ id: 'c1', name: 'Main', provider: 'openai' },
			{ id: 'c2', provider: 'openai', allowedApps: ['hermiq'] },
			{ id: 'c3', provider: 'openai', allowedApps: ['other'] },
			{ id: 'c4', provider: 'fireworks' },
		]
		expect(credentialOptions(creds, 'openai')).toEqual([{ label: 'Main', value: 'c1' }, { label: 'c2', value: 'c2' }])
		expect(credentialOptions(null, 'openai')).toEqual([])
	})

	it('pins or unpins a credential per provider without touching the original', () => {
		const pins = { openai: 'c1' }
		expect(setPin(pins, 'fireworks', 'c4')).toEqual({ openai: 'c1', fireworks: 'c4' })
		expect(setPin(pins, 'openai', '')).toEqual({})
		expect(pins).toEqual({ openai: 'c1' })
		expect(setPin(null, 'x', 'y')).toEqual({ x: 'y' })
	})

	it('lists the apps without core, plus the current one, sorted', () => {
		expect(appOptions({ core: 1, mail: 1, deck: 1 }, { value: ' Talk ' })).toEqual([
			{ label: 'deck', value: 'deck' }, { label: 'mail', value: 'mail' }, { label: 'talk', value: 'talk' },
		])
		expect(appOptions(null, '')).toEqual([])
		expect(appSlugOf(null)).toBe('')
	})

	it('knows when an agent is the assistant of its own app', () => {
		expect(answersInItsApp({ applicationSlug: 'Mail', appAssistantFor: 'mail' })).toBe(true)
		expect(answersInItsApp({ applicationSlug: 'mail', appAssistantFor: 'deck' })).toBe(false)
		expect(answersInItsApp({})).toBe(false)
	})

	it('reads and writes the three sharing choices', () => {
		expect(sharingOf(null)).toBe('organisation')
		expect(sharingOf({ isPrivate: true })).toBe('only-me')
		expect(sharingOf({ isPrivate: true, groups: ['g'] })).toBe('people-and-groups')
		expect(sharingFields('only-me', ['u'], ['g'])).toEqual({ isPrivate: true, invitedUsers: [], groups: [] })
		expect(sharingFields('organisation', null, null)).toEqual({ isPrivate: false, invitedUsers: [], groups: [] })
		expect(sharingFields('people-and-groups', ['u'], ['g'])).toEqual({ isPrivate: true, invitedUsers: ['u'], groups: ['g'] })
	})

	it('formats the catalog sharing and status columns', () => {
		const f = agentCatalogFormatters(t)
		expect(f.agentSharing(null, { isPrivate: true, invitedUsers: ['u'] })).toBe('Chosen people and groups')
		expect(f.agentSharing(null, null)).toBe('Everyone in the organisation')
		expect(f.agentStatus(false)).toBe('Switched off')
		expect(f.agentStatus(true)).toBe('On')
	})

	it('offers push and pull once an agent is linked to a repository', () => {
		expect(gitActions({})).toEqual(['publish'])
		expect(gitActions({ gitOwner: 'o', gitRepo: 'r' })).toEqual(['push', 'pull'])
		expect(repoLink({ gitOwner: 'a b', gitRepo: 'r' })).toBe('https://github.com/a%20b/r')
		expect(repoLink({ gitOwner: 'o' })).toBe('')
	})
})
