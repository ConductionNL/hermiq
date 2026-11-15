<?php

/**
 * Contract of GoalService
 *
 * The public surface of GoalService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of GoalService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GoalServiceInterface {

	/**
	 * The session's newest goal, for its owner, or null.
	 *
	 * @param string $sessionId The session.
	 * @param string $uid The person.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws RuntimeException 404 for a session that is not the person's.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	public function forSession(string $sessionId, string $uid): ?array;

	/**
	 * Take the next turn of every due goal (called by the dispatcher's background job).
	 *
	 * @return int The goals handled.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-goal-turns-continue-the-same-session-through-the-scheduled-run-gates-req-aggoal-002
	 */
	public function run(): int;

	/**
	 * Set a goal on the person's own session; one active goal per session.
	 *
	 * @param string $sessionId The session.
	 * @param string $uid The person.
	 * @param array<string, mixed> $input statement, check, intervalMinutes, maxTurns.
	 *
	 * @return array<string, mixed> The goal.
	 *
	 * @throws RuntimeException 404 for a session that is not the person's, 409 when one is active, 422 for a bad goal.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-person-can-give-an-agent-a-standing-goal-with-a-check-req-aggoal-001
	 */
	public function set(string $sessionId, string $uid, array $input): array;

	/**
	 * Stop a goal: only the person who set it and the agent's owner may; anyone else gets 404.
	 *
	 * @param string $goalId The goal.
	 * @param string $uid The person.
	 *
	 * @return array<string, mixed> The stopped goal.
	 *
	 * @throws RuntimeException 404 for anyone else or an unknown goal.
	 *
	 * @spec openspec/changes/agents-standing-goal/specs/agent-schedule/spec.md#requirement-a-goal-can-be-stopped-by-its-owner-or-the-agent-owner-req-aggoal-003
	 */
	public function stop(string $goalId, string $uid): array;
}//end interface
