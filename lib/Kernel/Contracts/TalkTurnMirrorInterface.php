<?php

/**
 * Contract of TalkTurnMirror
 *
 * The public surface of TalkTurnMirror that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of TalkTurnMirror.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkTurnMirrorInterface {

	/**
	 * Queue the mirror of a Chat-page turn into the session's Talk room.
	 *
	 * @param array<string, mixed> $session   The session object.
	 * @param string               $sessionId The session's uuid.
	 * @param string               $userId    Who typed the question.
	 * @param string               $question  The question.
	 * @param string               $answer    The agent's answer.
	 *
	 * @return bool True when a mirror was queued.
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
	 */
	public function enqueue(array $session, string $sessionId, string $userId, string $question, string $answer): bool;

	/**
	 * Post the question and the answer into the room as the agent's bot.
	 *
	 * @param array<string, string> $turn The queued turn: sessionId, roomToken, agentId, userId, question, answer.
	 *
	 * @return bool True when both messages were posted.
	 *
	 * @spec openspec/changes/chat-carry-a-conversation-across-channels/tasks.md#task-3-mirror-a-chat-page-turn-into-the-room
	 */
	public function mirror(array $turn): bool;
}//end interface
