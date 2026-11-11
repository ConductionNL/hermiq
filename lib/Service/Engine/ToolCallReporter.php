<?php

/**
 * Hermiq: what one dispatched tool call reports while it runs.
 *
 * The `tool_call` frame and the start of a `tool` trace step before the call,
 * the end of that step and the `tool_result` frame after it. Built by
 * FacadeToolInvoker for each dispatch.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * Reports one tool call to the streaming channel and the run trace.
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */
final class ToolCallReporter {

	/**
	 * Constructor.
	 *
	 * @param StreamYieldChannel|null $channel The streaming channel, or null.
	 * @param RunTraceCollector|null  $trace   The run trace, or null.
	 */
	public function __construct(
		private readonly ?StreamYieldChannel $channel,
		private readonly ?RunTraceCollector $trace,
	) {
	}//end __construct()

	/**
	 * Emit the call frame and start the call's trace step.
	 *
	 * @param ToolDispatch $dispatch The call.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function started(ToolDispatch $dispatch): void {
		$this->channel?->emitToolCall(
			payload: [
				'toolId' => $dispatch->name,
				'arguments' => $dispatch->arguments,
			]
		);

		$dispatch->traceToken = $this->trace?->startStep(type: 'tool', name: $dispatch->name);
	}//end started()

	/**
	 * End the call's trace step with its outcome and extra, then emit the result frame.
	 *
	 * @param ToolDispatch         $dispatch   The answered call.
	 * @param array<string, mixed> $traceExtra The step's extra (artefact, research target).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function finished(ToolDispatch $dispatch, array $traceExtra): void {
		if ($this->trace !== null && $dispatch->traceToken !== null) {
			$this->trace->endStep(token: $dispatch->traceToken, outcome: $dispatch->outcome(), extra: $traceExtra);
		}

		$this->channel?->emitToolResult(
			payload: [
				'toolId' => $dispatch->name,
				'result' => $dispatch->envelope['result'],
				'isError' => $dispatch->envelope['isError'],
			]
		);
	}//end finished()
}//end class
