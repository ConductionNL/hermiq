/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The flow pages use the canonical form: an index over the `flows` source whose
 * rows open a page of type `flow`, on the addresses links already point at.
 *
 * @spec exclude Structural invariant of the shipped manifest; no behavioural spec.
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const { pages } = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const byId = new Map(pages.map((page) => [page.id, page]))
const router = createRouter({
	history: createMemoryHistory(),
	routes: pages.map((page) => ({ name: page.id, path: page.route, component: {} })),
})
const flowLists = ['FlowIndex', 'FlowIndexLegacyRedirect']

describe('flow pages', () => {
	it('names no deprecated flow page type', () => {
		expect(pages.filter((page) => ['flows', 'flow-detail'].includes(page.type)).map((page) => page.id)).toEqual([])
	})

	it.each(flowLists)('%s is an index over the flows source, scoped to this app', (id) => {
		const { type, config } = byId.get(id)
		expect(type).toBe('index')
		expect(config.entitySource).toBe('flows')
		expect(config.app).toBe('hermiq')
	})

	it.each(flowLists)('%s opens its rows on a flow page', (id) => {
		expect(byId.get(byId.get(id).config.rowRoute).type).toBe('flow')
	})

	it('keeps the flow addresses on the flow pages', () => {
		expect(router.resolve('/flows').name).toBe('FlowIndex')
		expect(router.resolve('/flows/new').name).toBe('FlowDetail')
		expect(router.resolve('/flows/abc').name).toBe('FlowDetail')
		expect(router.resolve('/graphs').name).toBe('FlowIndexLegacyRedirect')
		expect(router.resolve('/graphs/abc').name).toBe('FlowDetailLegacyRedirect')
	})
})
