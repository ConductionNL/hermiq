/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operations-backup-and-restore task 5 (REQ-BKUP-002): an administrator picks
 * a backup in the restore dialog and reads, per kind of object, what the
 * restore would change, before anything is written.
 *
 * 🔴 NOTHING MAY BE WRITTEN BY A PREVIEW. The spec records every request to the
 * restore route and fails when the preview sent one. A dialog that ran the
 * restore on "Preview" would still show a table, so the table alone proves
 * nothing about the scenario's "nothing has been written yet".
 *
 * 🔴 THE BACKUP AND THE PREVIEW ARE FIXED, NOT WAITED FOR. A real backup needs
 * the nightly job or a queued BackupNowJob, which a browser cannot hurry. The
 * backup list and the preview are answered through page.route, in the shapes
 * BackupControllerTest and tests/vitest/restore-dialog.spec.js pin (12 agents
 * changed back, 1 created after the backup). What is NOT stubbed is the admin
 * page, the dialog, the backup choice and the preview table.
 *
 * Runs as the suite's administrator (the default storage state). Written for
 * the live pass (decision 139):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test spec-coverage/backup-restore
 *
 * @spec openspec/specs/backup-restore/spec.md#requirement-a-restore-starts-with-a-preview-of-what-will-change-req-bkup-002
 */

import { expect, test } from '@playwright/test'
import { dismissTour } from './_fixtures.ts'

const FILE = 'hermiq-backup-tilburg-2026-09-28-0200.json.gz'

const BACKUPS = {
	backups: [
		{
			name: FILE,
			manifest: {
				organisation: 'tilburg',
				createdAt: '2026-09-28T02:00:00+00:00',
				registerVersion: '0.32.0',
				counts: { agent: 15, agentskill: 5 },
			},
		},
	],
}

const PREVIEW = {
	point: { kind: 'backup', organisation: 'tilburg', file: FILE, at: null, removeCreatedAfter: false },
	organisation: 'tilburg',
	refused: null,
	schemas: {
		agent: { putBack: 0, changed: 12, unchanged: 3, createdAfter: 1, missing: 0, noHistory: 0, notRestorable: [] },
		agentskill: { putBack: 0, changed: 0, unchanged: 5, createdAfter: 0, missing: 0, noHistory: 0, notRestorable: [] },
	},
	writes: 12,
	createdAfter: 1,
}

test.describe('backup-restore', () => {
	// e2e: backup-restore::an-administrator-checks-a-restore-before-running-it
	test('An administrator checks a restore before running it', async ({ page }) => {
		const restores: string[] = []
		const previews: unknown[] = []
		await page.route('**/apps/hermiq/api/backup/files', (route) => route.fulfill({ json: BACKUPS }))
		await page.route('**/apps/hermiq/api/backup/restore/preview', (route) => {
			previews.push(route.request().postDataJSON())
			return route.fulfill({ json: PREVIEW })
		})
		await page.route('**/apps/hermiq/api/backup/restore', (route) => {
			restores.push(route.request().method())
			return route.fulfill({ status: 202, json: { queued: true, organisation: 'tilburg', writes: 12 } })
		})

		await page.goto('/settings/admin/hermiq', { waitUntil: 'domcontentloaded' })
		await dismissTour(page)
		await page.getByTestId('restore-open').click()

		await page.getByTestId('restore-kind-backup').click()
		await page.getByTestId('restore-backup').click()
		await page.getByRole('option', { name: /tilburg/ }).click()
		await page.getByTestId('restore-preview').click()

		const table = page.getByTestId('restore-preview-table')
		await expect(table).toBeVisible()
		const agents = table.getByRole('row').filter({ has: page.getByRole('rowheader', { name: 'agent', exact: true }) })
		await expect(agents).toHaveCount(1)
		const cells = agents.getByRole('cell')
		await expect(cells.nth(1)).toHaveText('12')
		await expect(cells.nth(2)).toHaveText('1')
		// A kind where nothing changes is not listed.
		await expect(table.getByRole('rowheader', { name: 'agentskill', exact: true })).toHaveCount(0)

		expect(previews).toHaveLength(1)
		expect(previews[0]).toMatchObject({ kind: 'backup', file: FILE })
		expect(restores, 'the preview must not start the restore').toEqual([])
		await expect(page.getByTestId('restore-queued')).toHaveCount(0)
	})
})
