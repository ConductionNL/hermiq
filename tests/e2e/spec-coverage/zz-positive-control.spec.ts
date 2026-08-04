/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * 🔴 TEMPORARY — POSITIVE CONTROL. Delete before merge.
 *
 * A green Playwright job proves nothing until the job has been shown capable
 * of going red. hermiq's gate has already produced one green that ran three
 * tests out of thirty-five, so "the job passed" is not evidence that the suite
 * ran, and "the suite ran" is not evidence that a failure would surface.
 *
 * This spec fails on purpose. The run that carries it MUST report the job as
 * FAILED and name this file and line in the summary, with every other test
 * passing. That is the evidence; the file is then removed and the next run is
 * the real green.
 */

import { test, expect } from '@playwright/test'

test.describe('positive control (temporary)', () => {

	test('deliberately fails so the gate is proven able to report red', async () => {
		expect(
			'this assertion is designed to fail',
			'POSITIVE CONTROL: if this passes, the gate is not evaluating assertions',
		).toBe('and it must be removed once the job has reported it')
	})

})
