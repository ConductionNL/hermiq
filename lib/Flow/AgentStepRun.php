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
 * The step configuration and acting identity of one agent step run.
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
final class AgentStepRun {

	/**
	 * Constructor.
	 *
	 * @param array  $config The step configuration, its agent already checked.
	 * @param string $owner  The identity the turns act as.
	 */
	public function __construct(
		public readonly array $config,
		public readonly string $owner,
	) {
	}//end __construct()

	/**
	 * The agent every item is answered by.
	 *
	 * @return string The trimmed agent id.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function agentId(): string {
		return trim((string)($this->config['agentId'] ?? ($this->config['agent'] ?? '')));
	}//end agentId()

	/**
	 * The organisation the turns run under ('' when the step names none).
	 *
	 * @return string The organisation.
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function organisation(): string {
		return (string)($this->config['organisation'] ?? '');
	}//end organisation()

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
