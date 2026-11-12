<?php

/**
 * Hermiq OutsideCall.
 *
 * The tool and principal of one call from outside, so that each gate-one
 * refusal is built from the call it refuses.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\OutsideAgent
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\OutsideAgent;

/**
 * One call from outside: which tool, for which principal.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */
final class OutsideCall {

	/**
	 * Build the call.
	 *
	 * @param string $toolId    The tool being called.
	 * @param string $principal The calling principal.
	 */
	public function __construct(
		public readonly string $toolId,
		public readonly string $principal,
	) {
	}//end __construct()

	/**
	 * The refusal of this call by one gate.
	 *
	 * @param string $gate   One of the OutsideCallRefusedException::GATE_* constants.
	 * @param string $reason The refusal in words.
	 *
	 * @return OutsideCallRefusedException
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
	 */
	public function refused(string $gate, string $reason): OutsideCallRefusedException {
		return new OutsideCallRefusedException(gate: $gate, toolId: $this->toolId, principal: $this->principal, reason: $reason);
	}//end refused()
}//end class
