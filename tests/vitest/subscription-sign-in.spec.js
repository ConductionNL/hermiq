/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The subscription sign-in in the user's own settings
 * (models-provider-connections Task 5 and 6): no runner, no button; the buttons
 * follow what the runner offers; the dialog walks a code and a device sign-in
 * and only ever shows the saved credential's label.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { t } from './stubs/nextcloud-l10n.js'

const api = vi.hoisted(() => ({
	fetchSignInOffer: vi.fn(),
	startSignIn: vi.fn(),
	finishSignIn: vi.fn(),
}))

vi.mock('../../src/api/subscriptionLogin.js', () => api)

const { default: SubscriptionSignIn } = await import('../../src/components/settings/SubscriptionSignIn.vue')
const { default: SubscriptionSignInModal } = await import('../../src/modals/SubscriptionSignInModal.vue')

const mountWith = (component, props = {}) => mount(component, { props, global: { mocks: { t } } })

beforeEach(() => {
	vi.clearAllMocks()
})

describe('SubscriptionSignIn settings block', () => {
	it('says the runner is needed instead of showing a button when it is not there', async () => {
		api.fetchSignInOffer.mockResolvedValue({ available: false, providers: [] })
		const w = mountWith(SubscriptionSignIn)
		await flushPromises()

		expect(w.text()).toContain('Subscription sign-in needs the Hermiq LLM runner app.')
		expect(w.find('.subscription-sign-in-settings__buttons').exists()).toBe(false)
	})

	it('shows one button per sign-in the runner offers, ChatGPT only when offered', async () => {
		api.fetchSignInOffer.mockResolvedValue({ available: true, providers: [{ provider: 'anthropic', mode: 'code' }] })
		const w = mountWith(SubscriptionSignIn)
		await flushPromises()

		const labels = w.findAll('.subscription-sign-in-settings__buttons nc-button').map((b) => b.text())
		expect(labels).toEqual(['Sign in with your Claude subscription'])
	})

	it('shows the failure of the offer call in place', async () => {
		api.fetchSignInOffer.mockRejectedValue({ response: { data: { message: 'Unauthorized' } } })
		const w = mountWith(SubscriptionSignIn)
		await flushPromises()

		expect(w.find('nc-notecard').text()).toBe('Unauthorized')
	})
})

describe('SubscriptionSignInModal', () => {
	it('walks a Claude code sign-in and shows the saved message, never a token', async () => {
		api.startSignIn.mockResolvedValue({ loginId: 'l-1', url: 'https://claude.ai/oauth/authorize?x=1', mode: 'code', userCode: '' })
		api.finishSignIn.mockResolvedValue({ id: 'cred-1', label: 'Claude subscription' })
		const w = mountWith(SubscriptionSignInModal, { show: false, provider: 'anthropic' })
		await w.setProps({ show: true })
		await flushPromises()

		expect(api.startSignIn).toHaveBeenCalledWith('anthropic')
		expect(w.find('a').attributes('href')).toBe('https://claude.ai/oauth/authorize?x=1')
		const finish = () => w.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'Finish sign-in')
		expect(finish().attributes('data-disabled')).toBe('true')

		w.findComponent({ name: 'NcTextField' }).vm.pick('  the-code ')
		await flushPromises()
		expect(finish().attributes('data-disabled')).toBe('false')
		await finish().vm.$emit('click')
		await flushPromises()

		expect(api.finishSignIn).toHaveBeenCalledWith('l-1', 'the-code')
		expect(w.text()).toContain('Signed in. Your Claude subscription is saved as a personal credential.')
		expect(w.emitted('signedIn')).toEqual([[{ id: 'cred-1', label: 'Claude subscription' }]])
	})

	it('shows the device code for a ChatGPT sign-in and finishes without one', async () => {
		api.startSignIn.mockResolvedValue({ loginId: 'l-2', url: 'https://auth.openai.com/codex/device', mode: 'device', userCode: 'ABCD-1234' })
		api.finishSignIn.mockResolvedValue({ id: 'cred-2', label: 'ChatGPT subscription' })
		const w = mountWith(SubscriptionSignInModal, { show: false, provider: 'openai' })
		await w.setProps({ show: true })
		await flushPromises()

		expect(w.find('strong').text()).toBe('ABCD-1234')
		expect(w.findComponent({ name: 'NcTextField' }).exists()).toBe(false)
		await w.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === 'I have signed in').vm.$emit('click')
		await flushPromises()

		expect(api.finishSignIn).toHaveBeenCalledWith('l-2', '')
		expect(w.text()).toContain('Signed in. Your ChatGPT subscription is saved as a personal credential.')
	})

	it('shows the server message when the sign-in cannot start', async () => {
		api.startSignIn.mockRejectedValue({ response: { data: { message: 'Subscription sign-in needs the Hermiq LLM runner app.' } } })
		const w = mountWith(SubscriptionSignInModal, { show: false })
		await w.setProps({ show: true })
		await flushPromises()

		expect(w.find('nc-notecard').text()).toBe('Subscription sign-in needs the Hermiq LLM runner app.')
		expect(w.find('a').exists()).toBe(false)
	})
})
