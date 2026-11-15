<?php

/**
 * Contract of AgentAccessService
 *
 * The public surface of AgentAccessService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of AgentAccessService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentAccessServiceInterface {

	/**
	 * Whether the user may READ an agent: non-private agents are open to the
	 * organisation (multitenancy already scoped the read), private agents only
	 * to their owner, an explicitly invited user, or a member of one of the
	 * groups in the agent's `groups` (hermiq#951).
	 *
	 * @param ObjectEntity $agent Agent object.
	 * @param string $userId Nextcloud user id.
	 *
	 * @return bool True when the user may access the agent.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-group-sharing-is-enforced-wherever-an-agent-is-read-or-run-req-agshare-002
	 */
	public function canUserAccessAgent(ObjectEntity $agent, string $userId): bool;

	/**
	 * Whether the user may MODIFY an agent or the per-agent state hanging off it
	 * (memory, profiles, skill installs): owner-only, mirroring OR's
	 * `AgentMapper::canUserModifyAgent()`.
	 *
	 * Read access is deliberately NOT enough here. An agent's memory entries and
	 * its installed skills are folded into the system-prompt preamble of every
	 * subsequent run (`Engine::assembleSkillsForRun()`), so a write to them is a
	 * durable instruction to somebody else's agent, not a note on a shared board.
	 *
	 * @param ObjectEntity $agent Agent object.
	 * @param string $userId Nextcloud user id.
	 *
	 * @return bool True when the user may modify the agent.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function canUserModifyAgent(ObjectEntity $agent, string $userId): bool;

	/**
	 * Load an agent only when it exists AND the requesting user may READ it —
	 * null either way, so a non-owner cannot confirm a private agent exists.
	 *
	 * @param string $agentId The Agent UUID.
	 * @param string $userId The requesting user's UID.
	 *
	 * @return ObjectEntity|null The accessible agent, or null.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 */
	public function loadAccessibleAgent(string $agentId, string $userId): ?ObjectEntity;

	/**
	 * Load an agent only when it exists AND the requesting user may MODIFY it
	 * (owner-only) — null either way, same non-disclosure rule as
	 * `loadAccessibleAgent()`.
	 *
	 * @param string $agentId The Agent UUID.
	 * @param string $userId The requesting user's UID.
	 *
	 * @return ObjectEntity|null The modifiable agent, or null.
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function loadModifiableAgent(string $agentId, string $userId): ?ObjectEntity;
}//end interface
