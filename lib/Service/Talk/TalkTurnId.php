<?php

/**
 * Hermiq TalkTurnId.
 *
 * The deterministic identity of one Talk turn: the room, the Talk message that
 * addressed the agent, and the agent it addressed. Every executor claims this
 * id before it runs the turn, so the id is also the dedup key that keeps a
 * redelivered event, a double enqueue or a re-run job from answering twice.
 *
 * The agent is part of the id because one message can address two agents: each
 * of them takes its own turn, and each turn needs its own claim.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/instant-talk/tasks.md#1-claims-and-dedup
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

/**
 * Derives turn ids.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
final class TalkTurnId {

	/**
	 * The turn id for one message addressed to one agent.
	 *
	 * Hashed so the id is a fixed-length key any store accepts, whatever a room
	 * token or message id looks like. Empty when the message id is unknown: a
	 * turn without one cannot be deduplicated, and an empty id tells the
	 * executors to run it unclaimed, as every turn ran before claims existed.
	 *
	 * @param string $roomToken The Talk room token.
	 * @param string $messageId The Talk message id that addressed the agent.
	 * @param string $agentId   The addressed agent's uuid.
	 *
	 * @return string The turn id, or '' when the message id is unknown.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public static function derive(string $roomToken, string $messageId, string $agentId): string {
		if ($roomToken === '' || $messageId === '') {
			return '';
		}

		return hash('sha256', $roomToken . "\n" . $messageId . "\n" . $agentId);
	}//end derive()
}//end class
