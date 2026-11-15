<?php

/**
 * Contract of ContextAgentInteractionService
 *
 * The public surface of ContextAgentInteractionService that other components call, so they depend
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

use OCP\TaskProcessing\Exception\ProcessingException;

/**
 * Contract of ContextAgentInteractionService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContextAgentInteractionServiceInterface {

	/**
	 * Run one ContextAgent interaction turn.
	 *
	 * @param string|null $userId The task creator (owns the conversation; required).
	 * @param string $input The chat message.
	 * @param int|null $confirmation The client's confirmation of previously-requested
	 *                               actions: 1 to confirm, 0 to deny, null when not
	 *                               answering a prior request.
	 * @param string $conversationToken The conversation token (a Hermiq Conversation UUID);
	 *                                  empty to start a new conversation.
	 *
	 * @return array{output: string, conversation_token: string, actions: string} The
	 *                                                                            ContextAgent output shape (reply, the new token, and the agent's action
	 *                                                                            allowlist as JSON).
	 *
	 * @throws ProcessingException When there is no user context, no available agent, the
	 *                             org kill-switch is engaged, or the turn fails.
	 *
	 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
	 */
	public function interact(?string $userId, string $input, ?int $confirmation, string $conversationToken): array;
}//end interface
