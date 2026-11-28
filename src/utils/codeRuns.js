// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Code run helpers for the run list (tools-code-sandbox).
 *
 * Plain functions without Vue or Nextcloud imports, so a spec runs them under node.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */

/**
 * How a recorded code run ended, as one of four kinds the run list words.
 *
 * A time-out wins over an exit code (a killed run still reports one), a sandbox
 * error code over a missing exit code, and a record with neither is `unknown`.
 *
 * @param {object|null|undefined} codeRun One entry of the run row's `codeRuns`.
 * @return {{kind: string, exitCode: (number|null), error: string}} The outcome.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */
export function codeRunOutcome(codeRun) {
	const run = codeRun && typeof codeRun === 'object' ? codeRun : {}
	const exitCode = Number.isInteger(run.exitCode) ? run.exitCode : null
	const error = typeof run.error === 'string' ? run.error : ''

	if (run.timedOut === true) {
		return { kind: 'timedOut', exitCode, error }
	}

	if (exitCode !== null) {
		return { kind: 'exit', exitCode, error }
	}

	if (error !== '') {
		return { kind: 'error', exitCode, error }
	}

	return { kind: 'unknown', exitCode, error }
}

/**
 * The recorded output streams worth showing: each non-empty stream, with
 * whether it was cut to the 4 KB cap. None when the output was withheld.
 *
 * @param {object|null|undefined} codeRun One entry of the run row's `codeRuns`.
 * @return {Array<{stream: string, text: string, truncated: boolean}>} The streams in stdout, stderr order.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */
export function codeRunOutputs(codeRun) {
	const run = codeRun && typeof codeRun === 'object' ? codeRun : {}
	if (run.outputWithheld === true) {
		return []
	}

	return ['stdout', 'stderr']
		.filter((stream) => typeof run[stream] === 'string' && run[stream] !== '')
		.map((stream) => ({ stream, text: run[stream], truncated: run[stream + 'Truncated'] === true }))
}
