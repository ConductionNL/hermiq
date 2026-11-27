<?php

/**
 * Contract of TalkTurnService
 *
 * The public surface of TalkTurnService that other components call, so they depend
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

use OCA\Hermiq\Service\Talk\TalkTurn;

/**
 * Contract of TalkTurnService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TalkTurnServiceInterface {

	/**
	 * Run one turn and post the answer into the room.
	 *
	 * Never throws: a Talk turn is a best-effort side channel, and a failure
	 * here must not surface as an unhandled background-job error. Failures are
	 * reported INTO THE ROOM rather than only logged — a user who sees the
	 * acknowledging reaction and then nothing cannot tell "still working" from
	 * "died".
	 *
	 * @param TalkTurn $turn The turn: conversation, speaker, message, room, and the
	 *                       optional agent ('' resolves it from the room's session),
	 *                       addressing message ('' when unknown) and chain.
	 *
	 * @return bool True when an answer was posted.
	 *
	 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-replies-stream-progressively-where-talk-supports-message-editing
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function runTurn(TalkTurn $turn): bool;
}//end interface
