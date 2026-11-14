<?php

/**
 * SessionShareService: the owner shares a session read-only with users and
 * groups under Nextcloud's sharing rules, and takes a share back; anyone else
 * gets the same 404 as a missing session. The saved Session payload is
 * validated against the REAL `Session` schema fragment in
 * lib/Settings/hermiq_register.json.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SessionShareException;
use OCA\Hermiq\Service\SessionShareService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Share\IManager as IShareManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Owner-only share management, sharing rules, notifications and recipient reads.
 */
class SessionShareServiceTest extends TestCase {

	/**
	 * Payloads saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Notifications sent, as [user, subject, parameters, object id].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
	 */
	private array $sent = [];

	/**
	 * The findAll configs and RBAC flags asked for.
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: bool}>
	 */
	private array $queries = [];

	/**
	 * Instance sharing settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = ['enabled' => true, 'disabledFor' => [], 'groupMembersOnly' => false, 'groupSharing' => true, 'excluded' => []];

	/**
	 * Group membership by user.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GROUPS = ['anne' => ['team-inkoop', 'everyone'], 'bram' => ['team-inkoop'], 'carla' => ['team-inkoop'], 'dirk' => ['team-juridisch', 'everyone']];

	/**
	 * The service over one stored session.
	 *
	 * @param array<string, mixed>|null      $payload The session, or null for none.
	 * @param array<int, ObjectEntity> $listed  What a share-key listing answers.
	 *
	 * @return SessionShareService
	 */
	private function service(?array $payload, array $listed = []): SessionShareService {
		$objectService = $this->createMock(ObjectService::class);
		$entity = null;
		if ($payload !== null) {
			$entity = new ObjectEntity();
			$entity->setUuid('sess-1');
			$entity->setObject($payload);
		}

		$objectService->method('find')->willReturn($entity);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($listed): array {
				$this->queries[] = [$config, $_rbac];
				return array_values(array_filter($listed, static fn (ObjectEntity $s): bool => in_array($config['filters']['shareKeys'], ($s->getObject()['shareKeys'] ?? []), true)));
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				$saved = new ObjectEntity();
				$saved->setUuid('sess-1');
				$saved->setObject($object);
				return $saved;
			}
		);

		$names = ['anne' => 'Anne de Vries', 'bram' => 'Bram Jansen', 'carla' => 'Carla Smit', 'dirk' => 'Dirk Bos'];
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($names): ?IUser {
				if (isset($names[$uid]) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('getDisplayName')->willReturn($names[$uid]);
				return $user;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturnCallback(static fn (IUser $user): array => (self::GROUPS[$user->getUID()] ?? []));
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, ['team-inkoop', 'team-juridisch', 'everyone'], true));
		$groups->method('get')->willReturnCallback(
			function (string $gid) use ($users): ?IGroup {
				$group = $this->createMock(IGroup::class);
				$group->method('getDisplayName')->willReturn('Team ' . $gid);
				$members = [];
				foreach (self::GROUPS as $uid => $memberOf) {
					if (in_array($gid, $memberOf, true) === true) {
						$members[] = $users->get($uid);
					}
				}

				$group->method('getUsers')->willReturn($members);
				return $group;
			}
		);

		$shares = $this->createMock(IShareManager::class);
		$shares->method('shareApiEnabled')->willReturnCallback(fn (): bool => $this->settings['enabled']);
		$shares->method('sharingDisabledForUser')->willReturnCallback(fn (?string $uid): bool => in_array($uid, $this->settings['disabledFor'], true));
		$shares->method('shareWithGroupMembersOnly')->willReturnCallback(fn (): bool => $this->settings['groupMembersOnly']);
		$shares->method('shareWithGroupMembersOnlyExcludeGroupsList')->willReturnCallback(fn (): array => $this->settings['excluded']);
		$shares->method('allowGroupSharing')->willReturnCallback(fn (): bool => $this->settings['groupSharing']);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function (): INotification {
				$record = ['', '', [], ''];
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime'] as $setter) {
					$notification->method($setter)->willReturnSelf();
				}

