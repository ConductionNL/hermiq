<?php

/**
 * Hermiq DomainToolDescriptors.
 *
 * The five tools over Hermiq's own domain objects (hermiq-domain-mcp-tools):
 * agents, their run status, pending approvals, running an agent now and the
 * skill catalogue. Each delegates to the read or trigger path its page already
 * uses, under the same guard, so the in-app companion can answer questions
 * about the surface the user is looking at.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptors of the domain tools.
 *
 * @spec openspec/changes/hermiq-domain-mcp-tools/specs/hermiq-domain-mcp-tools/spec.md
 */
final class DomainToolDescriptors {

	public const LIST_AGENTS = AppIdentity::APP_ID . '.listAgents';

	public const AGENT_STATUS = AppIdentity::APP_ID . '.getAgentStatus';

	public const PENDING_APPROVALS = AppIdentity::APP_ID . '.listPendingApprovals';

	public const RUN_AGENT_NOW = AppIdentity::APP_ID . '.runAgentNow';

	public const SEARCH_SKILLS = AppIdentity::APP_ID . '.searchSkills';

	public const IDS = [self::LIST_AGENTS, self::AGENT_STATUS, self::PENDING_APPROVALS, self::RUN_AGENT_NOW, self::SEARCH_SKILLS];

	public const ALL = [
		[
			'id' => self::LIST_AGENTS,
			'subject' => 'agent',
			'action' => 'list',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'List agents',
			'description' => 'List the agents the acting user may see, as on the agent catalogue page.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'limit' => ['type' => 'integer', 'description' => 'How many agents at most (default 20, at most 50).'],
					'offset' => ['type' => 'integer', 'description' => 'How many to skip (default 0).'],
				],
				'required' => [],
			],
			...ReadOnlyToolHints::HINTS,
		],
		[
			'id' => self::AGENT_STATUS,
			'subject' => 'agent',
			'action' => 'get',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Get agent status',
			'description' => 'Show one agent the acting user may see, with the last run and the next scheduled run of each of '
				. 'the acting user\'s own schedules for it.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => ['agentId' => ['type' => 'string', 'description' => 'The agent uuid.']],
				'required' => ['agentId'],
			],
			...ReadOnlyToolHints::HINTS,
		],
		[
			'id' => self::PENDING_APPROVALS,
			'subject' => 'approval',
			'action' => 'list',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'List pending approvals',
			'description' => 'List the pending approvals the acting user is the reviewer of, as in the approval inbox.',
			'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
			...ReadOnlyToolHints::HINTS,
		],
		[
			'id' => self::RUN_AGENT_NOW,
			'subject' => 'schedule',
			'action' => 'run',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Run agent now',
			'description' => 'Run one of the acting user\'s own schedules now, as the Run now button does, and report the run status.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => ['scheduleId' => ['type' => 'string', 'description' => 'The schedule uuid.']],
				'required' => ['scheduleId'],
			],
			// A run does whatever the agent's own tools do, so it is never read-only.
			'readOnlyHint' => false,
			'destructiveHint' => true,
			'idempotentHint' => false,
			'scope' => 'create',
		],
		[
			'id' => self::SEARCH_SKILLS,
			'subject' => 'skill',
			'action' => 'search',
			'reach' => ToolReachResolver::REACH_USER,
			'name' => 'Search skills',
			'description' => 'Search the skill catalogue of the acting user\'s organisation by name or description.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => ['query' => ['type' => 'string', 'description' => 'Words to look for; empty lists every skill.']],
				'required' => [],
			],
			...ReadOnlyToolHints::HINTS,
		],
	];
}//end class
