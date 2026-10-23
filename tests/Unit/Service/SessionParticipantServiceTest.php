<?php

/**
 * SessionParticipantService (chat-work-together-in-one-session).
 *
 * The owner of a web session adds and removes colleagues; everyone else gets the
 * same answer as a missing session. The saved Session payload is validated
 * against the REAL `Session` schema fragment in lib/Settings/hermiq_register.json
 * with Opis. Real ObjectEntity; mocks of the real ObjectService, IUserManager and
 * INotificationManager interfaces.
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
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SessionParticipantException;
use OCA\Hermiq\Service\SessionParticipantService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SessionParticipantService.
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
class SessionParticipantServiceTest extends TestCase {

	/**
	 * Payloads passed to saveObject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Notifications sent.
	 *
	 * @var array<int, INotification>
	 */
	private array $sent = [];

	/**
	 * Build the service around one stored session.
	 *
	 * @param array<string, mixed>|null $payload The stored Session payload, or null for none.
	 *
	 * @return SessionParticipantService
	 */
	private function service(?array $payload): SessionParticipantService {
		$objectService = $this->createMock(ObjectService::class);
		$entity = null;
		if ($payload !== null) {
			$entity = new ObjectEntity();
			$entity->setUuid('sess-1');
			$entity->setObject($payload);
		}

		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				$saved = new ObjectEntity();
				$saved->setUuid('sess-1');
				$saved->setObject($object);
				return $saved;
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				$names = ['anne' => 'Anne de Vries', 'bram' => 'Bram Jansen'];
				if (isset($names[$uid]) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('getDisplayName')->willReturn($names[$uid]);
				return $user;
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
					$notification->method($setter)->willReturnSelf();
				}

				return $notification;
			}
		);
		$notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = $notification;
			}
		);

		return new SessionParticipantService($objectService, $users, $notifications, new NullLogger());
	}//end service()

	/**
	 * A web session owned by anne.
	 *
	 * @return array<string, mixed>
	 */
	private function annesSession(): array {
		return ['agentId' => '5f0c6a3e-1d2b-4c8e-9a7f-3b2d1e0f4a5c', 'userId' => 'anne', 'title' => 'Omgevingsvisie 2040', 'participants' => []];
	}//end annesSession()

	/**
	 * Validate a payload against the Session schema fragment.
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
	 * Remove every `$ref` that is not a JSON pointer (OpenRegister slug references), recursively.
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
	 * The owner adds a colleague: the roster holds them, the payload is valid, one notification goes out.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testTheOwnerAddsAColleague(): void {
		$list = $this->service($this->annesSession())->add(uuid: 'sess-1', callerUid: 'anne', uid: 'bram');

		$this->assertSame([['uid' => 'bram', 'displayName' => 'Bram Jansen']], $list);
		$this->assertCount(1, $this->saved);
		$this->assertSame(['bram'], $this->saved[0]['participants']);
		$this->assertSame('Omgevingsvisie 2040', $this->saved[0]['title'], 'The rest of the session survives the save.');
		$this->assertTrue($this->validSession($this->saved[0]), 'The saved payload must pass the real Session schema.');
		$this->assertCount(1, $this->sent);

	}//end testTheOwnerAddsAColleague()

	/**
	 * Adding someone already on the list changes nothing and notifies nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testAddingTwiceIsANoOp(): void {
		$session = array_merge($this->annesSession(), ['participants' => ['bram']]);
		$list = $this->service($session)->add(uuid: 'sess-1', callerUid: 'anne', uid: 'bram');

		$this->assertSame([['uid' => 'bram', 'displayName' => 'Bram Jansen']], $list);
		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->sent);

	}//end testAddingTwiceIsANoOp()

	/**
	 * The owner removes a colleague.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testTheOwnerRemovesAColleague(): void {
		$session = array_merge($this->annesSession(), ['participants' => ['bram']]);
		$list = $this->service($session)->remove(uuid: 'sess-1', callerUid: 'anne', uid: 'bram');

		$this->assertSame([], $list);
		$this->assertSame([], $this->saved[0]['participants']);
		$this->assertTrue($this->validSession($this->saved[0]));

	}//end testTheOwnerRemovesAColleague()

	/**
	 * A participant, a stranger and a missing session all get 404 and nothing is saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testSomeoneElseCannotChangeTheList(): void {
		$session = array_merge($this->annesSession(), ['participants' => ['bram']]);
		foreach ([[$session, 'bram'], [$session, 'carol'], [null, 'anne']] as [$payload, $caller]) {
			try {
				$this->service($payload)->add(uuid: 'sess-1', callerUid: $caller, uid: 'bram');
				$this->fail('Expected a refusal for ' . $caller);
			} catch (SessionParticipantException $e) {
				$this->assertSame(404, $e->getCode());
			}
		}

		$this->assertSame([], $this->saved);

	}//end testSomeoneElseCannotChangeTheList()

	/**
	 * A Talk-bound session is refused with 409; the owner or an unknown user with 400.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testTalkSessionsOwnerAndUnknownUsersAreRefused(): void {
		$talk = array_merge($this->annesSession(), ['talkRoomToken' => 'abc123']);
		$cases = [[$talk, 'bram', 409], [$this->annesSession(), 'anne', 400], [$this->annesSession(), 'nobody', 400]];
		foreach ($cases as [$payload, $uid, $status]) {
			try {
				$this->service($payload)->add(uuid: 'sess-1', callerUid: 'anne', uid: $uid);
				$this->fail('Expected a refusal for ' . $uid);
			} catch (SessionParticipantException $e) {
				$this->assertSame($status, $e->getCode());
			}
		}

		$this->assertSame([], $this->saved);

	}//end testTalkSessionsOwnerAndUnknownUsersAreRefused()

	/**
	 * The owner lists the roster with display names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
	 */
	public function testTheOwnerListsTheRoster(): void {
		$session = array_merge($this->annesSession(), ['participants' => ['bram', 'gone']]);
		$list = $this->service($session)->list(uuid: 'sess-1', callerUid: 'anne');

		$this->assertSame([['uid' => 'bram', 'displayName' => 'Bram Jansen'], ['uid' => 'gone', 'displayName' => 'gone']], $list);

	}//end testTheOwnerListsTheRoster()
}//end class
