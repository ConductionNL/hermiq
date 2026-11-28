/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * ToolGrantMatrix turns the tool catalogue into per-application clusters of
 * subject rows with verb columns. These tests pin the clustering, the write and
 * destructive classification, the wildcard third state, the grant toggles that
 * compare both id spellings, and the filters.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	getToolCatalog: vi.fn(),
	updateToolGrants: vi.fn(),
	getAgentGrants: vi.fn(),
	getToolTaxonomy: vi.fn(),
}))

vi.mock('../../src/api/toolOversight.js', () => ({
	getToolCatalog: api.getToolCatalog,
	updateToolGrants: api.updateToolGrants,
}))

vi.mock('../../src/api/toolTaxonomy.js', async (importOriginal) => ({
	...(await importOriginal()),
	getAgentGrants: api.getAgentGrants,
	getToolTaxonomy: api.getToolTaxonomy,
}))

const { default: ToolGrantMatrix } =
	await import('../../src/components/ToolGrantMatrix.vue')

/**
 * The catalogue the matrix reads: dotted ids, as the catalogue spells them.
 *
 * @return {object} The catalogue.
 */
function catalogue() {
	return {
		tools: [
			{ id: 'pipelinq.createLead', description: 'Create a lead', scope: 'write', granted: true, grantedBy: 'pipelinq.createLead' },
			{ id: 'pipelinq.getLead', description: 'Read one lead', scope: 'read', granted: false },
			{ id: 'pipelinq.deleteLead', description: 'Delete a lead', scope: 'write', destructiveHint: true, requiresExplicitGrant: true },
			{ id: 'pipelinq.updateLead', scope: 'write', granted: true, grantedBy: 'pipelinq.*' },
			{ id: 'hermiq.delegateAgent', description: 'Hand over to an agent' },
			{ id: 'hermiq.sendMail', description: 'Send an e-mail', scope: 'write', requiresExplicitGrant: true },
			{ id: 'orphan.webFetch', description: 'Fetch a page' },
		],
	}
}

/**
 * The taxonomy, in the underscore spelling the taxonomy endpoint uses.
 *
 * @return {Array<object>} The taxonomy entries.
 */
function taxonomy() {
	return [
		{ name: 'pipelinq_createLead', app: 'pipelinq' },
		{ name: 'pipelinq_getLead', app: 'pipelinq' },
		{ name: 'pipelinq_deleteLead', app: 'pipelinq' },
		{ name: 'pipelinq_updateLead', app: 'pipelinq' },
		{ name: 'hermiq_delegateAgent', app: 'hermiq' },
		{ name: 'hermiq_sendMail', app: 'hermiq' },
	]
}

