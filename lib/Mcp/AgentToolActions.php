<?php

/**
 * Hermiq AgentToolActions
 *
 * The services the agent tools act through: delegation, writes, mail and access requests.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
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

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\Contracts\DelegationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\MailReadServiceInterface;
use OCA\Hermiq\Kernel\Contracts\NcNativeWriteServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ToolAccessRequestServiceInterface;

/**
 * The services the agent tools act through: delegation, writes, mail and access requests.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AgentToolActions {

	/**
	 * Constructor.
	 *
	 * @param DelegationServiceInterface $delegationService Shared governed dispatcher backing
	 *                                                      `delegateAgent` (sub-agent-delegation) —
	 *                                                      all self/cycle/allowlist/depth/fan-out/
	 *                                                      organisation/model-policy/kill-switch/
	 *                                                      budget/approval gating lives there.
	 * @param NcNativeWriteServiceInterface $writeService Facade over the Calendar/Contacts/Notes
	 *                                                    write services (nc-native-write-tools) —
	 *                                                    ownership guards, ADR-088 marking and the
	 *                                                    lazy Notes resolution all live there.
	 * @param MailReadServiceInterface $mailReadService Read-only mail (nc-mail-read-tools) —
	 *                                                  AI-feature gate and lazy Mail resolution live there.
	 * @param ToolAccessRequestServiceInterface $accessRequests Discovery past the grant and the
	 *                                                          request that can widen it
	 *                                                          (tool-discovery-and-access-requests) —
	 *                                                          backs `listAvailableTools` and
	 *                                                          `requestToolAccess`. Raising a request
	 *                                                          grants nothing; only the agent's owner
	 *                                                          can write `Agent.tools`.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly DelegationServiceInterface $delegationService,
		public readonly NcNativeWriteServiceInterface $writeService,
		public readonly MailReadServiceInterface $mailReadService,
		public readonly ToolAccessRequestServiceInterface $accessRequests,
	) {
	}//end __construct()
}//end class
