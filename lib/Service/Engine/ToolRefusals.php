<?php

/**
 * Hermiq ToolRefusals.
 *
 * How a run refuses a tool call: a `tool` trace step with the refusal's outcome,
 * the call and result frames on the stream, and the envelope returned to the
 * model as JSON.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The refusals of one run, over its trace and stream; built by FacadeToolInvoker.
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */
final class ToolRefusals {

	/**
	 * Refuse over a run's trace and stream.
	 *
	 * @param RunTraceCollector|null  $trace   The run trace, or null.
	 * @param StreamYieldChannel|null $channel The stream, or null.
	 */
	public function __construct(
		private readonly ?RunTraceCollector $trace,
		private readonly ?StreamYieldChannel $channel,
	) {
	}//end __construct()

	/**
	 * Record a refused call as a `tool` trace step, show it on the stream, and
	 * return its envelope to the model.
	 *
	 * @param ToolRefusal $refusal The refusal.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	public function refuse(ToolRefusal $refusal): string {
		$call = $refusal->call;
		$traceToken = null;
		if ($this->trace !== null) {
			$traceToken = $this->trace->startStep(type: 'tool', name: ($refusal->traceName ?? $call->toolId));
		}

		if ($this->trace !== null && $traceToken !== null) {
			$this->trace->endStep(token: $traceToken, outcome: $refusal->outcome, extra: $refusal->traceExtra);
		}

		$this->channel?->emitToolCall(payload: ['toolId' => $call->toolId, 'arguments' => $call->arguments]);
		$this->channel?->emitToolResult(payload: ['toolId' => $call->toolId, 'result' => $refusal->envelope, 'isError' => true]);

		return $this->encoded(envelope: $refusal->envelope);
	}//end refuse()

	/**
	 * Refuse a `confirm`-classified tool call, recording a `tool` trace step
	 * with the given outcome (spec: "record every ... tool confirm-request ...
	 * as a trace step") and emitting the streaming channel's frames.
	 *
	 * @param ToolCall       $call    The refused call.
	 * @param ConfirmRefusal $confirm The status, message, trace outcome and pending approval.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	public function confirm(ToolCall $call, ConfirmRefusal $confirm): string {
		$envelope = [
			'isError' => true,
			'error' => 'approval_required',
			'toolId' => $call->toolId,
			'status' => $confirm->status,
			'message' => $confirm->message,
		];

		if ($confirm->approvalId !== null) {
			$envelope['approvalId'] = $confirm->approvalId;
		}

		$refusal = new ToolRefusal(call: $call, envelope: $envelope);
		$refusal->outcome = $confirm->outcome;

		return $this->refuse(refusal: $refusal);
	}//end confirm()

	/**
	 * Refuse a confirm-classified call when there is no agent context to route an approval to.
	 *
	 * @param ToolCall $call The refused call.
	 *
	 * @return string JSON-encoded refusal for the follow-up LLM turn.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	public function unavailable(ToolCall $call): string {
		$confirm = new ConfirmRefusal(
			status: 'unavailable',
			message: 'This action requires human approval, but no agent context is available to route it — refused.'
		);
		$confirm->outcome = 'denied';

		return $this->confirm(call: $call, confirm: $confirm);
	}//end unavailable()

	/**
	 * A pending refusal that names the approval it waits on.
	 *
	 * @param string       $message  A human-readable refusal message for the LLM.
	 * @param ObjectEntity $approval The pending approval.
	 *
	 * @return ConfirmRefusal The refusal reason.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	public function awaiting(string $message, ObjectEntity $approval): ConfirmRefusal {
		$confirm = new ConfirmRefusal(status: 'pending', message: $message);
		$confirm->approvalId = (string)$approval->getUuid();

		return $confirm;
	}//end awaiting()

	/**
	 * The approval gate's verdict fields: denied by the reviewer who decided, or
	 * pending on the approval the call raised.
	 *
	 * @param ObjectEntity|null $decided The decided (not approved) approval, or null.
	 * @param ObjectEntity|null $pending The approval the call raised when none was decided.
	 *
	 * @return array{status: string, approvalId: string, message: string} The verdict fields.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	public function gateVerdict(?ObjectEntity $decided, ?ObjectEntity $pending): array {
		if ($decided !== null) {
			return [
				'status' => 'denied',
				'approvalId' => (string)$decided->getUuid(),
				'message' => 'This action was denied by a reviewer and cannot be run.',
			];
		}

		return [
			'status' => 'pending',
			'approvalId' => (string)$pending?->getUuid(),
			'message' => 'This action requires human approval before it can run.',
		];
	}//end gateVerdict()

	/**
	 * A refusal envelope as JSON; when it cannot be encoded, just its `ok`, `isError` and `error`.
	 *
	 * @param array<string, mixed> $envelope The refusal.
	 *
	 * @return string The JSON.
	 *
	 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
	 */
	public function encoded(array $envelope): string {
		$encoded = json_encode($envelope);
		if (is_string($encoded) === false) {
			return (string)json_encode(array_intersect_key($envelope, array_flip(['ok', 'isError', 'error'])));
		}

		return $encoded;
	}//end encoded()
}//end class
