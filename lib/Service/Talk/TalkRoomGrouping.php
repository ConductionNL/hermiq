<?php

/**
 * Hermiq TalkRoomGrouping.
 *
 * Files a bound agent room under a "Hermiq" conversation tag in each
 * participant's own Talk sidebar, so agent rooms stop competing with the
 * conversations people actually talk in.
 *
 * Two facts about Talk tags shape everything here:
 *
 * 1. Tags are PER USER (`oc_talk_conversation_tags.user_id`, with assignment on
 *    the attendee row). There is no shared tag, so grouping a group room means
 *    writing into every participant's own conversation list — a small uninvited
 *    UI change made on their behalf, which is why it is individually
 *    disableable.
 * 2. The assignment API takes the FULL tag list for an attendee-room pair
 *    (`assignTags([])` unassigns everything). A blind write passing only
 *    Hermiq's tag id would silently destroy every tag the user had put on that
 *    room — data Hermiq does not own. Read-modify-write is mandatory.
 *
 * Presentation only: nothing reads this tag to make a decision. Authorization
 * is the participant roster and routing is the room binding. If that ever
 * changes, authorization becomes editable from Talk's UI.
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
 * @spec openspec/changes/archive/2026-07-28-talk-room-grouping/tasks.md#2-tag-resolver
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Groups a user's agent rooms under one personal Talk tag.
 *
 * @psalm-api
 *
 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-bound-agent-room-is-filed-under-each-participants-hermiq-tag
 */
class TalkRoomGrouping {

	/**
	 * The tag name shown in the user's conversation list.
	 *
	 * @var string
	 */
	public const TAG_NAME = 'Hermiq';

	/**
	 * Personal-settings key for the per-user opt-out.
	 *
	 * @var string
	 */
	public const PREFERENCE_KEY = 'talk_group_rooms';

	/**
	 * Spreed's per-user conversation tag service (resolved lazily).
	 *
	 * Introduced in spreed 24; absent on older Talk, where grouping is skipped
	 * and nothing else is affected.
	 *
	 * @var string
	 */
	private const TAG_SERVICE = 'OCA\\Talk\\Service\\ConversationTagService';

	/**
	 * Spreed's participant service, which owns tag assignment.
	 *
	 * @var string
	 */
	private const PARTICIPANT_SERVICE = 'OCA\\Talk\\Service\\ParticipantService';

	/**
	 * Spreed's room manager.
	 *
	 * @var string
	 */
	private const TALK_MANAGER = 'OCA\\Talk\\Manager';

	/**
	 * Constructor.
	 *
	 * @param TalkBridge $bridge Talk availability probe.
	 * @param ContainerInterface $container Server container for lazy spreed resolution.
	 * @param IConfig $config Reads the per-user opt-out preference.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkBridge $bridge,
		private readonly ContainerInterface $container,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this Talk supports conversation tags at all.
	 *
	 * @return bool True when tag grouping can be attempted.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-grouping-never-breaks-anything-it-touches
	 */
	public function isSupported(): bool {
		return $this->bridge->isAvailable() === true && class_exists(self::TAG_SERVICE) === true;
	}//end isSupported()

	/**
	 * File a bound agent room under the Hermiq tag for every participant.
	 *
	 * Best-effort throughout: grouping is cosmetic and MUST never fail a bind,
	 * a turn or a delivery.
	 *
	 * @param string $roomToken The Talk room that was bound.
	 *
	 * @return int The number of participants the room was filed for.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-bound-agent-room-is-filed-under-each-participants-hermiq-tag
	 */
	public function groupRoom(string $roomToken): int {
		if ($this->isSupported() === false || $roomToken === '') {
			return 0;
		}

		$filed = 0;

		try {
			$room = $this->container->get(self::TALK_MANAGER)->getRoomByToken($roomToken);
			$participants = $this->container->get(self::PARTICIPANT_SERVICE)->getParticipantUserIds($room);

			foreach ($participants as $uid) {
				if ($this->fileIfEnabled(room: $room, uid: $uid) === true) {
					$filed++;
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkRoomGrouping] Could not group the room (chat is unaffected)',
				context: $this->failureContext(identifiers: ['roomToken' => $roomToken], error: $e)
			);
		}//end try

		return $filed;
	}//end groupRoom()

	/**
	 * File the room for one participant, when they are a user who left grouping enabled.
	 *
	 * @param object $room The resolved spreed Room.
	 * @param mixed  $uid  The participant's user id, as spreed listed it.
	 *
	 * @return bool True when the room is filed for this participant.
	 */
	private function fileIfEnabled(object $room, mixed $uid): bool {
		if (is_string($uid) === false || $uid === '') {
			return false;
		}

		if ($this->isEnabledFor(uid: $uid) === false) {
			return false;
		}

		return $this->fileForUser(room: $room, uid: $uid);
	}//end fileIfEnabled()

	/**
	 * Whether a user has left Hermiq's tag grouping enabled.
	 *
	 * Defaults to enabled; disabling stops further creation and assignment but
	 * deliberately leaves existing assignments in place — they are the user's
	 * own tags, removable through Talk's UI.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool True when grouping is enabled for this user.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-grouping-is-per-user-optional
	 */
	public function isEnabledFor(string $uid): bool {
		return ($this->config->getUserValue($uid, 'hermiq', self::PREFERENCE_KEY, 'yes') !== 'no');
	}//end isEnabledFor()

