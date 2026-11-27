// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * How the run list words a recorded code run (tools-code-sandbox).
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */

import { describe, expect, it } from 'vitest'
import { codeRunOutcome, codeRunOutputs } from '../../src/utils/codeRuns.js'

describe('codeRunOutcome', () => {
	it('reads an exit code', () => {
		expect(codeRunOutcome({ exitCode: 0 })).toEqual({ kind: 'exit', exitCode: 0, error: '' })
		expect(codeRunOutcome({ exitCode: 1 }).kind).toBe('exit')
	})

	it('lets a time-out win over the exit code a killed run reports', () => {
		expect(codeRunOutcome({ exitCode: 137, timedOut: true })).toEqual({ kind: 'timedOut', exitCode: 137, error: '' })
	})

	it('falls back to the sandbox error code, then to unknown', () => {
		expect(codeRunOutcome({ error: 'sandbox_unavailable' })).toEqual({ kind: 'error', exitCode: null, error: 'sandbox_unavailable' })
		expect(codeRunOutcome({ exitCode: '0' }).kind).toBe('unknown')
		expect(codeRunOutcome(null).kind).toBe('unknown')
	})
})

describe('codeRunOutputs', () => {
	it('lists each non-empty stream with whether it was cut', () => {
		expect(codeRunOutputs({ stdout: '2\n', stdoutTruncated: false, stderr: 'warn', stderrTruncated: true })).toEqual([
			{ stream: 'stdout', text: '2\n', truncated: false },
			{ stream: 'stderr', text: 'warn', truncated: true },
		])
		expect(codeRunOutputs({ stdout: '', stderr: 'boom' })).toEqual([{ stream: 'stderr', text: 'boom', truncated: false }])
	})

	it('shows nothing of a withheld output, or of a missing record', () => {
		expect(codeRunOutputs({ stdout: 'x', outputWithheld: true })).toEqual([])
		expect(codeRunOutputs(undefined)).toEqual([])
	})
})
