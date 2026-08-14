<?php

/**
 * Hermiq TalkSessionRoom.
 *
 * Gives a chat session its own Talk room, named after the session, with the
 * session's owner in it and the agent's bot enabled.
 *
 * 🔴 This is what makes an agent answer without being `@`-mentioned. The
 * addressing rule keys off `talkRoomOrigin`, and only this service writes
 * `created`. Everything else — a room Hermiq was invited into, a room a report
 * was delivered to — stays `bound` and keeps the mention gate. The value is
 * STORED rather than inferred from the room's shape, because inferring it would
 * silently flip that behaviour the moment somebody invites a second person.
 *
 * Best-effort by contract: Talk being absent, a room creation refused, or a
 * rename failing must never stop a session being created or renamed. Hermiq's
 * own chat UI works with no room at all, so every failure degrades to
 * "no room", which is exactly a pre-Talk session.
 *
 * spreed is reached only through container lookups by FQCN string, never a
 * type-hint, so Hermiq still boots with spreed absent.
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
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates and renames the Talk room a session owns.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
 */
class TalkSessionRoom {

	/**
	 * Spreed's RoomService, resolved by name so spreed stays optional.
	 *
	 * @var string
	 */
	private const TALK_ROOM_SERVICE = 'OCA\\Talk\\Service\\RoomService';

	/**
	 * Spreed's Manager, for resolving a room by token.
	 *
	 * @var string
	 */
	private const TALK_MANAGER = 'OCA\\Talk\\Manager';

	/**
	 * Spreed's group room type (Room::TYPE_GROUP).
	 *
	 * A group room, not one-to-one: a session is shareable by design
	 * (talk-shared-sessions), so the room must be able to take participants.
	 *
	 * @var int
	 */
	private const ROOM_TYPE_GROUP = 2;

	/**
	 * Fallback room name when a session has no title yet.
	 *
	 * @var string
	 */
	private const UNTITLED = 'Hermiq session';