	/**
	 * File one room under one user's Hermiq tag.
	 *
	 * @param object $room The resolved spreed Room.
	 * @param string $uid The participant's user id.
	 *
	 * @return bool True when the room is filed for this user.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-bound-agent-room-is-filed-under-each-participants-hermiq-tag
	 */
	private function fileForUser(object $room, string $uid): bool {
		try {
			$tagId = $this->ensureTag(uid: $uid);
			if ($tagId === null) {
				return false;
			}

			$participantService = $this->container->get(self::PARTICIPANT_SERVICE);
			$participant = $participantService->getParticipant($room, $uid);
			$attendee = $participant->getAttendee();

			// READ-MODIFY-WRITE. The assignment API replaces the whole list for
			// this attendee-room pair, so writing only Hermiq's id would wipe
			// every tag the user had on this room.
			$existing = $this->currentTagIds(attendee: $attendee);
			if (in_array($tagId, $existing, true) === true) {
				return true;
			}

			$existing[] = $tagId;
			$participantService->assignConversationToTags($participant, $existing);

			return true;
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkRoomGrouping] Could not file the room for this user',
				context: $this->failureContext(identifiers: ['uid' => $uid], error: $e)
			);
			return false;
		}//end try

	}//end fileForUser()

	/**
	 * The tag ids already assigned to a room for one attendee.
	 *
	 * @param object $attendee The spreed Attendee row.
	 *
	 * @return string[] The currently assigned tag ids.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-bound-agent-room-is-filed-under-each-participants-hermiq-tag
	 */
	private function currentTagIds(object $attendee): array {
		$raw = $attendee->getTagIds();
		if (is_string($raw) === false || $raw === '') {
			return [];
		}

		$ids = [];
		foreach ($this->decodedTagIds(raw: $raw) as $id) {
			$id = trim((string)$id);
			if ($id !== '' && in_array($id, $ids, true) === false) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end currentTagIds()

	/**
	 * The stored tag ids as a list: JSON, or the comma-separated form spreed has also stored.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return array<int|string, mixed> The ids, untrimmed.
	 */
	private function decodedTagIds(string $raw): array {
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			// Spreed has also stored these as a comma-separated list; tolerate both.
			$decoded = explode(',', $raw);
		}

		return $decoded;
	}//end decodedTagIds()

	/**
	 * Resolve the user's Hermiq tag, creating it on first use.
	 *
	 * `(user_id, type, name)` is unique, so a concurrent create fails rather
	 * than duplicating — that failure is treated as success and the existing
	 * tag re-read, which turns a harmless race into a no-op instead of a
	 * failed bind.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string|null The tag id, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-hermiq-conversation-tag-is-created-per-user-on-demand
	 */
	private function ensureTag(string $uid): ?string {
		$tagService = $this->container->get(self::TAG_SERVICE);

		$existing = $this->findTag(uid: $uid);
		if ($existing !== null) {
			return $existing;
		}

		try {
			$created = $tagService->createTag($uid, self::TAG_NAME);

			return (string)$created->getId();
		} catch (Throwable $e) {
			return $this->racedTag(uid: $uid, error: $e);
		}//end try

	}//end ensureTag()

	/**
	 * After a failed create: the tag a concurrent create made, or null with the failure logged.
	 *
	 * Most likely the failure is a uniqueness conflict from a concurrent create,
	 * so the tag is re-read rather than the create failing.
	 *
	 * @param string    $uid   The user id.
	 * @param Throwable $error Why the create failed.
	 *
	 * @return string|null The tag id, or null when there is none.
	 */
	private function racedTag(string $uid, Throwable $error): ?string {
		$recheck = $this->findTag(uid: $uid);
		if ($recheck !== null) {
			return $recheck;
		}

		$this->logger->debug(
			message: '[TalkRoomGrouping] Could not create the Hermiq tag',
			context: $this->failureContext(identifiers: ['uid' => $uid], error: $error)
		);
		return null;
	}//end racedTag()

	/**
	 * Find an existing Hermiq tag for a user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string|null The tag id, or null when the user has none.
	 *
	 * @spec openspec/specs/talk-room-grouping/spec.md#requirement-a-hermiq-conversation-tag-is-created-per-user-on-demand
	 */
	private function findTag(string $uid): ?string {
		try {
			foreach ($this->container->get(self::TAG_SERVICE)->getTags($uid) as $tag) {
				if ($tag->getName() === self::TAG_NAME) {
					return (string)$tag->getId();
				}
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkRoomGrouping] Could not list the user\'s conversation tags',
				context: $this->failureContext(identifiers: ['uid' => $uid], error: $e)
			);
		}

		return null;
	}//end findTag()

	/**
	 * A debug line's context: where it was logged, the identifiers, then the error.
	 *
	 * @param array<string, string> $identifiers The room or user the failure was about.
	 * @param Throwable             $error       The failure.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function failureContext(array $identifiers, Throwable $error): array {
		return array_merge(['file' => __FILE__, 'line' => __LINE__], $identifiers, ['error' => $error->getMessage()]);
	}//end failureContext()
}//end class
