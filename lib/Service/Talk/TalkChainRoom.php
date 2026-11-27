<?php

/**
 * Hermiq TalkChainRoom
 *
 * The room side of an agent-to-agent chain: the target agent's session in the
 * room, found or opened for the chain's rooting human and kept in step with the
 * room's roster; the hand-off of the chained turn; and the notice that ends a
 * chain.
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
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Kernel\Contracts\TalkRoomBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnDispatcherInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Container\ContainerInterface;

/**
 * Sessions, hand-offs and notices in the room a chain runs in.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */
class TalkChainRoom {

	/**
	 * Constructor.
	 *
	 * @param TalkRoomBindingInterface  $roomBinding   Each agent's session in the room.
	 * @param ConversationParticipation $participation Owner-or-participant guard.
	 * @param TalkBridge                $bridge        Room roster and the chain-ended notice.
	 * @param ContainerInterface        $container     Resolves the dispatcher when a turn is handed off:
	 *                                                 the dispatcher's executor runs TalkTurnService,
	 *                                                 which holds the chain, so a constructor
	 *                                                 dependency would be a cycle.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		private readonly TalkRoomBindingInterface $roomBinding,
		private readonly ConversationParticipation $participation,
		private readonly TalkBridge $bridge,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The target agent's session in the room, for the chain's rooting human.
	 *
	 * The session is found or opened for the rooting human and its roster synced
	 * with the room; the rooting human must still be in the room and its owner or
	 * a participant. One who left fails closed: null.
	 *
	 * @param string $roomToken The room.
	 * @param string $agentId   The mentioned agent.
	 * @param string $rootUid   The human who rooted the chain.
	 *
	 * @return ObjectEntity|null The session the chained turn runs on, or null.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function sessionFor(string $roomToken, string $agentId, string $rootUid): ?ObjectEntity {
		$members = $this->bridge->roomUserIds(roomToken: $roomToken);

		$session = $this->roomBinding->findByRoomAndAgent(roomToken: $roomToken, agentId: $agentId);
		if ($session !== null) {
			$session = $this->roomBinding->syncParticipants(conversation: $session, participants: $members);
		}

		if ($session === null) {
			$session = $this->roomBinding->createBound(roomToken: $roomToken, agentId: $agentId, ownerUid: $rootUid, participants: $members);
		}

		if ($session === null
			|| in_array($rootUid, $members, true) === false
			|| $this->participation->mayTakeTurn(conversationData: (array)$session->getObject(), userId: $rootUid) === false
		) {
			return null;
		}

		return $session;
	}//end sessionFor()

	/**
	 * Hand a chained turn to the dispatcher.
	 *
	 * @param TalkTurn $turn The chained turn.
	 *
	 * @return string The path taken: `triggered`, `instant` or `queued`.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function dispatch(TalkTurn $turn): string {
		return $this->container->get(TalkTurnDispatcherInterface::class)->dispatch(turn: $turn);
	}//end dispatch()

	/**
	 * Post a brief notice into the room under an agent's bot.
	 *
	 * @param string $roomToken The room.
	 * @param string $notice    The notice.
	 * @param string $agentId   The agent whose bot posts it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function postNotice(string $roomToken, string $notice, string $agentId): void {
		$this->bridge->postToRoom(roomToken: $roomToken, message: $notice, agentId: $agentId);
	}//end postNotice()
}//end class
