<?php

/**
 * Hermiq EngineRunRequest
 *
 * One agent turn the scheduler hands to the Engine.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
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

namespace OCA\Hermiq\Service\Schedule;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One agent turn the scheduler hands to the Engine.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class EngineRunRequest {

	/**
	 * Constructor.
	 *
	 * @param string $owner The identity to run as (schedule owner, or the
	 *                      resolved `actingUser` — see
	 *                      resolveActingUser()).
	 * @param string $agentId The bound agent UUID (hermiq register `agent` object).
	 * @param string $prompt The prompt to run.
	 * @param bool $dryRun Whether this turn is a dry-run preview
	 *                     (run-replay-and-dry-run): threaded
	 *                     onto `Engine::processMessage()` so a
	 *                     side-effecting tool call is
	 *                     neutralised, and the scratch
	 *                     `Conversation`/`Message` objects
	 *                     created below are deleted once the
	 *                     turn completes (success or failure)
	 *                     — a preview leaves no persisted
	 *                     transcript behind. False (every
	 *                     pre-existing caller) is byte-for-byte
	 *                     unchanged behavior.
	 * @param ObjectEntity|null $anchor Sub-agent-delegation: the budget-rollup anchor
	 *                                  object passed straight through from
	 *                                  `runAgentAsOwner()` — pushed verbatim onto
	 *                                  the `DelegationContext` frame below, never
	 *                                  re-derived here.
	 * @param array|null $skillSetOverride Per-run effective-skill-set override
	 *                                     (skill uuids, skill-evals) passed straight
	 *                                     through to `Engine::processMessage()`;
	 *                                     null (every non-eval caller) exposes the
	 *                                     agent's stored installs.
	 * @param array<string, mixed> $startValues The schedule's start field values,
	 *                                          already through the input filter.
	 * @param string|null $continueSessionUuid A goal's session to continue (agents-standing-goal).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Dry-run preview (run-replay-and-dry-run) is a
	 *   cross-cutting mode carried as a flag by design.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $agentId,
		public readonly string $prompt,
		public readonly bool $dryRun = false,
		public readonly ?ObjectEntity $anchor = null,
		public readonly ?array $skillSetOverride = null,
		public readonly array $startValues = [],
		public readonly ?string $continueSessionUuid = null,
	) {
	}//end __construct()
}//end class
