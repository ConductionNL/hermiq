/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tools-agent-workspace-and-installed-tools tasks 3 and 7: the Files panel
 * under the chat header, and the builds on the agent page.
 *
 * What is real and what is answered by page.route, and why:
 * - A session workspace is filled by sandbox runs and agent tool calls,
 *   which need the code sandbox and a model. So the workspace listing and
 *   the save are answered, and what is asserted is what the panel shows and
 *   sends. WorkspaceFilesControllerTest pins the routes, including another
 *   user, and WorkspaceFilesTest the agent-authored tag on the saved file.
 * - A build archives a session workspace, so the build list and the publish
 *   are answered too. The conversation offered in the publish dialog is a
 *   real session of the seeded agent. AgentBuildServiceTest pins the publish.
 *
 * Written from the change's scenarios; not run here, it needs a running Nextcloud.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	appRoot,
	cleanupFamily,
	dismissTour,
	harvestToken,
	seedAgent,
	TEST_PREFIX,
} from './_fixtures.ts'

const API = '/index.php/apps/hermiq/api'
const REPORT = 'rapportage-q3.pdf'
const NOTE = 'Sjablonen kwartaalrapportage'

test.describe('tools-agent-workspace-and-installed-tools', () => {
	let token = ''
	let agentId = ''
	let sessionUuid = ''
	let sessionTitle = ''

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		token = await harvestToken(page)
		const agent = await seedAgent(page.request, token, {
			name: `${TEST_PREFIX}-workspace`,
			workspaceMode: 'session',
		})
		agentId = agent.id
		sessionTitle = `${TEST_PREFIX}-workspace-session`
		const created = await page.request.post(`${API}/sessions`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
			data: { agentUuid: agentId, title: sessionTitle },
		})
		expect(created.status()).toBe(201)
		sessionUuid = (await created.json()).uuid
		await page.close()
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.request.delete(`${API}/sessions/${sessionUuid}`, {
			headers: { 'OCS-APIRequest': 'true', requesttoken: token },
		})
		await cleanupFamily(page.request, token, 'agent')
		await page.close()
	})

	/**
	 * Answer the session workspace's listing and its save.
	 *
	 * @param page The Playwright page.
	 * @return The paths the panel asked to save.
	 */
	async function workspaceWithReport(page: Page): Promise<string[]> {
		const saved: string[] = []
		const base = `/api/sessions/${sessionUuid}/workspace`
		await page.route(
			new RegExp(`${base}/files(\\?.*)?$`),
			async (route: Route) => {
				await route.fulfill({
					json: {
						files: [
							{
								path: REPORT,
								size: 48213,
								modified: '2026-11-21T14:10:00+00:00',
							},
						],
						truncated: false,
					},
				})
			},
		)
		await page.route(
			new RegExp(`${base}/save(\\?.*)?$`),
			async (route: Route) => {
				const body = route.request().postDataJSON() as { path: string }
				saved.push(body.path)
				await route.fulfill({
					json: { path: `Hermiq/${body.path}`, tagged: true },
				})
			},
		)
		return saved
	}

	// @e2e agent-workspace::a-policy-officer-keeps-the-report
	test("the Files panel lists the workspace and saves a file to the person's Files", async ({
		page,
	}) => {
		const saved = await workspaceWithReport(page)

		await page.goto(`${await appRoot(page)}/chat`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)
		await page.getByText(sessionTitle).first().click()

		const panel = page.getByTestId('workspace-files')
		await expect(panel).toBeVisible({ timeout: 20_000 })
		const row = panel.getByTestId('workspace-files-row')
		await expect(row).toHaveCount(1)
		await expect(row).toContainText(REPORT)
		await expect(row.getByTestId('workspace-files-download')).toHaveAttribute(
			'href',
			new RegExp(
				`/workspace/download\\?path=${encodeURIComponent(REPORT).replace(/\./g, '\\.')}$`,
			),
		)

		await row.getByTestId('workspace-files-save').click()
		await expect.poll(() => saved).toEqual([REPORT])
		await expect(panel.getByTestId('workspace-files-message')).toHaveText(
			`Saved to your Files as Hermiq/${REPORT}.`,
		)
	})

	/**
	 * Answer the agent's build list, and record a publish.
	 *
	 * @param page The Playwright page.
	 * @return The bodies of the publishes the dialog sent.
	 */
	async function buildsOfAgent(
		page: Page,
	): Promise<Array<Record<string, unknown>>> {
		const published: Array<Record<string, unknown>> = []
		const build3 = {
			id: `${TEST_PREFIX}-build-3`,
			number: 3,
			note: NOTE,
			createdBy: 'admin',
			createdByName: 'admin',
			createdAt: '2026-11-21T15:00:00+00:00',
			files: ['sjabloon-kwartaal.docx', 'sjabloon-bijlage.xlsx'],
			tools: [{ name: 'csvkit', version: '2.1.0' }],
		}
		await page.route(
			new RegExp(`/api/agents/${agentId}/builds(\\?.*)?$`),
			async (route: Route) => {
				if (route.request().method() === 'POST') {
					published.push(
						route.request().postDataJSON() as Record<string, unknown>,
					)
					await route.fulfill({ status: 201, json: build3 })
					return
				}
				await route.fulfill({
					json: {
						builds: [build3],
						pinnedBuildId: build3.id,
						pinnedBuildNumber: 3,
						unpublishedChanges: true,
						canPublish: true,
					},
				})
			},
		)
		return published
	}

	// @e2e agent-versioning::an-agent-owner-publishes-build-3
	// @e2e agent-versioning::edits-after-publishing-are-visible-to-the-owner
	test('the agent page lists build 3 pinned, says what changed since, and publishes with a note', async ({
		page,
	}) => {
		const published = await buildsOfAgent(page)

		await page.goto(`${await appRoot(page)}/agents/${agentId}`, {
			waitUntil: 'domcontentloaded',
		})
		await dismissTour(page)

		const panel = page.getByTestId('agent-builds')
		await expect(panel).toBeVisible({ timeout: 20_000 })
		const row = panel.getByTestId('agent-builds-row')
		await expect(row).toHaveCount(1)
		await expect(row).toContainText('Build 3')
		await expect(row).toContainText('published by admin on')
		await expect(row).toContainText(NOTE)
		await expect(row).toContainText('2 files, 1 tool')
		await expect(row.getByTestId('agent-builds-pinned')).toHaveText('Pinned')
		await expect(panel.getByTestId('agent-builds-unpublished')).toContainText(
			'Unpublished changes since build 3',
		)

		await panel.getByTestId('agent-builds-publish').click()
		const dialog = page.getByRole('dialog')
		await dialog.getByTestId('publish-build-session').click()
		await page.getByRole('option', { name: sessionTitle }).click()
		await dialog.getByTestId('publish-build-note').locator('textarea').fill(NOTE)
		await dialog.getByTestId('publish-build-submit').click()

		await expect.poll(() => published.length).toBe(1)
		expect(published[0]).toEqual({ sessionId: sessionUuid, note: NOTE })
	})
})
