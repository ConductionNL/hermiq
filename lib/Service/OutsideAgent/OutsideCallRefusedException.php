<?php

/**
 * Hermiq OutsideCallRefusedException.
 *
 * A call from an agent outside the instance that one of the two gates closed on.
 * Which gate it was is on the exception, because "refused" without a gate name
 * sends an integrator to the wrong screen: the grant is an administrator's to
 * change here, and the right is the owning app's to change there.
 *
 * @category Exception
 * @package  OCA\Hermiq\Service\OutsideAgent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\OutsideAgent;

use RuntimeException;

/**
 * A refused call from an outside agent, naming the gate that refused it.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-both-gates-must-open-before-a-tool-runs
 */
class OutsideCallRefusedException extends RuntimeException {

	/**
	 * The registration does not list the tool, or lists nothing and the tool
	 * writes. An administrator of this instance changes that.
	 *
	 * @var string
	 */
	public const GATE_GRANT = 'registration-grant';

	/**
	 * There is no registration for this principal, or it is switched off.
	 *
	 * @var string
	 */
	public const GATE_REGISTRATION = 'registration';

	/**
	 * The tool is not offered at all: no owning app declared it reachable from
	 * outside.
	 *
	 * @var string
	 */
	public const GATE_SURFACE = 'tool-surface';

	/**
	 * The owning app refused the act for this principal. Nothing here can grant
	 * it, which is the point.
	 *
	 * @var string
	 */
	public const GATE_OWNING_APP = 'owning-app';

	/**
	 * Constructor.
	 *
	 * @param string $gate Which gate refused, one of the GATE_* constants.
	 * @param string $toolId The tool that was called.
	 * @param string $principal The person the outside agent authenticated as.
	 * @param string $reason The refusal in words.
	 */
	public function __construct(
		public readonly string $gate,
		public readonly string $toolId,
		public readonly string $principal,
		string $reason,
	) {
		parent::__construct(
			sprintf(
				"Refused by the %s gate: '%s' for principal '%s': %s.",
				$gate,
				$toolId,
				$principal,
				$reason
			),
			403
		);

	}//end __construct()

	/**
	 * Which gate refused this call.
	 *
	 * @return string The gate name.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-permitted-caller-without-the-grant-is-refused
	 */
	public function gate(): string {
		return $this->gate;
	}//end gate()
}//end class
