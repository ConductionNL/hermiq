<?php

/**
 * Hermiq ToolRefusal.
 *
 * One refused tool call: the call, the envelope the model gets back, and how the run trace records it.
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
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * One refusal of a tool call.
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */
final class ToolRefusal {

	/**
	 * The trace step's outcome.
	 *
	 * @var string
	 */
	public string $outcome = 'refused';

	/**
	 * Extra fields of the trace step.
	 *
	 * @var array<string, mixed>
	 */
	public array $traceExtra = [];

	/**
	 * The trace step's name, when it is not the dotted tool id.
	 *
	 * @var string|null
	 */
	public ?string $traceName = null;

	/**
	 * Build the refusal.
	 *
	 * @param ToolCall             $call     The refused call.
	 * @param array<string, mixed> $envelope The refusal the model gets back.
	 */
	public function __construct(
		public readonly ToolCall $call,
		public readonly array $envelope,
	) {
	}//end __construct()
}//end class
