<?php

/**
 * The owner of a web session invites colleagues into it (chat-work-together-in-one-session).
 *
 * `Session.participants` already decides who may take a turn
 * (ConversationParticipation) and who may read the object (the schema's read
 * rule). Until now only TalkRoomBinding wrote it, from a Talk room's members.
 * This service is the second writer, for sessions that live on /chat only:
 *
 * - Only the owner changes the list. Anyone else, including a participant, gets
 *   404, the same answer as a session that does not exist.
 * - A session bound to a Talk room is refused (409): its roster follows the room,
 *   and a web edit would be overwritten by the next room sync.
 * - The owner cannot be listed (implicit), and an unknown uid is refused (400).
 * - Each person added gets a notification.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists, adds and removes the participants of a session.
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
class SessionParticipantService {
	use SanitizesForSaveTrait;

	/**
	 * Register slug.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Session schema slug.
	 *
	 * @var string
	 */
	private const SESSION_SCHEMA = 'agentsession';

	/**
	 * Notification subject for a person added to a session.
	 *
	 * @var string
	 */
	public const SUBJECT_ADDED = 'session_participant_added';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read and write.
	 * @param IUserManager $userManager Resolves uids and display names.
	 * @param INotificationManager $notifications Sends the invitation notification.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ConversationParticipation $participation Roster normaliser.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $userManager,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
	) {
	}//end __construct()

	/**
	 * The participant list of a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return array<int, array{uid: string, displayName: string}> The participants.
	 *
	 * @throws SessionParticipantException 404 when the caller does not own the session.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function list(string $uuid, string $callerUid): array {
		$session = $this->ownedSession(uuid: $uuid, callerUid: $callerUid);

		return $this->describe(uids: $this->participation->roster(conversationData: $session->getObject()));
	}//end list()

	/**
	 * Add a colleague to a session the caller owns, and notify them.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 * @param string $uid The colleague to add.
	 *
	 * @return array<int, array{uid: string, displayName: string}> The participants after the change.
	 *
	 * @throws SessionParticipantException 404 not the owner, 409 Talk-bound, 400 owner or unknown uid.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function add(string $uuid, string $callerUid, string $uid): array {
		$session = $this->editableSession(uuid: $uuid, callerUid: $callerUid);
		$payload = $session->getObject();

		if ($uid === $callerUid) {
			throw new SessionParticipantException('The owner is always part of the session.', 400);
		}

		if ($uid === '' || $this->userManager->get($uid) === null) {
			throw new SessionParticipantException('There is no user with that name.', 400);
		}

		$roster = $this->participation->roster(conversationData: $payload);
		if (in_array($uid, $roster, true) === true) {
			return $this->describe(uids: $roster);
		}

		$roster[] = $uid;
		$this->saveRoster(uuid: $uuid, payload: $payload, roster: $roster);
		$this->notifyAdded(uid: $uid, callerUid: $callerUid, uuid: $uuid, title: (string)($payload['title'] ?? ''));

		return $this->describe(uids: $roster);
	}//end add()

	/**
	 * Take a colleague off a session the caller owns.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 * @param string $uid The colleague to remove.
	 *
	 * @return array<int, array{uid: string, displayName: string}> The participants after the change.
	 *
	 * @throws SessionParticipantException 404 not the owner, 409 Talk-bound.
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function remove(string $uuid, string $callerUid, string $uid): array {
		$session = $this->editableSession(uuid: $uuid, callerUid: $callerUid);
		$payload = $session->getObject();

		$roster = $this->participation->roster(conversationData: $payload);
		$kept = array_values(array_filter($roster, static fn (string $member): bool => $member !== $uid));
		if (count($kept) !== count($roster)) {
			$this->saveRoster(uuid: $uuid, payload: $payload, roster: $kept);
		}

		return $this->describe(uids: $kept);
	}//end remove()

	/**
	 * The session, when the caller owns it; otherwise 404.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return ObjectEntity The session.
	 *
	 * @throws SessionParticipantException 404.
	 */
	private function ownedSession(string $uuid, string $callerUid): ObjectEntity {
		$session = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::SESSION_SCHEMA);
		if ($session === null || $callerUid === '' || ($session->getObject()['userId'] ?? null) !== $callerUid) {
			throw new SessionParticipantException('Session not found.', 404);
		}

		return $session;
	}//end ownedSession()

	/**
	 * The session, when the caller owns it and it is not bound to a Talk room.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return ObjectEntity The session.
	 *
	 * @throws SessionParticipantException 404 or 409.
	 */
	private function editableSession(string $uuid, string $callerUid): ObjectEntity {
		$session = $this->ownedSession(uuid: $uuid, callerUid: $callerUid);
		if ((string)($session->getObject()['talkRoomToken'] ?? '') !== '') {
			throw new SessionParticipantException('This session belongs to a Talk room. Invite the person to the room instead.', 409);
		}

		return $session;
	}//end editableSession()

	/**
	 * Save the full session payload with a new roster.
	 *
	 * @param string $uuid The session uuid.
	 * @param array<string, mixed> $payload The stored payload.
	 * @param string[] $roster The new roster.
	 *
	 * @return void
	 */
	private function saveRoster(string $uuid, array $payload, array $roster): void {
		unset($payload['@self'], $payload['id']);
		$payload['participants'] = array_values($roster);
		$this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $payload),
			register: self::REGISTER_SLUG,
			schema: self::SESSION_SCHEMA,
			uuid: $uuid
		);
	}//end saveRoster()

	/**
	 * Uids with their display names (the uid itself for a user that no longer exists).
	 *
	 * @param string[] $uids The uids.
	 *
	 * @return array<int, array{uid: string, displayName: string}>
	 */
	private function describe(array $uids): array {
		$list = [];
		foreach ($uids as $uid) {
			$user = $this->userManager->get($uid);
			$list[] = ['uid' => $uid, 'displayName' => ($user?->getDisplayName() ?? $uid)];
		}

		return $list;
	}//end describe()

	/**
	 * Tell a colleague they were added. A failed notification never undoes the change.
	 *
	 * @param string $uid The colleague.
	 * @param string $callerUid The owner who added them.
	 * @param string $uuid The session uuid.
	 * @param string $title The session title.
	 *
	 * @return void
	 */
	private function notifyAdded(string $uid, string $callerUid, string $uuid, string $title): void {
		try {
			$owner = $this->userManager->get($callerUid);
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime(new DateTime())
				->setObject('session', $uuid)
				->setSubject(self::SUBJECT_ADDED, ['owner' => ($owner?->getDisplayName() ?? $callerUid), 'name' => $title]);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('[SessionParticipantService] notification failed: ' . $e->getMessage());
		}
	}//end notifyAdded()
}//end class
