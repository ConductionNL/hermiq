<?php

/**
 * Hermiq FlowRunInput
 *
 * The flow payload an agent run starts from, and its organisation.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AgentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AgentRun;

/**
 * The flow payload an agent run starts from, and its organisation.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class FlowRunInput {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $payload The event payload.
	 * @param string $organisation The triggering object's organisation —
	 *                             threaded to `runAgentAsOwner()` so its
	 *                             defense-in-depth output-filter re-check
	 *                             resolves the correct GuardrailPolicy
	 *                             (agent-guardrails).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly string $organisation = '',
	) {
	}//end __construct()
}//end class
