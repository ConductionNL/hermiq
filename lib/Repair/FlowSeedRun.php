<?php

/**
 * Hermiq triage flow seed run.
 *
 * The flow store and repair output one SeedHydraTriageFlow run works with. Built by
 * SeedHydraTriageFlow and never handed out of it.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\FlowMapper;
use OCP\Migration\IOutput;

/**
 * One triage flow seed run.
 *
 * @spec openspec/changes/hydra-console-agent-leaves/tasks.md#task-7-seed-the-triage-agentflow
 */
final class FlowSeedRun {

	/**
	 * The run.
	 *
	 * @param FlowMapper $mapper The flow store.
	 * @param IOutput $output Repair output channel.
	 */
	public function __construct(
		public readonly FlowMapper $mapper,
		public readonly IOutput $output,
	) {
	}//end __construct()
}//end class
