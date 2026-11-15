<?php

/**
 * Contract of AgentAvailabilityService
 *
 * The public surface of AgentAvailabilityService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of AgentAvailabilityService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentAvailabilityServiceInterface {

	/**
	 * Whether a user may switch the agent: its owner, an instance admin, or the
	 * owner of the agent's organisation.
	 *
	 * @param ObjectEntity $agent The agent.
	 * @param string $uid The user.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function mayModify(ObjectEntity $agent, string $uid): bool;

	/**
	 * The agent when the user may read it, else null.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The user.
	 *
	 * @return ObjectEntity|null The agent.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function readableAgent(string $agentId, string $uid): ?ObjectEntity;

	/**
	 * How many schedules the agent has, which the delete confirmation names.
	 *
	 * @param string $agentId The agent.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
	 */
	public function scheduleCount(string $agentId): int;

	/**
	 * Switch an agent off or on.
	 *
	 * @param string $agentId The agent.
	 * @param bool $active On (true) or off (false).
	 * @param string $reason Why; required when switching off.
	 * @param string $actorUid Who switches.
	 *
	 * @return ObjectEntity The stored agent.
	 *
	 * @throws RuntimeException 404 unknown agent, 403 not allowed, 400 no reason.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The switch IS a boolean.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function switchAgent(string $agentId, bool $active, string $reason, string $actorUid): ObjectEntity;
}//end interface
