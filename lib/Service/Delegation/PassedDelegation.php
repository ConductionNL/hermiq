<?php

/**
 * Hermiq PassedDelegation
 *
 * A delegation that passed every gate and has not run yet: the target agent
 * and the turn it will run.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Delegation
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
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Delegation;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A delegation that passed its gates, waiting to run.
 *
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
 */
final class PassedDelegation {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity  $targetAgent The agent the work goes to.
	 * @param DelegatedTurn $turn        The task and the frame it runs in.
	 *
	 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
	 */
	public function __construct(
		public readonly ObjectEntity $targetAgent,
		public readonly DelegatedTurn $turn,
	) {
	}//end __construct()
}//end class