	/**
	 * OpenRegister register slug holding Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for session objects.
	 *
	 * @var string
	 */
	private const CONVERSATION_SCHEMA = 'conversation';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves spreed services lazily.
	 * @param IUserManager $userManager Resolves the owner for room creation.
	 * @param ObjectService $objectService Records the room back onto the session.
	 * @param TalkBridge $bridge Talk availability probe.
	 * @param TalkBotInstaller $installer Enables the agent's bot in the room.
	 * @param TalkAgentBinding $agentBinding Checks the agent opted into Talk.
	 * @param TalkRoomGrouping $grouping Files the room under each participant's Hermiq tag.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IUserManager $userManager,
		private readonly ObjectService $objectService,
		private readonly TalkBridge $bridge,
		private readonly TalkBotInstaller $installer,
		private readonly TalkAgentBinding $agentBinding,
		private readonly TalkRoomGrouping $grouping,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create the room a session owns, and return its token.
	 *
	 * @param string $title The session title, used as the room name.
	 * @param string $ownerUid The session owner, who becomes the room's owner.
	 * @param string $agentId The agent whose bot is enabled in the room.
	 *
	 * @return string|null The room token, or null when no room was created.
	 *
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	public function createForSession(string $title, string $ownerUid, string $agentId): ?string {
		if ($this->bridge->isAvailable() === false || $ownerUid === '' || $agentId === '') {
			return null;
		}

		// 🔴 The agent's half of the two-sided opt-in. Without this a session
		// for an agent that never opted into Talk still gets a room — and that
		// room is useless by construction, because a Talk-disabled agent has no
		// bot to enable in it. Found by e2e; every unit fixture and every live
		// probe had used an opted-in agent, so nothing else could have caught it.
		if ($this->agentBinding->isAgentTalkEnabled(agentId: $agentId) === false) {
			return null;
		}

		try {
			$owner = $this->userManager->get($ownerUid);
			if ($owner === null) {
				return null;
			}

			$room = $this->container->get(self::TALK_ROOM_SERVICE)->createConversation(
				self::ROOM_TYPE_GROUP,
				$this->roomName(title: $title),
				$owner
			);
			$token = (string)$room->getToken();
			if ($token === '') {
				return null;
			}

			// The agent's bot must be enabled IN the room, not merely installed
			// on the instance: spreed's opt-in is two-sided, and a bot that is
			// not enabled here is never invoked for this room's messages.
			$this->installer->enableInRoom(agentId: $agentId, roomToken: $token);

			// File it under the owner's Hermiq tag so a session room does not
			// land in "Other" beside unrelated conversations.
			$this->grouping->groupRoom(roomToken: $token);

			return $token;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkSessionRoom] Could not create the session room (the session is unaffected)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'owner' => $ownerUid,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end createForSession()

	/**
	 * Create a room for a freshly created session and record it on the session.
	 *
	 * 🔴 Writing `talkRoomOrigin = created` is the whole point. The addressing
	 * rule reads that value to decide whether the agent answers unaddressed
	 * messages, and this is the ONLY place that writes it — everything else
	 * stays `bound` and keeps the mention gate.
	 *
	 * A second save rather than a pre-computed token: the room is named after
	 * the session, so until the session exists there is nothing to name it
	 * after. `saveObject()` is PUT-semantic, so the whole payload is carried
	 * forward — omitting a field would delete it.
	 *
	 * Returns the session unchanged when no room could be made. A session with
	 * no room is exactly a pre-Talk session, so this must never be fatal.
	 *
	 * @param ObjectEntity $conversation The freshly created session.
	 * @param string $title The session title, used as the room name.
	 * @param string $ownerUid The session owner.
	 * @param string $agentId The bound agent, whose bot joins the room.
	 *
	 * @return ObjectEntity The session, with its room recorded when one was created.
	 *
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	public function attachToSession(
		ObjectEntity $conversation,
		string $title,
		string $ownerUid,
		string $agentId,
	): ObjectEntity {
		if ($agentId === '') {
			return $conversation;
		}

		try {
			$token = $this->createForSession(title: $title, ownerUid: $ownerUid, agentId: $agentId);
			if ($token === null || $token === '') {
				return $conversation;
			}

			$payload = $conversation->getObject();
			$payload['talkRoomToken'] = $token;
			$payload['talkRoomOrigin'] = TalkRoomBinding::ORIGIN_CREATED;

			return $this->objectService->saveObject(
				object: $payload,
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA,
				uuid: (string)$conversation->getUuid()
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkSessionRoom] Could not record the room on the session',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => (string)$conversation->getUuid(),
					'error' => $e->getMessage(),
				]
			);

			// 🔴 Re-read rather than returning the copy we came in with.
			//
			// The write and the OBJECT-EVENT DISPATCH that follows it share this
			// try block, and the dispatch runs other apps' listeners. One of
			// those throwing means the row was already written — observed live,
			// where an unrelated app's listener raised on its own field and
			// this method reported failure for a save that had succeeded. The
			// caller would then hand the client a session with no room while
			// the stored session had one, and nothing would ever reconcile them.
			return $this->reread(conversation: $conversation);
		}//end try

	}//end attachToSession()

	/**
	 * Re-read a session, falling back to the copy in hand.
	 *
	 * @param ObjectEntity $conversation The session as last known.
	 *
	 * @return ObjectEntity The stored session, or the copy when it cannot be read.
	 *
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	private function reread(ObjectEntity $conversation): ObjectEntity {
		try {
			$fresh = $this->objectService->find(
				id: (string)$conversation->getUuid(),
				register: self::REGISTER_SLUG,
				schema: self::CONVERSATION_SCHEMA
			);

			if ($fresh instanceof ObjectEntity) {
				return $fresh;
			}
		} catch (Throwable $e) {
			// Fall through — the copy in hand is still a usable session.
		}

		return $conversation;
	}//end reread()

	/**
	 * Rename the session's room, but only if the session OWNS it.
	 *
	 * 🔴 The ownership test is the point of this method. A session may be bound
	 * to a room somebody else made — a team room the agent was invited into, or
	 * one a scheduled report was delivered to. Renaming a Hermiq session must
	 * not rewrite that room's title out from under the people using it, so only
	 * `talkRoomOrigin === created` is renamed.
	 *
	 * @param array $session The session payload, after the title change.
	 *
	 * @return bool True when a room was renamed.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-renaming-a-session-renames-its-room
	 */
	public function renameIfOwned(array $session): bool {
		if ((string)($session['talkRoomOrigin'] ?? '') !== TalkRoomBinding::ORIGIN_CREATED) {
			return false;
		}

		return $this->renameRoom(
			roomToken: (string)($session['talkRoomToken'] ?? ''),
			title: (string)($session['title'] ?? '')
		);

	}//end renameIfOwned()

	/**
	 * Rename the room a session owns.
	 *
	 * Only ever called for a room Hermiq CREATED. Renaming a room Hermiq was
	 * merely invited into would rewrite somebody else's conversation title
	 * because a Hermiq session happened to change name.
	 *
	 * @param string $roomToken The room to rename.
	 * @param string $title The new session title.
	 *
	 * @return bool True when the room was renamed.
	 *
	 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-renaming-a-session-renames-its-room
	 */
	public function renameRoom(string $roomToken, string $title): bool {
		if ($this->bridge->isAvailable() === false || $roomToken === '') {
			return false;
		}

		try {
			$room = $this->container->get(self::TALK_MANAGER)->getRoomByToken($roomToken);
			$this->container->get(self::TALK_ROOM_SERVICE)->setName($room, $this->roomName(title: $title));

			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkSessionRoom] Could not rename the session room (the session is unaffected)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'roomToken' => $roomToken,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end renameRoom()

	/**
	 * The room name for a session title.
	 *
	 * Talk stores the name in a bounded column and an empty name renders as a
	 * participant list, which for a session room would read as "you and a bot".
	 *
	 * @param string $title The session title.
	 *
	 * @return string The room name.
	 *
	 * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
	 */
	private function roomName(string $title): string {
		$name = trim($title);
		if ($name === '') {
			return self::UNTITLED;
		}

		return mb_substr($name, 0, 255);
	}//end roomName()
}//end class
