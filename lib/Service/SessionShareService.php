<?php

/**
 * The owner of a session shares it read-only with users and groups (chat-fork-and-share-link).
 *
 * A share is an entry in `Session.sharedWith`, mirrored as a `user:<uid>` or
 * `group:<gid>` string in `Session.shareKeys` so the sessions shared with a
 * person can be filtered. A share grants reading the session and its turns
 * and nothing else: ConversationParticipation, which decides who may take a
 * turn, does not read either property.
 *
 * - Only the owner lists, adds or removes a share. Anyone else gets 404, the
 *   same answer as a session that does not exist.
 * - Nextcloud's sharing settings apply: sharing switched off for the owner,
 *   "share only with group members" and group sharing switched off refuse.
 * - Each new recipient, or each member of a new group, gets a notification.
 * - Removing a share removes access at once; nothing is sent.
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
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTime;
use DateTimeInterface;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\SessionShareServiceInterface;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only shares on a session.
 *
 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
 */
class SessionShareService implements SessionShareServiceInterface {
	use SanitizesForSaveTrait;

	private const REGISTER_SLUG = 'hermiq';

	private const SESSION_SCHEMA = 'agentsession';

	/**
	 * The notification subject a new recipient gets.
	 */
	public const SUBJECT_SHARED = 'session_shared';

	/**
	 * The share types a session accepts.
	 */
	public const TYPES = ['user', 'group'];

	/**
	 * How many shared sessions one listing reads per share key.
	 */
	private const MAX_SHARED_SCAN = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectService        $objectService OpenRegister object access.
	 * @param IUserManager         $userManager   Resolves users and display names.
	 * @param IGroupManager        $groupManager  Resolves groups and memberships.
	 * @param IShareManager        $shareManager  The instance's sharing settings.
	 * @param INotificationManager $notifications Sends the notification to a new recipient.
	 * @param LoggerInterface      $logger        Logs a notification that could not be sent.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IShareManager $shareManager,
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The session's shares, for its owner.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return list<array<string, mixed>> The shares with display names.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	public function list(string $uuid, string $callerUid): array {
		$session = $this->ownedSession(uuid: $uuid, callerUid: $callerUid);

