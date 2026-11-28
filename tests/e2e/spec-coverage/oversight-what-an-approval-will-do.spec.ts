/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * oversight-what-an-approval-will-do: a reviewer opens "Details" on a pending
 * approval and sees what it will do. The inbox read is answered by page.route
 * with the record shape ApprovalService::listPendingForReviewer() returns; the
 * preview itself is built and tested in PHPUnit (ApprovalPreviewBuilderTest,
 * ApprovalServiceTest::testInboxRecordsCarryThePreview).
 */

import { expect, test } from '@playwright/test'
import { appRoot, dismissTour, harvestToken } from './_fixtures.ts'

const DELETION = {
	id: 'appr-del',
	scheduleId: '',
	agentId: 'agent-archive',
	prompt: '',
	requestedAt: '2026-09-29T09:00:00+00:00',
	reviewer: 'admin',
	reviewerType: 'user',
	status: 'pending',
	sourceType: 'toolcall',
	toolId: 'files.deleteFile',
	toolArguments: { path: '/Archive/2019/old-invoices.pdf' },
	preview: {
		kind: 'toolcall',
		heldBecause: 'This tool needs a reviewer before the agent may call it.',
		tools: [
			{
				id: 'files.deleteFile',
				name: 'files.deleteFile',
				effect: 'deletes',
				reach: 'user',
				arguments: { path: '/Archive/2019/old-invoices.pdf' },
			},
		],
	},
}

const DIGEST = {
	...DELETION,
	id: 'appr-run',
	scheduleId: 'sched-digest',
	agentId: 'agent-digest',
	prompt: 'Summarise this week of supplier mail',
	sourceType: 'schedule',
	toolId: '',
	toolArguments: null,
	preview: {
		kind: 'run',
		heldBecause: 'This run needs a reviewer before it starts.',
		tools: [
			{
				id: 'mail.listMessages',
				name: 'mail.listMessages',
				effect: 'reads',
				reach: 'user',
			},
			{
				id: 'mail.sendMail',
				name: 'mail.sendMail',
				effect: 'sends',
				reach: 'external',
			},
		],
	},
}

test.describe('hermiq approvals: what a held action will do', () => {
	test.beforeEach(async ({ page }) => {
		await harvestToken(page)
		await page.route('**/apps/hermiq/api/approvals', (route) =>
			route.request().method() === 'GET'
				? route.fulfill({ json: { results: [DELETION, DIGEST] } })
				: route.continue(),
		)
	})

	// @e2e human-approval-gate::a-reviewer-checks-a-held-deletion
	test('a reviewer checks a held deletion', async ({ page }) => {
		const root = await appRoot(page)
		await page.goto(`${root}/approvals`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		await page.getByRole('button', { name: 'What this will do' }).first().click()
		const dialog = page.getByRole('dialog', { name: 'What this will do' })
		await expect(dialog).toContainText('files.deleteFile')
		await expect(dialog).toContainText('Deletes')
		await expect(dialog).toContainText('Your own files')
		await expect(dialog).toContainText('/Archive/2019/old-invoices.pdf')
		await expect(dialog.getByRole('button', { name: 'Approve' })).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Deny' })).toBeVisible()
	})

	// @e2e human-approval-gate::a-reviewer-checks-a-held-scheduled-run
	test('a reviewer checks a held scheduled run', async ({ page }) => {
		const root = await appRoot(page)
		await page.goto(`${root}/approvals`, { waitUntil: 'domcontentloaded' })
		await dismissTour(page)

		await page.getByRole('button', { name: 'What this will do' }).nth(1).click()
		const dialog = page.getByRole('dialog', { name: 'What this will do' })
		await expect(dialog).toContainText('Summarise this week of supplier mail')
		await expect(dialog).toContainText('mail.listMessages')
		await expect(dialog).toContainText('Reads')
		await expect(dialog).toContainText('mail.sendMail')
		await expect(dialog).toContainText('Sends')
		await expect(dialog).toContainText('Outside this Nextcloud')
	})
})
