/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Characterisation of the two copy-once webhook secret dialogs: selecting and
 * copying the revealed secret, and the schedule dialog's status, mint, rotate
 * and revoke.
 */

import * as dialogs from '@nextcloud/dialogs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/api/scheduleWebhookSecret.js', () => ({
	getScheduleWebhookSecretStatus: vi.fn(),
	mintScheduleWebhookSecret: vi.fn(),
	revokeScheduleWebhookSecret: vi.fn(),
	rotateScheduleWebhookSecret: vi.fn(),
}))

const api = await import('../../src/api/scheduleWebhookSecret.js')
const { default: ScheduleWebhookSecretDialog } = await import('../../src/modals/ScheduleWebhookSecretDialog.vue')
const { default: WebhookSecretDialog } = await import('../../src/modals/WebhookSecretDialog.vue')

/**
 * A dialog context with its methods bound.
 *
 * @param {object} component The component.
 * @param {object} state Its data and props.
 * @return {object} The context.
 */
function dialog(component, state) {
	const methods = Object.assign({}, ...(component.mixins || []).map((mixin) => mixin.methods || {}), component.methods)
	return { ...methods, t: (app, text) => text, ...state }
}

let writeText

beforeEach(() => {
	writeText = vi.fn(async () => {})
	vi.stubGlobal('navigator', { clipboard: { writeText } })
	vi.mocked(dialogs.showError).mockClear()
	vi.mocked(dialogs.showSuccess).mockClear()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe.each([
	['WebhookSecretDialog', WebhookSecretDialog, { secret: 'S1' }, 'S1'],
	['ScheduleWebhookSecretDialog', ScheduleWebhookSecretDialog, { revealedSecret: 'S2' }, 'S2'],
])('%s', (name, component, state, secret) => {
	it('selects the field on focus, when it can', () => {
		const ctx = dialog(component, state)
		const select = vi.fn()
		ctx.selectAll({ target: { select } })
		expect(select).toHaveBeenCalled()
		expect(() => ctx.selectAll({ target: {} })).not.toThrow()
		expect(() => ctx.selectAll(null)).not.toThrow()
	})

	it('copies the secret, and says so', async () => {
		await dialog(component, state).copy()
		expect(writeText).toHaveBeenCalledWith(secret)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Secret copied to clipboard.')
	})

	it('asks for a manual copy when the clipboard refuses', async () => {
		writeText.mockRejectedValue(new Error('insecure'))
		await dialog(component, state).copy()
		expect(dialogs.showError).toHaveBeenCalledWith('Could not copy automatically — select and copy the value manually.')
	})
})

describe('ScheduleWebhookSecretDialog actions', () => {
	it('loads the status, unconfigured when it cannot', async () => {
		vi.mocked(api.getScheduleWebhookSecretStatus).mockResolvedValue({ configured: true })
		const ctx = dialog(ScheduleWebhookSecretDialog, { scheduleId: 'sch-1' })
		await ctx.loadStatus()
		expect(api.getScheduleWebhookSecretStatus).toHaveBeenCalledWith('sch-1')
		expect(ctx.status).toEqual({ configured: true })

		vi.mocked(api.getScheduleWebhookSecretStatus).mockRejectedValue(new Error('x'))
		await ctx.loadStatus()
		expect(ctx.status).toEqual({ configured: false })
	})

	it.each([
		['mint', 'mintScheduleWebhookSecret', 'Could not mint webhook secret'],
		['rotate', 'rotateScheduleWebhookSecret', 'Could not rotate webhook secret'],
	])('%s reveals the new secret, or reports the failure', async (action, call, failure) => {
		vi.mocked(api[call]).mockResolvedValue({ secret: 'NEW', rotatedAt: '2026-11-10' })
		const ctx = dialog(ScheduleWebhookSecretDialog, { scheduleId: 'sch-1', error: 'old' })
		await ctx[action]()
		expect(api[call]).toHaveBeenCalledWith('sch-1')
		expect(ctx.revealedSecret).toBe('NEW')
		expect(ctx.status).toEqual({ configured: true, rotatedAt: '2026-11-10' })
		expect(ctx.error).toBe('')
		expect(ctx.busy).toBe(false)

		vi.mocked(api[call]).mockRejectedValue({ response: { data: { error: 'Denied' } } })
		await ctx[action]()
		expect(ctx.error).toBe('Denied')
		vi.mocked(api[call]).mockRejectedValue(new Error('plain'))
		await ctx[action]()
		expect(ctx.error).toBe(failure)
		expect(ctx.revealedSecret).toBe('NEW')
		expect(ctx.busy).toBe(false)
	})

	it('revoke clears the secret and says so, or reports the failure', async () => {
		vi.mocked(api.revokeScheduleWebhookSecret).mockResolvedValue({ configured: false })
		const ctx = dialog(ScheduleWebhookSecretDialog, { scheduleId: 'sch-1', revealedSecret: 'OLD', error: 'old' })
		await ctx.revoke()
		expect(ctx.status).toEqual({ configured: false })
		expect(ctx.revealedSecret).toBe('')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Webhook secret revoked.')
		expect(ctx.error).toBe('')

		vi.mocked(api.revokeScheduleWebhookSecret).mockRejectedValue(new Error('plain'))
		await ctx.revoke()
		expect(ctx.error).toBe('Could not revoke webhook secret')
		expect(ctx.busy).toBe(false)
	})
})
