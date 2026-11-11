<?php

/**
 * One agent step's run: the agent, the identity and the step configuration.
 *
 * Built once per `execute()` by the agent step and handed to the helpers that
 * answer each item, so they share one resolved agent and one acting identity.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

/**
 * The resolved agent, acting identity and organisation of one agent step run.
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
final class AgentStepRun {

	/**
	 * Constructor.
	 *
	 * @param array  $config       The step configuration.
	 * @param string $agentId      The agent every item is answered by.
	 * @param string $owner        The identity the turns act as.
	 * @param string $organisation The organisation the turns run under.
	 */
	public function __construct(
		public readonly array $config,
		public readonly string $agentId,
		public readonly string $owner,
		public readonly string $organisation,
	) {
	}//end __construct()

	/**
	 * The item key the answer is stored under (default `result`).
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function outputKey(): string {
		return (string)($this->config['output'] ?? 'result');
	}//end outputKey()
}//end class