				$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$record, $notification) {
					$record[0] = $uid;
					return $notification;
				});
				$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use (&$record, $notification) {
					$record[1] = $subject;
					$record[2] = $parameters;
					return $notification;
				});
				$notification->method('setObject')->willReturnCallback(function (string $type, string $id) use (&$record, $notification) {
					$record[3] = $id;
					return $notification;
				});
				$notification->method('getObjectId')->willReturnCallback(function () use (&$record): string {
					return $record[3];
				});
				$notification->method('getUser')->willReturnCallback(function () use (&$record): string {
					return $record[0];
				});
				$notification->method('getSubject')->willReturnCallback(function () use (&$record): string {
					return $record[1];
				});
				$notification->method('getSubjectParameters')->willReturnCallback(function () use (&$record): array {
					return $record[2];
				});
				return $notification;
			}
		);
		$notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = [$notification->getUser(), $notification->getSubject(), $notification->getSubjectParameters(), $notification->getObjectId()];
			}
		);

		return new SessionShareService($objectService, $users, $groups, $shares, $notifications, new NullLogger());
	}//end service()

	/**
	 * Anne's session, optionally with shares.
	 *
	 * @param array<int, array<string, string>> $sharedWith The stored shares.
	 *
	 * @return array<string, mixed>
	 */
	private function annesSession(array $sharedWith = []): array {
		return [
			'agentId' => '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a5c',
			'userId' => 'anne',
			'title' => 'Offerte dakrenovatie Stadskantoor',
			'participants' => [],
			'sharedWith' => $sharedWith,
			'shareKeys' => array_map(static fn (array $s): string => $s['type'] . ':' . $s['id'], $sharedWith),
		];
	}//end annesSession()

	/**
	 * Validate a payload against the real Session schema fragment.
	 *
	 * @param array<string, mixed> $payload The saved payload.
	 *
	 * @return bool
	 */
	private function validSession(array $payload): bool {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
		$schema = $register->components->schemas->Session;
		$this->stripSlugRefs($schema);
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $schema)->isValid();
	}//end validSession()

	/**
	 * Remove every `$ref` that is not a JSON pointer, recursively.
	 *
	 * @param mixed $node A schema node.
	 *
	 * @return void
	 */
	private function stripSlugRefs(mixed $node): void {
		if (is_object($node) === false && is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $child) {
			if ($key === '$ref' && is_string($child) === true && str_starts_with($child, '#') === false) {
				unset($node->{'$ref'});
				continue;
			}

			$this->stripSlugRefs($child);
		}
	}//end stripSlugRefs()

	/**
	 * The owner shares with a group: the share and its key are saved with the
	 * rest of the session, the payload passes the real schema, and every member
	 * but the owner is told.
	 *
	 * @return void
	 */
	public function testTheOwnerSharesWithAGroupAndEveryMemberIsTold(): void {
		$list = $this->service($this->annesSession())->add(uuid: 'sess-1', callerUid: 'anne', type: 'group', id: 'team-inkoop');

		$this->assertCount(1, $this->saved);
		$this->assertSame('group', $this->saved[0]['sharedWith'][0]['type']);
		$this->assertSame('team-inkoop', $this->saved[0]['sharedWith'][0]['id']);
		$this->assertSame('anne', $this->saved[0]['sharedWith'][0]['sharedBy']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $this->saved[0]['sharedWith'][0]['sharedAt']);
		$this->assertSame(['group:team-inkoop'], $this->saved[0]['shareKeys']);
		$this->assertSame('Offerte dakrenovatie Stadskantoor', $this->saved[0]['title'], 'The rest of the session survives the save.');
		$this->assertTrue($this->validSession($this->saved[0]), 'The saved payload must pass the real Session schema.');
		$this->assertSame('Team team-inkoop', $list[0]['displayName']);

		$this->assertSame(['bram', 'carla'], array_column($this->sent, 0));
		$this->assertSame(['session_shared', ['owner' => 'Anne de Vries', 'name' => 'Offerte dakrenovatie Stadskantoor'], 'sess-1'], array_slice($this->sent[0], 1));
	}//end testTheOwnerSharesWithAGroupAndEveryMemberIsTold()

	/**
	 * Sharing with a user saves a user share and tells that user; a second add of
	 * the same share changes nothing and tells nobody.
	 *
	 * @return void
	 */
	public function testAUserShareIsSavedOnce(): void {
		$service = $this->service($this->annesSession());
		$list = $service->add(uuid: 'sess-1', callerUid: 'anne', type: 'user', id: 'bram');

		$this->assertSame(['user:bram'], $this->saved[0]['shareKeys']);
		$this->assertSame('Bram Jansen', $list[0]['displayName']);
		$this->assertSame(['bram'], array_column($this->sent, 0));

		$this->saved = [];
		$this->sent = [];
		$existing = $this->annesSession([['type' => 'user', 'id' => 'bram', 'sharedBy' => 'anne', 'sharedAt' => '2026-09-12T10:04:00+02:00']]);
		$again = $this->service($existing)->add(uuid: 'sess-1', callerUid: 'anne', type: 'user', id: 'bram');
		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->sent);
		$this->assertSame('2026-09-12T10:04:00+02:00', $again[0]['sharedAt']);
	}//end testAUserShareIsSavedOnce()

	/**
	 * Anyone but the owner gets 404 on every share route, and nothing is saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function testANonOwnerGetsNotFoundOnEveryRoute(): void {
		$shared = $this->annesSession([['type' => 'user', 'id' => 'bram', 'sharedBy' => 'anne', 'sharedAt' => 'x']]);
		$calls = [
			fn () => $this->service($shared)->list(uuid: 'sess-1', callerUid: 'bram'),
			fn () => $this->service($shared)->add(uuid: 'sess-1', callerUid: 'bram', type: 'user', id: 'carla'),
			fn () => $this->service($shared)->remove(uuid: 'sess-1', callerUid: 'bram', type: 'user', id: 'bram'),
			fn () => $this->service(null)->list(uuid: 'sess-1', callerUid: 'anne'),
		];
		foreach ($calls as $index => $call) {
			try {
				$call();
				$this->fail('call ' . $index . ' was not refused');
			} catch (SessionShareException $e) {
				$this->assertSame(404, $e->getCode());
				$this->assertSame('Session not found.', $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);
	}//end testANonOwnerGetsNotFoundOnEveryRoute()

	/**
	 * The instance's sharing rules refuse, and sharedWith stays as it was.
	 *
	 * @return void
	 */
	public function testTheSharingRulesRefuse(): void {
		$cases = [
			// Anne and Dirk share only `everyone`, which the instance excludes from the rule.
			[['groupMembersOnly' => true, 'excluded' => ['everyone']], 'user', 'dirk', 403, 'You can only share with members of your groups.'],
			[['groupMembersOnly' => true, 'excluded' => ['team-inkoop']], 'user', 'bram', 403, 'You can only share with members of your groups.'],
			[['groupMembersOnly' => true], 'group', 'team-juridisch', 403, 'You can only share with groups you are a member of.'],
			[['groupSharing' => false], 'group', 'team-inkoop', 403, 'Sharing with groups is switched off.'],
			[['disabledFor' => ['anne']], 'user', 'bram', 403, 'Sharing is switched off for your account.'],
			[['enabled' => false], 'user', 'bram', 403, 'Sharing is switched off for your account.'],
			[[], 'user', 'nobody', 400, 'There is no user with that name.'],
			[[], 'user', 'anne', 400, 'You own this session already.'],
			[[], 'group', 'ghosts', 400, 'There is no group with that name.'],
			[[], 'circle', 'x', 400, 'A share is with a user or a group.'],
		];
		$default = $this->settings;
		foreach ($cases as [$settings, $type, $id, $code, $message]) {
			$this->settings = $settings + $default;
			try {
				$this->service($this->annesSession())->add(uuid: 'sess-1', callerUid: 'anne', type: $type, id: $id);
				$this->fail($type . ':' . $id . ' was not refused');
			} catch (SessionShareException $e) {
				$this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()], $type . ':' . $id);
			}
		}

		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->sent);

		// Control: with the rule on, a colleague in a shared group is fine, and
		// so is Dirk while `everyone` still counts.
		$this->settings = ['groupMembersOnly' => true] + $default;
		$this->service($this->annesSession())->add(uuid: 'sess-1', callerUid: 'anne', type: 'user', id: 'bram');
		$this->service($this->annesSession())->add(uuid: 'sess-1', callerUid: 'anne', type: 'user', id: 'dirk');
		$this->assertCount(2, $this->saved);
	}//end testTheSharingRulesRefuse()

	/**
	 * Removing a share saves the list without it and its key, and tells nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-the-owner-can-revoke-a-share-at-once-req-sshare-003
	 */
	public function testTheOwnerRemovesAShare(): void {
		$shares = [
			['type' => 'user', 'id' => 'bram', 'sharedBy' => 'anne', 'sharedAt' => 'a'],
			['type' => 'group', 'id' => 'team-inkoop', 'sharedBy' => 'anne', 'sharedAt' => 'b'],
		];
		$list = $this->service($this->annesSession($shares))->remove(uuid: 'sess-1', callerUid: 'anne', type: 'user', id: 'bram');

		$this->assertSame(['group:team-inkoop'], $this->saved[0]['shareKeys']);
		$this->assertSame([['type' => 'group', 'id' => 'team-inkoop', 'sharedBy' => 'anne', 'sharedAt' => 'b']], $this->saved[0]['sharedWith']);
		$this->assertSame(['team-inkoop'], array_column($list, 'id'));
		$this->assertSame([], $this->sent);

		$this->saved = [];
		$this->service($this->annesSession($shares))->remove(uuid: 'sess-1', callerUid: 'anne', type: 'user', id: 'carla');
		$this->assertSame([], $this->saved, 'Removing a share that is not there saves nothing.');
	}//end testTheOwnerRemovesAShare()

	/**
	 * A share admits the named user and the members of a named group, never the
	 * owner, and never anyone else; a recipient still cannot take a turn.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-fork-and-share-link/specs/session-sharing/spec.md#requirement-a-share-recipient-can-read-and-do-nothing-else-req-sshare-002
	 */
	public function testWhoAShareAdmits(): void {
		$data = $this->annesSession([
			['type' => 'user', 'id' => 'dirk', 'sharedBy' => 'anne', 'sharedAt' => 'a'],
			['type' => 'group', 'id' => 'team-inkoop', 'sharedBy' => 'anne', 'sharedAt' => 'b'],
		]);
		$service = $this->service($data);

		$this->assertSame('dirk', $service->shareFor(sessionData: $data, uid: 'dirk')['id']);
		$this->assertSame('team-inkoop', $service->shareFor(sessionData: $data, uid: 'carla')['id']);
		$this->assertSame('Anne de Vries', $service->shareFor(sessionData: $data, uid: 'carla')['sharedByDisplayName']);
		$this->assertNull($service->shareFor(sessionData: $data, uid: 'anne'));
		$this->assertNull($service->shareFor(sessionData: $data, uid: 'nobody'));
		$this->assertNull($service->shareFor(sessionData: $data, uid: ''));
		$this->assertNull($service->shareFor(sessionData: $this->annesSession(), uid: 'dirk'));
		$this->assertSame('sess-1', $service->sharedSession(uuid: 'sess-1', uid: 'carla')?->getUuid());
		$this->assertNull($service->sharedSession(uuid: 'sess-1', uid: 'nobody'));

		$participation = new ConversationParticipation();
		$this->assertFalse($participation->mayTakeTurn(conversationData: $data, userId: 'carla'), 'A share never grants a turn.');
		$this->assertFalse($participation->mayTakeTurn(conversationData: $data, userId: 'dirk'));
	}//end testWhoAShareAdmits()

	/**
	 * "Shared with you" reads one filtered listing per key, without RBAC, and
	 * keeps a session only while a share still names the reader.
	 *
	 * @return void
	 */
	public function testSharedWithListsByKeyAndChecksTheShare(): void {
		$byGroup = new ObjectEntity();
		$byGroup->setUuid('sess-g');
		$byGroup->setObject($this->annesSession([['type' => 'group', 'id' => 'team-inkoop', 'sharedBy' => 'anne', 'sharedAt' => 'a']]));
		$stale = new ObjectEntity();
		$stale->setUuid('sess-stale');
		// A key left behind without its share entry admits nobody.
		$stale->setObject(['userId' => 'anne', 'sharedWith' => [], 'shareKeys' => ['user:bram']]);

		$found = $this->service(null, [$byGroup, $stale])->sharedWith(uid: 'bram');

		$this->assertSame(['sess-g'], array_map(static fn (ObjectEntity $s): string => (string)$s->getUuid(), $found));
		$this->assertSame(
			[[['filters' => ['shareKeys' => 'user:bram'], 'sort' => ['updated' => 'DESC'], 'limit' => 200], false], [['filters' => ['shareKeys' => 'group:team-inkoop'], 'sort' => ['updated' => 'DESC'], 'limit' => 200], false]],
			$this->queries
		);
		$this->assertSame([], $this->service(null)->sharedWith(uid: ''));
	}//end testSharedWithListsByKeyAndChecksTheShare()
}//end class
