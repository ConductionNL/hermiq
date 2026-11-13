<?php

/**
 * Hermiq AgentRuntimes
 *
 * What runs an agent turn and delivers its result.
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

use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCP\BackgroundJob\IJobList;

/**
 * What runs an agent turn and delivers its result.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AgentRuntimes {

	/**
	 * Constructor.
	 *
	 * @param ChatService $chatService OpenRegister agent runtime (processMessage).
	 * @param DeliveryService $deliveryService Real Talk/notification delivery (talk-delivery).
	 * @param Engine $engine In-app agent engine facade, used only when the flag is on (agent-engine-port).
	 * @param DelegationContext $delegationContext Request-scoped delegation call-stack
	 *                                             (sub-agent-delegation): pushed/popped around
	 *                                             every Engine-path turn so a mid-turn
	 *                                             `hermiq.delegateAgent` call sees this run's
	 *                                             true depth/ancestry/fan-out/anchor.
	 * @param IJobList $jobList Enqueues the post-run learnings capture
	 *                          QueuedJob AFTER the run's audit entry is
	 *                          written (skill-learnings) — try/catch
	 *                          wrapped; capture never sits on the run's
	 *                          critical path.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ChatService $chatService,
		public readonly DeliveryService $deliveryService,
		public readonly Engine $engine,
		public readonly DelegationContext $delegationContext,
		public readonly IJobList $jobList,
	) {
	}//end __construct()
}//end class
