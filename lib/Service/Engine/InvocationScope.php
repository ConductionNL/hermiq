<?php

/**
 * Hermiq InvocationScope
 *
 * Whose run a tool invocation belongs to, and the limits it runs under.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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

namespace OCA\Hermiq\Service\Engine;

use Closure;

/**
 * Whose run a tool invocation belongs to, and the limits it runs under.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class InvocationScope {

	/**
	 * Constructor.
	 *
	 * @param string|null $agentId The acting agent's UUID; null disables
	 *                             the approval gate (no reviewer/owner
	 *                             to route to).
	 * @param bool $dryRun Whether this turn is a
	 *                     dry-run preview
	 *                     (run-replay-and-dry-run):
	 *                     when true, a
	 *                     side-effecting tool is
	 *                     neutralised at
	 *                     `dispatchToFacade()`
	 *                     instead of actually
	 *                     invoked. False (every
	 *                     pre-existing caller)
	 *                     is byte-for-byte
	 *                     unchanged behavior.
	 * @param string|null $ownerUid The owning Nextcloud UID this run acts as
	 *                              (hydra-console-agent-leaves). Required
	 *                              before a flow-queueing tool may be
	 *                              dispatched; null or empty REFUSES that
	 *                              tool rather than defaulting the owner.
	 *                              Every other tool is unaffected, so
	 *                              existing callers that omit it are
	 *                              unchanged.
	 * @param int|null $maxToolCalls Tool calls this turn may make (agents-switch-off-and-stop);
	 *                               null, every pre-existing caller, is no cap.
	 * @param Closure|null $agentStillOn Fresh read of the agent's switch before each call;
	 *                                   null is always on.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?string $agentId = null,
		public readonly bool $dryRun = false,
		public readonly ?string $ownerUid = null,
		public readonly ?int $maxToolCalls = null,
		public readonly ?Closure $agentStillOn = null,
	) {
	}//end __construct()
}//end class
