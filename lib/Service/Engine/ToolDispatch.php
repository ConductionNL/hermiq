<?php

/**
 * Hermiq: one tool call through FacadeToolInvoker's dispatch chokepoint.
 *
 * The tool and its arguments, the trace step it was started under, and the
 * facade's `{result, isError}` envelope once it answered. Built and read only
 * inside FacadeToolInvoker.
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
 * One dispatched tool call.
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */
final class ToolDispatch {

	/**
	 * The outcome a successful call is traced with instead of `ok`, or null.
	 *
	 * @var string|null
	 */
	public ?string $outcomeOverride = null;

	/**
	 * The trace step's token, or null without a trace.
	 *
	 * @var int|null
	 */
	public ?int $traceToken = null;

	/**
	 * The facade's `{result, isError}` envelope.
	 *
	 * @var array<string, mixed>
	 */
	public array $envelope = [];

	/**
	 * Constructor.
	 *
	 * @param string               $name      The tool name.
	 * @param array<string, mixed> $arguments The call's arguments.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $arguments,
	) {
	}//end __construct()

	/**
	 * The trace outcome: `error` for an error result, otherwise the override or `ok`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agent-guardrails/tasks.md#task-7-confirm-tool-retry-and-consume-flow-in-facadetoolinvoker
	 */
	public function outcome(): string {
		if ($this->envelope['isError'] === true) {
			return 'error';
		}

		return ($this->outcomeOverride ?? 'ok');
	}//end outcome()

	/**
	 * The JSON of the tool result for the follow-up LLM turn, or a fixed error
	 * when it cannot be encoded.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function encodedResult(): string {
		$encoded = json_encode($this->envelope['result']);
		if (is_string($encoded) === false) {
			return '{"error":"Tool result could not be encoded"}';
		}

		return $encoded;
	}//end encodedResult()
}//end class
