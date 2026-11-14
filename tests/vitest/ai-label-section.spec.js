/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The AI label section of the AI oversight page: the counts, the reviewed share,
 * the label shown as "Gemaakt met AI" and never as the stored tag name, and the
 * Files link filtered on the stored tag.
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-hermiq-shows-the-ai-label-as-gemaakt-met-ai-req-ailbl-004
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const { default: AiLabelSection } = await import('../../src/widgets/AiLabelSection.vue')
const { aiLabelFilesUrl, markAiLabelReviewed } = await import('../../src/api/aiLabel.js')

const DUTCH = {
	'Made with AI': 'Gemaakt met AI',
	'Everything an agent writes carries the label {label}.': 'Alles wat een agent schrijft krijgt het label {label}.',
	'Notes and files': 'Notities en bestanden',
	'Reviewed afterwards': 'Achteraf nagekeken',
	'Show the files labelled {label} in Files': 'De bestanden met het label {label} tonen in Bestanden',
}

/**
 * A Dutch translator, as Nextcloud's t() would be for a Dutch person.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholders.
 * @return {string} The translated text.
 */
function t(app, text, vars = {}) {
	return (DUTCH[text] ?? text).replace(/{(\w+)}/g, (_, key) => String(vars[key]))
}

/**
 * Mount the section over the given counts.
 *
 * @param {object} stats The /api/ai-label/stats answer.
 * @return {Promise<object>} The wrapper.
 */
async function render(stats) {
	http.get.mockResolvedValue({ data: stats })
	const wrapper = mount(AiLabelSection, { global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

describe('AiLabelSection', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.post.mockReset()
	})

	it('shows the counts and the reviewed share from the stats endpoint', async () => {
		const wrapper = await render({ files: 4, reviewed: 1, reviewedShare: 25, tagId: '3', truncated: false })

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/hermiq/api/ai-label/stats')
		expect(wrapper.get('[data-testid="ai-label-files"]').text()).toBe('4')
		expect(wrapper.get('[data-testid="ai-label-reviewed"]').text()).toBe('25%')
	})

	it('names the label "Gemaakt met AI" and never the stored tag name', async () => {
		const wrapper = await render({ files: 4, reviewed: 1, reviewedShare: 25, tagId: '3', truncated: false })

		expect(wrapper.text()).toContain('Gemaakt met AI')
		expect(wrapper.text()).not.toContain('Agent authored')
	})

	it('links to Files filtered on the stored tag, and offers no link before the tag exists', async () => {
		const linked = await render({ files: 4, reviewed: 1, reviewedShare: 25, tagId: '3', truncated: false })
		expect(linked.get('[data-testid="ai-label-files-link"]').attributes('href')).toBe('/index.php/apps/files/tags?dir=/3')

		const none = await render({ files: 0, reviewed: 0, reviewedShare: 0, tagId: null, truncated: false })
		expect(none.find('[data-testid="ai-label-files-link"]').exists()).toBe(false)
		expect(aiLabelFilesUrl(null)).toBe('')
	})

	it('says why when the counts cannot be loaded', async () => {
		http.get.mockRejectedValue({ response: { data: { error: 'Authentication required' } } })
		const wrapper = mount(AiLabelSection, { global: { mocks: { t } } })
		await flushPromises()

		expect(wrapper.text()).toContain('Authentication required')
	})

	it('posts the reviewed mark for one file', async () => {
		http.post.mockResolvedValue({ data: { fileId: 42, reviewed: true } })

		await expect(markAiLabelReviewed(42)).resolves.toEqual({ fileId: 42, reviewed: true })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/hermiq/api/ai-label/files/42/reviewed')
	})
})
