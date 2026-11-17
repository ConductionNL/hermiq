<?php

/**
 * Contract of InstructionVariablesService
 *
 * The public surface of InstructionVariablesService that other components call, so they depend
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

use OCA\Hermiq\Service\Agent\StartValuesRejectedException;
use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of InstructionVariablesService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InstructionVariablesServiceInterface {

	/**
	 * Store the person's answers on their session, once.
	 *
	 * @param string $sessionId The session.
	 * @param string $uid The caller.
	 * @param array<string, mixed> $values The answers per field key.
	 *
	 * @return ObjectEntity The saved session.
	 *
	 * @throws RuntimeException 404 when it is not the caller's session, 409 when it already has answers.
	 * @throws StartValuesRejectedException 422 when an answer is missing, of the wrong kind or blocked.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function answer(string $sessionId, string $uid, array $values): ObjectEntity;

	/**
	 * The instructions filled in for the owner, with sample answers.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string|null $prompt The form's unsaved instructions, or null for the stored ones.
	 * @param array<int, array<string, mixed>>|null $startFields The form's unsaved fields, or null for the stored ones.
	 * @param array<string, mixed> $sampleValues Sample answers per field key.
	 *
	 * @return array{text: string, unknown: array<int, string>}
	 *
	 * @throws RuntimeException 404 when the caller does not own the agent.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function preview(string $agentId, string $uid, ?string $prompt, ?array $startFields, array $sampleValues): array;
}//end interface
