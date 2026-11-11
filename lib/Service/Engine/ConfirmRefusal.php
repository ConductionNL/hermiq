<?php

/**
 * Hermiq ConfirmRefusal.
 *
 * Why a confirm-classified tool call is refused: its status, the message for the model, the trace outcome and the approval it waits on.
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

/**
 * One confirm refusal's status and message.
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */
final class ConfirmRefusal {

	/**
	 * The trace step's outcome.
	 *
	 * @var string
	 */
	public string $outcome = 'awaiting_approval';

	/**
	 * The pending Approval's UUID, when one exists.
	 *
	 * @var string|null
	 */
	public ?string $approvalId = null;

	/**
	 * Build the refusal reason.
	 *
	 * @param string $status  The envelope's `status` field.
	 * @param string $message A human-readable refusal message for the LLM.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