		return $this->describe(shares: self::shares(sessionData: $session->getObject()));
	}//end list()

	/**
	 * Share the session read-only with a user or a group.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller, who must own the session.
	 * @param string $type      `user` or `group`.
	 * @param string $id        The user id or group id.
	 *
	 * @return list<array<string, mixed>> The shares after the change.
	 *
	 * @throws SessionShareException 404, 400 or 403.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	public function add(string $uuid, string $callerUid, string $type, string $id): array {
		$session = $this->ownedSession(uuid: $uuid, callerUid: $callerUid);
		$payload = $session->getObject();
		$id      = trim($id);

		if (in_array($type, self::TYPES, true) === false) {
			throw new SessionShareException('A share is with a user or a group.', 400);
		}

		$this->assertMayShare(callerUid: $callerUid, type: $type, id: $id);

		$shares = self::shares(sessionData: $payload);
		if (self::indexOf(shares: $shares, type: $type, id: $id) !== null) {
			return $this->describe(shares: $shares);
		}

		$shares[] = [
			'type' => $type,
			'id' => $id,
			'sharedBy' => $callerUid,
			'sharedAt' => (new DateTime())->format(DateTimeInterface::ATOM),
		];
		$this->saveShares(uuid: $uuid, payload: $payload, shares: $shares);
		$this->notifyRecipients(type: $type, id: $id, callerUid: $callerUid, uuid: $uuid, title: (string)($payload['title'] ?? ''));

		return $this->describe(shares: $shares);
	}//end add()

	/**
	 * Take a share back. Removing a share that does not exist changes nothing.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller, who must own the session.
	 * @param string $type      `user` or `group`.
	 * @param string $id        The user id or group id.
	 *
	 * @return list<array<string, mixed>> The shares after the change.
	 *
	 * @throws SessionShareException 404 when the caller does not own the session.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
	 */
	public function remove(string $uuid, string $callerUid, string $type, string $id): array {
		$session = $this->ownedSession(uuid: $uuid, callerUid: $callerUid);
		$payload = $session->getObject();
		$shares  = self::shares(sessionData: $payload);

		$index = self::indexOf(shares: $shares, type: $type, id: $id);
		if ($index !== null) {
			array_splice($shares, $index, 1);
			$this->saveShares(uuid: $uuid, payload: $payload, shares: $shares);
		}

		return $this->describe(shares: $shares);
	}//end remove()

	/**
	 * The share that lets the user read the session: one naming the user, else
	 * one naming a group the user is in. The owner holds no share.
	 *
	 * @param array<string, mixed> $sessionData The session's data.
	 * @param string               $uid         The reader.
	 *
	 * @return array<string, mixed>|null The share entry.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function shareFor(array $sessionData, string $uid): ?array {
		if ($uid === '' || ($sessionData['userId'] ?? null) === $uid) {
			return null;
		}

		$shares = self::shares(sessionData: $sessionData);
		$direct = self::indexOf(shares: $shares, type: 'user', id: $uid);
		if ($direct !== null) {
			return $this->withSharer(share: $shares[$direct]);
		}

		$groups = $this->groupsOf(uid: $uid);
		foreach ($shares as $share) {
			if ($share['type'] === 'group' && in_array($share['id'], $groups, true) === true) {
				return $this->withSharer(share: $share);
			}
		}

		return null;
	}//end shareFor()

	/**
	 * The share with the display name of the owner who made it, for "Shared by".
	 *
	 * @param array<string, string> $share The share.
	 *
	 * @return array<string, string>
	 */
	private function withSharer(array $share): array {
		$share['sharedByDisplayName'] = ($this->userManager->get($share['sharedBy'])?->getDisplayName() ?? $share['sharedBy']);

		return $share;
	}//end withSharer()

	/**
	 * The session when the user may read it through a share, else null. The
	 * session is read without OpenRegister's per-object rule, which admits
	 * only the owner and participants; the share check here replaces it.
	 *
	 * @param string $uuid The session uuid.
	 * @param string $uid  The reader.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function sharedSession(string $uuid, string $uid): ?ObjectEntity {
		if ($uid === '') {
			return null;
		}

		try {
			$session = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::SESSION_SCHEMA, _rbac: false);
		} catch (Throwable) {
			return null;
		}

		if ($session === null || $this->shareFor(sessionData: $session->getObject(), uid: $uid) === null) {
			return null;
		}

		return $session;
	}//end sharedSession()

	/**
	 * The sessions shared with the user, directly or through a group, newest first.
	 *
	 * @param string $uid The reader.
	 *
	 * @return list<ObjectEntity>
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function sharedWith(string $uid): array {
		if ($uid === '') {
			return [];
		}

		$keys = ['user:' . $uid];
		foreach ($this->groupsOf(uid: $uid) as $gid) {
			$keys[] = 'group:' . $gid;
		}

		$sessions = [];
		foreach ($keys as $key) {
			$found = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SESSION_SCHEMA)
				->findAll(
				config: [
					'filters' => ['shareKeys' => $key],
					'sort' => ['updated' => 'DESC'],
					'limit' => self::MAX_SHARED_SCAN,
				],
				_rbac: false
				);
			foreach ($found as $session) {
				// The filter narrows; the share entry decides.
				if ($session instanceof ObjectEntity && $this->shareFor(sessionData: $session->getObject(), uid: $uid) !== null) {
					$sessions[(string)$session->getUuid()] = $session;
				}
			}
		}

		$sessions = array_values($sessions);
		usort(
			$sessions,
			static fn (ObjectEntity $a, ObjectEntity $b): int => ($b->getUpdated()?->getTimestamp() ?? 0) <=> ($a->getUpdated()?->getTimestamp() ?? 0)
		);

		return $sessions;
	}//end sharedWith()

	/**
	 * The shares stored on a session, each with a known type and an id.
	 *
	 * @param array<string, mixed> $sessionData The session's data.
	 *
	 * @return list<array{type: string, id: string, sharedBy: string, sharedAt: string}>
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public static function shares(array $sessionData): array {
		$stored = ($sessionData['sharedWith'] ?? []);
		if (is_array($stored) === false) {
			return [];
		}

		$shares = [];
		foreach ($stored as $share) {
			if (is_array($share) === false
				|| in_array(($share['type'] ?? null), self::TYPES, true) === false
				|| is_string($share['id'] ?? null) === false
				|| $share['id'] === ''
			) {
				continue;
			}

			$shares[] = [
				'type' => (string)$share['type'],
				'id' => (string)$share['id'],
				'sharedBy' => (string)($share['sharedBy'] ?? ''),
				'sharedAt' => (string)($share['sharedAt'] ?? ''),
			];
		}

		return $shares;
	}//end shares()

	/**
	 * The session, when the caller owns it.
	 *
	 * @param string $uuid      The session uuid.
	 * @param string $callerUid The caller.
	 *
	 * @return ObjectEntity
	 *
	 * @throws SessionShareException 404 for anyone but the owner.
	 */
	private function ownedSession(string $uuid, string $callerUid): ObjectEntity {
		$session = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::SESSION_SCHEMA);
		if ($session === null || $callerUid === '' || ($session->getObject()['userId'] ?? null) !== $callerUid) {
			throw new SessionShareException('Session not found.', 404);
		}

		return $session;
	}//end ownedSession()

	/**
	 * Refuse a share Nextcloud's sharing settings do not allow the owner.
	 *
	 * @param string $callerUid The owner.
	 * @param string $type      `user` or `group`.
	 * @param string $id        The user id or group id.
	 *
	 * @return void
	 *
	 * @throws SessionShareException 400 for an unknown recipient, 403 for a refused share.
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	private function assertMayShare(string $callerUid, string $type, string $id): void {
		if ($this->shareManager->shareApiEnabled() === false || $this->shareManager->sharingDisabledForUser($callerUid) === true) {
			throw new SessionShareException('Sharing is switched off for your account.', 403);
		}

		if ($type === 'user') {
			$this->assertMayShareWithUser(callerUid: $callerUid, uid: $id);
			return;
		}

		if ($this->shareManager->allowGroupSharing() === false) {
			throw new SessionShareException('Sharing with groups is switched off.', 403);
		}

		if ($id === '' || $this->groupManager->groupExists($id) === false) {
			throw new SessionShareException('There is no group with that name.', 400);
		}

		if ($this->shareManager->shareWithGroupMembersOnly() === true
			&& in_array($id, $this->restrictingGroupsOf(uid: $callerUid), true) === false
		) {
			throw new SessionShareException('You can only share with groups you are a member of.', 403);
		}
	}//end assertMayShare()

	/**
	 * Refuse a share with a user the owner may not share with.
	 *
	 * @param string $callerUid The owner.
	 * @param string $uid       The recipient.
	 *
	 * @return void
	 *
	 * @throws SessionShareException 400 or 403.
	 */
	private function assertMayShareWithUser(string $callerUid, string $uid): void {
		if ($uid === $callerUid) {
			throw new SessionShareException('You own this session already.', 400);
		}

		if ($uid === '' || $this->userManager->get($uid) === null) {
			throw new SessionShareException('There is no user with that name.', 400);
		}

		if ($this->shareManager->shareWithGroupMembersOnly() === true
			&& array_intersect($this->restrictingGroupsOf(uid: $callerUid), $this->groupsOf(uid: $uid)) === []
		) {
			throw new SessionShareException('You can only share with members of your groups.', 403);
		}
	}//end assertMayShareWithUser()

	/**
	 * The user's groups that count for "share only with group members": all of
	 * them but the groups the instance excludes from that rule.
	 *
	 * @param string $uid The user.
	 *
	 * @return list<string>
	 */
	private function restrictingGroupsOf(string $uid): array {
		$excluded = $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList();

		return array_values(array_diff($this->groupsOf(uid: $uid), $excluded));
	}//end restrictingGroupsOf()

	/**
	 * The user's group ids.
	 *
	 * @param string $uid The user.
	 *
	 * @return list<string>
	 */
	private function groupsOf(string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}

		return array_values(array_map('strval', $this->groupManager->getUserGroupIds($user)));
	}//end groupsOf()

	/**
	 * The position of a share in the list, or null.
	 *
	 * @param list<array<string, string>> $shares The shares.
	 * @param string                      $type   `user` or `group`.
	 * @param string                      $id     The user id or group id.
	 *
	 * @return int|null
	 */
	private static function indexOf(array $shares, string $type, string $id): ?int {
		foreach ($shares as $index => $share) {
			if ($share['type'] === $type && $share['id'] === $id) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * Save the shares and their keys onto the session, with its full payload.
	 *
	 * @param string                      $uuid    The session uuid.
	 * @param array<string, mixed>        $payload The session's data.
	 * @param list<array<string, string>> $shares  The shares to store.
	 *
	 * @return void
	 */
	private function saveShares(string $uuid, array $payload, array $shares): void {
		unset($payload['@self'], $payload['id']);
		$payload['sharedWith'] = array_values($shares);
		$payload['shareKeys']  = array_values(
			array_map(static fn (array $share): string => $share['type'] . ':' . $share['id'], $shares)
		);
		$this->objectService->saveObject(
			object: $this->sanitizeForSave(data: $payload),
			register: self::REGISTER_SLUG,
			schema: self::SESSION_SCHEMA,
			uuid: $uuid
		);
	}//end saveShares()

	/**
	 * The shares with the recipient's display name; a group says it reaches
	 * its members now and later.
	 *
	 * @param list<array<string, string>> $shares The shares.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function describe(array $shares): array {
		$list = [];
		foreach ($shares as $share) {
			$displayName = $share['id'];
			if ($share['type'] === 'user') {
				$displayName = ($this->userManager->get($share['id'])?->getDisplayName() ?? $share['id']);
			} else {
				$displayName = ($this->groupManager->get($share['id'])?->getDisplayName() ?? $share['id']);
			}

			$list[] = $share + ['displayName' => $displayName];
		}

		return $list;
	}//end describe()

	/**
	 * Tell the new recipient, or each member of the new group but the owner.
	 *
	 * @param string $type      `user` or `group`.
	 * @param string $id        The user id or group id.
	 * @param string $callerUid The owner.
	 * @param string $uuid      The session uuid.
	 * @param string $title     The session title.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-shares-a-session-with-named-users-or-groups-req-sshare-001
	 */
	private function notifyRecipients(string $type, string $id, string $callerUid, string $uuid, string $title): void {
		$uids = [$id];
		if ($type === 'group') {
			$uids = [];
			foreach (($this->groupManager->get($id)?->getUsers() ?? []) as $member) {
				$uids[] = $member->getUID();
			}
		}

		$owner = ($this->userManager->get($callerUid)?->getDisplayName() ?? $callerUid);
		foreach (array_unique($uids) as $uid) {
			if ($uid === $callerUid) {
				continue;
			}

			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp(AppIdentity::APP_ID)
					->setUser($uid)
					->setDateTime(new DateTime())
					->setObject('session', $uuid)
					->setSubject(self::SUBJECT_SHARED, ['owner' => $owner, 'name' => $title]);
				$this->notifications->notify($notification);
			} catch (Throwable $e) {
				$this->logger->warning('[SessionShareService] notification failed: ' . $e->getMessage());
			}
		}
	}//end notifyRecipients()
}//end class
