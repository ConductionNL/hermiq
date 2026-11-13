<?php

/**
 * Hermiq ScheduleRecords
 *
 * The records a scheduled run reads and writes: agents, conversations, the audit trail and versions.
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

use OCA\Hermiq\Service\AgentVersionService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;

/**
 * The records a scheduled run reads and writes: agents, conversations, the audit trail and versions.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ScheduleRecords {

	/**
	 * Constructor.
	 *
	 * @param AgentMapper $agentMapper Resolves an agent UUID to an Agent entity.
	 * @param ConversationMapper $conversationMapper Creates the conversation row the agent runs against.
	 * @param AuditTrailMapper $auditTrailMapper OR audit write-path for the explicit per-run entry (run-audit-log).
	 * @param AgentVersionService $agentVersionService Resolves the executing agent's current version
	 *                                                 identifier, pinned onto the run-audit context
	 *                                                 (agent-versioning).
	 * @param SkillVersionService $skillVersionService Resolves the exposed skills' version
	 *                                                 identifiers, pinned onto the run-audit
	 *                                                 context (skill-self-improvement) — never
	 *                                                 fatal to the run.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly AgentMapper $agentMapper,
		public readonly ConversationMapper $conversationMapper,
		public readonly AuditTrailMapper $auditTrailMapper,
		public readonly AgentVersionService $agentVersionService,
		public readonly SkillVersionService $skillVersionService,
	) {
	}//end __construct()
}//end class
