<?php

/**
 * Hermiq DelegatedTurn
 *
 * The task one agent delegates to another, and the frame it runs in.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Delegation;

use OCA\Hermiq\Service\Engine\DelegationFrame;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * The task one agent delegates to another, and the frame it runs in.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class DelegatedTurn {

	/**
	 * Constructor.
	 *
	 * @param string $task The bounded task/prompt (LLM-supplied).
	 * @param string $organisation The caller's organisation.
	 * @param DelegationFrame|null $callerFrame The calling agent's own current frame
	 *                                          (its `runId` becomes this sub-run's
	 *                                          `parentRunId`; its `anchor` is reused
	 *                                          verbatim).
	 * @param string $effectiveReach The composed reach of this delegation
	 *                               (`effectiveReachFor()`), carried onto the
	 *                               result so the calling turn can account for
	 *                               it. Defaults to the delegation tool's own
	 *                               `instance` — never lower.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $task,
		public readonly string $organisation,
		public readonly ?DelegationFrame $callerFrame,
		public readonly string $effectiveReach = ToolReachResolver::REACH_INSTANCE,
	) {
	}//end __construct()
}//end class
