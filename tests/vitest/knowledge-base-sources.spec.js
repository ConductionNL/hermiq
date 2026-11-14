/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * memory-knowledge-bases: the knowledge base API helper and the sources
 * widget's pure helpers (what is stored, what a Files pick adds), plus the
 * widget's notices: search not available, and only-you per source.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const axiosPost = vi.fn()
const fetchObject = vi.fn(async () => ({ id: 'kb-1', name: 'Inkoopbeleid', sources: [] }))
const saveObject = vi.fn(async () => ({}))

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => axiosGet(...args), post: (...args) => axiosPost(...args) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => '/index.php' + path.replace('{id}', params?.id ?? ''),
}))
vi.mock('@nextcloud/dialogs', () => ({ getFilePickerBuilder: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({
	useKnowledgeBaseStore: () => ({ registerObjectType: vi.fn(), fetchObject, saveObject }),
}))

const { fetchSources, uploadDocuments } = await import('../../src/api/knowledgeBases.js')
const { default: KnowledgeBaseSources, storedSource, withPicked } = await import('../../src/widgets/KnowledgeBaseSources.vue')

describe('knowledge base API', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
	})

	it('uploads every file as files[] to the documents route', async () => {
		axiosPost.mockResolvedValue({ data: { results: [{ type: 'file', fileId: 7 }] } })
		const a = new File(['a'], 'a.pdf')
		const b = new File(['b'], 'b.pdf')

		const sources = await uploadDocuments('kb-1', [a, b])

		expect(axiosPost.mock.calls[0][0]).toBe('/index.php/apps/hermiq/api/knowledge-bases/kb-1/documents')
		expect(axiosPost.mock.calls[0][1].getAll('files[]').map((f) => f.name)).toEqual(['a.pdf', 'b.pdf'])
		expect(sources).toEqual([{ type: 'file', fileId: 7 }])
	})

	it('reads the sources and treats anything but true as search not available', async () => {
		axiosGet.mockResolvedValue({ data: { results: [{ type: 'folder', fileId: 3 }], searchAvailable: 'yes' } })

		expect(await fetchSources('kb-1')).toEqual({ sources: [{ type: 'folder', fileId: 3 }], searchAvailable: false })
		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/hermiq/api/knowledge-bases/kb-1/sources')
	})
})

describe('sources helpers', () => {
	it('stores a source without its computed state', () => {
		expect(storedSource({ type: 'file', fileId: 7, path: 'a.pdf', onlyYou: true, missing: false, indexState: 'indexed' }))
			.toEqual({ type: 'file', fileId: 7, path: 'a.pdf' })
	})

	it('adds picked nodes once, as folders when folders were picked', () => {
		const current = [{ type: 'file', fileId: 7, path: 'a.pdf' }]

		expect(withPicked(current, [{ fileid: 7, path: '/a.pdf' }, { fileid: 9, path: '/Team Inkoop/Kennisbank' }], true)).toEqual([
			{ type: 'file', fileId: 7, path: 'a.pdf' },
			{ type: 'folder', fileId: 9, path: 'Team Inkoop/Kennisbank' },
		])
		expect(withPicked(current, [{ fileid: 0, path: '/x' }], false)).toEqual(current)
	})
})

describe('KnowledgeBaseSources', () => {
	const mountWidget = () => mount(KnowledgeBaseSources, {
		global: {
			mocks: { $route: { params: { id: 'kb-1' } }, t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m), n: (app, s) => s },
			stubs: { NcButton: { template: '<button><slot /></button>' }, NcLoadingIcon: true, NcNoteCard: { template: '<div class="note"><slot /></div>' } },
		},
	})

	it('says search is not available and warns about a source only the owner can read', async () => {
		axiosGet.mockResolvedValue({
			data: {
				results: [
					{ type: 'folder', fileId: 10, path: 'Inkoop/Privé', onlyYou: true, missing: false, indexState: 'pending' },
					{ type: 'folder', fileId: 11, path: 'Integraties/SharePoint/Beleidsdocumenten', label: 'Synced by integriq from SharePoint', onlyYou: false, indexState: 'indexed' },
				],
				searchAvailable: false,
			},
		})

		const wrapper = mountWidget()
		await flushPromises()

		const text = wrapper.text()
		expect(text).toContain('Search is not available on this server.')
		expect(text).toContain('Only you can read this folder.')
		expect(text.match(/Only you can read/g)).toHaveLength(1)
		expect(text).toContain('Synced by integriq from SharePoint')
		expect(text).toContain('Waiting to be indexed')
		expect(text).toContain('Indexed')
	})

	it('removes a source by saving the rest without their state', async () => {
		axiosGet.mockResolvedValue({
			data: { results: [{ type: 'file', fileId: 7, path: 'a.pdf', onlyYou: true }, { type: 'file', fileId: 8, path: 'b.pdf', onlyYou: false }], searchAvailable: true },
		})
		const wrapper = mountWidget()
		await flushPromises()

		await wrapper.vm.remove(0)

		expect(saveObject).toHaveBeenCalledWith('agentknowledgebase', { id: 'kb-1', name: 'Inkoopbeleid', sources: [{ type: 'file', fileId: 8, path: 'b.pdf' }] })
	})
})
