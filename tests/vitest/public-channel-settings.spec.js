/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Public chat settings: only agents without tools may be public, a
 * channel saves what the form shows and keeps what it does not, and a saved
 * website channel shows a script and an iframe snippet.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

import { describe, expect, it } from 'vitest'
import {
	agentHasTools,
	channelForm,
	channelPayload,
	DEFAULT_DISCLOSURE,
	embedSnippets,
	formProblems,
	invalidOrigins,
	originsFrom,
	publicAgentOptions,
	scopeFrom,
	scopeText,
} from '../../src/utils/publicChannel.js'

const t = (app, text) => text
const TOKEN = 'Chan0123456789abcdef'

describe('public chat settings', () => {
	it('offers only agents without tools', () => {
		expect(agentHasTools({ tools: [] })).toBe(false)
		expect(agentHasTools({ tools: ['__none__'] })).toBe(false)
		expect(agentHasTools({})).toBe(false)
		expect(agentHasTools({ tools: ['hermiq.sendMail'] })).toBe(true)
		expect(publicAgentOptions([
			{ id: 'a', name: 'Public information', tools: [] },
			{ id: 'b', name: 'Mailer', tools: ['hermiq.sendMail'] },
			{ uuid: 'c', name: 'Sentinel', tools: ['__none__'] },
			{ name: 'No id' },
		])).toEqual([{ id: 'a', label: 'Public information' }, { id: 'c', label: 'Sentinel' }])
	})

	it('reads origins and scope from their text fields', () => {
		expect(originsFrom('https://www.example.nl/\nhttps://www.example.nl, http://localhost:8080 ')).toEqual(['https://www.example.nl', 'http://localhost:8080'])
		expect(invalidOrigins(['https://www.example.nl', 'https://x.nl/chat', 'www.example.nl'])).toEqual(['https://x.nl/chat', 'www.example.nl'])
		expect(scopeFrom('publication/page\n\n opencatalogi \n/orphan')).toEqual([{ register: 'publication', schema: 'page' }, { register: 'opencatalogi', schema: '' }])
		expect(scopeText([{ register: 'publication', schema: 'page' }, { register: 'opencatalogi' }, {}])).toBe('publication/page\nopencatalogi')
	})

	it('starts a new channel off, with the default disclosure and limit', () => {
		expect(channelForm(null)).toEqual({ name: '', kind: 'website', channelKey: '', agentId: '', origins: '', scope: '', disclosureText: DEFAULT_DISCLOSURE, dailyQuestionLimit: 500, enabled: false })
	})

	it('saves what the form shows and keeps the token', () => {
		const stored = { id: 'ch-1', '@self': { id: 'ch-1' }, channelToken: TOKEN, kind: 'website', allowedOrigins: ['https://www.example.nl'], sourceScope: [{ register: 'publication', schema: 'page' }], enabled: false, dailyQuestionLimit: 200 }
		const form = { ...channelForm(stored), name: ' Website ', agentId: 'a', enabled: true, dailyQuestionLimit: '0', disclosureText: '  ' }

		expect(channelPayload(form, stored)).toEqual({
			id: 'ch-1', channelToken: TOKEN, name: 'Website', kind: 'website', channelKey: '', agentId: 'a',
			allowedOrigins: ['https://www.example.nl'], sourceScope: [{ register: 'publication', schema: 'page' }],
			disclosureText: DEFAULT_DISCLOSURE, dailyQuestionLimit: 500, enabled: true,
		})
		expect(channelPayload({ ...form, kind: 'app', channelKey: ' app:portaliq:wonen ' }, null)).toMatchObject({ kind: 'app', channelKey: 'app:portaliq:wonen', allowedOrigins: [] })
	})

	it('says what is wrong before saving', () => {
		expect(formProblems(channelForm(null), t)).toEqual(['Name the channel.', 'Pick the agent that answers on this channel.'])
		expect(formProblems({ ...channelForm(null), name: 'W', agentId: 'a', origins: 'https://x.nl/chat' }, t)).toEqual(['An allowed origin is a scheme and a host, such as https://www.example.nl, without a path.'])
		expect(formProblems({ ...channelForm(null), name: 'P', agentId: 'a', kind: 'app', channelKey: 'portaliq' }, t)).toEqual(['An app channel key looks like app:portaliq:wonen.'])
		expect(formProblems({ ...channelForm(null), name: 'P', agentId: 'a', kind: 'app', channelKey: 'app:portaliq:wonen' }, t)).toEqual([])
	})

	it('shows a script snippet and an iframe snippet for a saved channel', () => {
		expect(embedSnippets(TOKEN, 'https://cloud.example.nl', '/apps/hermiq/js/public-chat.js', `/index.php/apps/hermiq/public/${TOKEN}`)).toEqual({
			script: `<script src="https://cloud.example.nl/apps/hermiq/js/public-chat.js?channel=${TOKEN}" async></script>`,
			iframe: `<iframe src="https://cloud.example.nl/index.php/apps/hermiq/public/${TOKEN}" title="Chat" width="380" height="560" style="border:0"></iframe>`,
		})
		expect(embedSnippets('', 'https://x', '/a', '/b')).toBeNull()
		expect(embedSnippets('"><script>', 'https://x', '/a', '/b')).toBeNull()
	})
})