/**
 * Mount the matrix and let its first load settle.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function matrix(props = {}) {
	const wrapper = mount(ToolGrantMatrix, {
		props: { agentId: 'agent-1', canEdit: true, ...props },
	})
	await flushPromises()
	return wrapper
}

describe('ToolGrantMatrix grid', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		api.getToolCatalog.mockResolvedValue(catalogue())
		api.getToolTaxonomy.mockResolvedValue(taxonomy())
		// Stored in the underscore spelling, while the catalogue uses dots.
		api.getAgentGrants.mockResolvedValue(['pipelinq_createLead'])
		api.updateToolGrants.mockResolvedValue({})
	})

	it('files tools under their application, sorted, with the unknown ones under "No application"', async () => {
		const wrapper = await matrix()

		const clusters = wrapper.vm.visibleClusters
		expect(clusters.map((cluster) => cluster.label)).toEqual([
			'hermiq',
			'No application',
			'pipelinq',
		])
		expect(clusters[1].id).toBe('__none__')

		const pipelinq = clusters[2]
		expect(pipelinq.verbs).toEqual(['create', 'read', 'update', 'list', 'delete'])
		expect(pipelinq.rows).toHaveLength(1)
		expect(pipelinq.rows[0].label).toBe('lead')
		expect(pipelinq.rows[0].description).toBe('Create a lead')
		expect(Object.keys(pipelinq.rows[0].tools).sort()).toEqual(['create', 'delete', 'read', 'update'])
		// The held exact grant and the wildcard both count.
		expect(pipelinq.grantedCount).toBe(2)

		const hermiq = clusters[0]
		expect(hermiq.verbs).toEqual(['create', 'read', 'update', 'list', 'delete', 'special'])
		expect(hermiq.rows.map((row) => row.label)).toEqual(['agent', 'mail'])
		expect(hermiq.rows[0].specials.map((tool) => tool.specialLabel)).toEqual(['delegate'])
		expect(hermiq.grantedCount).toBe(0)
	})

	it('opens only the clusters that already hold a grant', async () => {
		const wrapper = await matrix()

		expect(wrapper.vm.openClusters).toEqual({ pipelinq: true })
		expect(wrapper.vm.isOpen('hermiq')).toBe(false)

		const header = wrapper.findAll('button.grant-matrix__cluster-header')
			.find((button) => button.text().includes('hermiq'))
		expect(header.attributes('aria-expanded')).toBe('false')
		await header.trigger('click')
		expect(wrapper.vm.isOpen('hermiq')).toBe(true)
		expect(header.attributes('aria-expanded')).toBe('true')
		await header.trigger('click')
		expect(wrapper.vm.isOpen('hermiq')).toBe(false)
	})

	it('carries the scope, destructive and explicit-grant classification', async () => {
		const wrapper = await matrix()
		const byId = Object.fromEntries(wrapper.vm.tools.map((tool) => [tool.id, tool]))

		expect(byId['pipelinq.getLead']).toMatchObject({ scope: 'read', destructive: false, wildcard: false, app: 'pipelinq' })
		expect(byId['pipelinq.deleteLead']).toMatchObject({ scope: 'write', destructive: true, requiresExplicitGrant: true })
		expect(byId['pipelinq.updateLead']).toMatchObject({ granted: true, grantedBy: 'pipelinq.*', wildcard: true })
		expect(byId['pipelinq.createLead'].wildcard).toBe(false)
		expect(byId['orphan.webFetch'].app).toBeNull()

		expect(wrapper.vm.classificationLabel(undefined)).toBe('')
		expect(wrapper.vm.classificationLabel(byId['pipelinq.getLead'])).toBe('')
		expect(wrapper.vm.classificationLabel(byId['pipelinq.createLead'])).toBe('write')
		expect(wrapper.vm.classificationLabel(byId['hermiq.sendMail'])).toBe('write: requires explicit grant')
		expect(wrapper.vm.classificationLabel(byId['pipelinq.deleteLead'])).toBe('destructive: requires explicit grant')
	})

	it('names the verb, subject, cluster and classification in the accessible label', async () => {
		const wrapper = await matrix()
		const cluster = wrapper.vm.visibleClusters.find((entry) => entry.id === 'pipelinq')
		const row = cluster.rows[0]

		expect(wrapper.vm.ariaFor(cluster, row, 'read')).toBe('Grant Read on lead in pipelinq')
		expect(wrapper.vm.ariaFor(cluster, row, 'delete')).toBe(
			'Grant Delete on lead in pipelinq — destructive: requires explicit grant',
		)
		expect(wrapper.vm.verbLabel('special')).toBe('Special')
		expect(wrapper.vm.verbLabel('approve')).toBe('approve')
	})

	it('renders a wildcard grant as its own state, not as a checkbox', async () => {
		const wrapper = await matrix()

		const wildcard = wrapper.find('.grant-matrix__wildcard')
		expect(wildcard.attributes('title')).toBe(
			'Granted by the wildcard pipelinq.*. Edit that grant to change it.',
		)
		expect(wildcard.text()).toContain('Granted via wildcard')
		const labels = wrapper.findAll('nc-checkboxradioswitch')
			.map((box) => box.attributes('data-aria-label'))
		expect(labels).toContain('Grant Create on lead in pipelinq — write')
		expect(labels.some((label) => label?.startsWith('Grant Update on lead'))).toBe(false)
		expect(wrapper.find('.grant-matrix__reach--destructive').text()).toBe('del')
	})

	it('treats both id spellings as one grant when ticking and unticking', async () => {
		const wrapper = await matrix()

		expect(wrapper.vm.isDrafted('pipelinq.createLead')).toBe(true)
		expect(wrapper.vm.dirty).toBe(false)

		// Ticking a held grant in the other spelling adds nothing.
		wrapper.vm.toggleTool('pipelinq.createLead', true)
		expect(wrapper.vm.draftGrants).toEqual(['pipelinq_createLead'])

		wrapper.vm.toggleTool('pipelinq.getLead', true)
		expect(wrapper.vm.draftGrants).toEqual(['pipelinq_createLead', 'pipelinq.getLead'])
		expect(wrapper.vm.grantedCount).toBe(2)
		expect(wrapper.vm.dirty).toBe(true)

		// Unticking in the dotted spelling clears the underscore grant.
		wrapper.vm.toggleTool('pipelinq.createLead', false)
		expect(wrapper.vm.draftGrants).toEqual(['pipelinq.getLead'])

		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('dirtyChanged').map((event) => event[0])).toEqual([false, true])

		wrapper.vm.reset()
		expect(wrapper.vm.draftGrants).toEqual(['pipelinq_createLead'])
		expect(wrapper.vm.dirty).toBe(false)
	})

	it('ticks a special action through its own checkbox', async () => {
		const wrapper = await matrix()
		await wrapper.vm.toggleCluster('hermiq')

		const box = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			.find((stand) => stand.attributes('data-aria-label') === 'Grant delegate on agent')
		box.vm.pick(true)

		expect(wrapper.vm.draftGrants).toEqual(['pipelinq_createLead', 'hermiq.delegateAgent'])
	})

	it('reports equal-length drafts that differ as dirty', async () => {
		const wrapper = await matrix()

		wrapper.vm.draftGrants = ['pipelinq.getLead']

		expect(wrapper.vm.dirty).toBe(true)
	})

	it('filters on id, application, subject and description', async () => {
		const wrapper = await matrix()

		wrapper.vm.filter = '  E-MAIL '
		expect(wrapper.vm.filteredTools.map((tool) => tool.id)).toEqual(['hermiq.sendMail'])

		wrapper.vm.filter = 'pipelinq'
		expect(wrapper.vm.filteredTools).toHaveLength(4)

		wrapper.vm.filter = 'nothing like this'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.visibleClusters).toEqual([])
		const empty = wrapper.find('nc-emptycontent')
		expect(empty.attributes('data-name')).toBe('Nothing matches')
		expect(empty.attributes('data-description')).toBe('No tool matches this filter.')
	})

	it('shows only drafted and wildcard grants when "Only granted" is on', async () => {
		const wrapper = await matrix()

		wrapper.vm.grantedOnly = true
		expect(wrapper.vm.filteredTools.map((tool) => tool.id)).toEqual([
			'pipelinq.createLead',
			'pipelinq.updateLead',
		])

		wrapper.vm.filter = 'mail'
		await wrapper.vm.$nextTick()
		expect(wrapper.find('nc-emptycontent').attributes('data-description')).toBe(
			'No granted tool matches this filter.',
		)
	})

	it('counts the drafted grants and the catalogue size in the toolbar', async () => {
		const wrapper = await matrix()

		// The l10n stand-in leaves %n as is; the singular and plural picked show the counts.
		expect(wrapper.find('.grant-matrix__count').text()).toMatch(/%n right granted\s+·\s+%n tools/)
		expect(wrapper.vm.grantedCount).toBe(1)
		expect(wrapper.vm.totalTools).toBe(7)
	})

	it('shows the server error when the catalogue cannot be loaded', async () => {
		api.getToolCatalog.mockRejectedValue({ message: 'Network Error', response: { data: { error: 'Not allowed' } } })
		const wrapper = await matrix()

		expect(wrapper.vm.error).toBe('Not allowed')
		const empty = wrapper.find('nc-emptycontent')
		expect(empty.attributes('data-name')).toBe('Tools could not be loaded')
		expect(empty.attributes('data-description')).toBe('Not allowed')
	})

	it('saves the edited draft and reloads', async () => {
		const wrapper = await matrix()
		wrapper.vm.toggleTool('pipelinq.getLead', true)
		api.getAgentGrants.mockResolvedValue(['pipelinq_createLead', 'pipelinq.getLead'])

		const saved = await wrapper.vm.persist()

		expect(saved).toEqual(['pipelinq_createLead', 'pipelinq.getLead'])
		expect(api.updateToolGrants).toHaveBeenCalledWith('agent-1', ['pipelinq_createLead', 'pipelinq.getLead'])
		expect(api.getAgentGrants).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.dirty).toBe(false)
	})
})
