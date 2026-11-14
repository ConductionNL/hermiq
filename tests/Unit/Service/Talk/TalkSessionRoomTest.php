<?php

/**
 * TalkSessionRoom tests (talk-agent-sessions): creating, recording and
 * renaming a session's own Talk room.
 *
 * `TalkSessionRoomOptInTest` pins the agent's opt-in guard. This covers
 * everything after it: the room that is actually made, the one write that
 * marks it `created` (the only thing that lets the agent answer unaddressed
 * messages there), the re-read after a save whose listeners threw, and the
 * ownership test that keeps a rename from rewriting somebody else's room.
 *
 * Every Talk failure must leave the session itself untouched, so each of
 * those paths is asserted to degrade to "no room" rather than to an error.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-renaming-a-session-renames-its-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Room creation, recording and owned-only renaming.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkSessionRoom
 */
class TalkSessionRoomTest extends TestCase {

	/**
	 * The Talk room service double: records every created room and rename.
	 *
	 * @var object
	 */
	private object $roomService;

	/**
	 * The Talk manager double: hands back a room per token.
	 *
	 * @var object
	 */
	private object $manager;

	/**
	 * Whether the bridge reports Talk as available.
	 *
	 * @var boolean
	 */
	private bool $available = true;

	/**
	 * The owner the user manager resolves, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $owner = null;

	private ContainerInterface&MockObject $container;

	private ObjectService&MockObject $objects;

	private TalkBotInstaller&MockObject $installer;

	private TalkRoomGrouping&MockObject $grouping;

	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->owner = $this->createMock(IUser::class);

		$this->roomService = new class {

			/**
			 * Every createConversation() call: [type, name, owner].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $created = [];

			/**
			 * Every setName() call: [room, name].
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $renamed = [];

			/**
			 * The token the next created room carries.
			 *
			 * @var string
			 */
			public string $token = 'room-token-1';

			/**
			 * @param int    $type  The room type.
			 * @param string $name  The room name.
			 * @param mixed  $owner The owner.
			 *
			 * @return object
			 */
			public function createConversation(int $type, string $name, mixed $owner): object {
				$this->created[] = [$type, $name, $owner];
				$token = $this->token;

				return new class($token) {

					/**
					 * @param string $token The room token.
					 */
					public function __construct(private readonly string $token) {
					}//end __construct()

					/**
					 * @return string
					 */
					public function getToken(): string {
						return $this->token;
					}//end getToken()

				};
			}//end createConversation()

			/**
			 * @param mixed  $room The room.
			 * @param string $name The new name.
			 *
			 * @return void
			 */
			public function setName(mixed $room, string $name): void {
				$this->renamed[] = [$room, $name];
			}//end setName()

		};

		$this->manager = new class {

			/**
			 * @param string $token The room token.
			 *
			 * @return object
			 */
			public function getRoomByToken(string $token): object {
				return (object)['token' => $token];
			}//end getRoomByToken()

		};

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				return match ($id) {
					'OCA\\Talk\\Service\\RoomService' => $this->roomService,
					'OCA\\Talk\\Manager' => $this->manager,
					default => throw new RuntimeException('unexpected service ' . $id),
				};
			}
		);

		$this->objects   = $this->createMock(ObjectService::class);
		$this->installer = $this->createMock(TalkBotInstaller::class);
		$this->grouping  = $this->createMock(TalkRoomGrouping::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The service under test, over the current doubles.
	 *
	 * @return TalkSessionRoom
	 */
	private function service(): TalkSessionRoom {
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn($this->available);

		$agents = $this->createMock(TalkAgentBinding::class);
		$agents->method('isAgentTalkEnabled')->willReturn(true);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->owner);

		return new TalkSessionRoom(
			$this->container,
			$users,
			$this->objects,
			$bridge,
			$this->installer,
			$agents,
			$this->grouping,
			$this->logger
		);

	}//end service()

	/**
	 * A session for an opted-in agent gets a GROUP room named after the
	 * session and owned by its owner; the agent's bot is enabled in that room
	 * and the room is filed under the owner's Hermiq group.
	 *
	 * @return void
	 */
	public function testARoomIsCreatedNamedAfterTheSessionAndTheBotEnabledInIt(): void {
		$this->installer->expects($this->once())->method('enableInRoom')->with('agent-1', 'room-token-1');
		$this->grouping->expects($this->once())->method('groupRoom')->with('room-token-1');

		$token = $this->service()->createForSession('  Quarterly report  ', 'alice', 'agent-1');

		$this->assertSame('room-token-1', $token);
		$this->assertSame([[2, 'Quarterly report', $this->owner]], $this->roomService->created, 'a group room, trimmed name, the owner as creator');

	}//end testARoomIsCreatedNamedAfterTheSessionAndTheBotEnabledInIt()

	/**
	 * An untitled session's room is still named — an empty name renders as a
	 * participant list, which for a session room reads as "you and a bot" —
	 * and an over-long title is cut to the bounded column.
	 *
	 * @return void
	 */
	public function testTheRoomNameIsNeverEmptyAndNeverOverLong(): void {
		$service = $this->service();

		$service->createForSession('   ', 'alice', 'agent-1');
		$service->createForSession(str_repeat('é', 300), 'alice', 'agent-1');

		$this->assertSame('Hermiq session', $this->roomService->created[0][1]);
		$this->assertSame(str_repeat('é', 255), $this->roomService->created[1][1], 'cut by characters, not bytes');

	}//end testTheRoomNameIsNeverEmptyAndNeverOverLong()

	/**
	 * With Talk unavailable nothing is asked of Talk at all.
	 *
	 * @return void
	 */
	public function testWithoutTalkNoRoomIsAttempted(): void {
		$this->available = false;
		$this->container->expects($this->never())->method('get');

		$this->assertNull($this->service()->createForSession('A session', 'alice', 'agent-1'));

	}//end testWithoutTalkNoRoomIsAttempted()

	/**
	 * An owner who cannot be resolved gets no room, rather than a room owned
	 * by nobody.
	 *
	 * @return void
	 */
	public function testAnUnresolvableOwnerGetsNoRoom(): void {
		$this->owner = null;

		$this->assertNull($this->service()->createForSession('A session', 'ghost', 'agent-1'));
		$this->assertSame([], $this->roomService->created);

	}//end testAnUnresolvableOwnerGetsNoRoom()

	/**
	 * A room that comes back without a token is no room: its bot is never
	 * enabled and it is never grouped.
	 *
	 * @return void
	 */
	public function testARoomWithoutATokenIsNoRoom(): void {
		$this->roomService->token = '';
		$this->installer->expects($this->never())->method('enableInRoom');
		$this->grouping->expects($this->never())->method('groupRoom');

		$this->assertNull($this->service()->createForSession('A session', 'alice', 'agent-1'));

	}//end testARoomWithoutATokenIsNoRoom()

	/**
	 * A Talk failure while creating the room is logged and yields no room —
	 * the session is unaffected.
	 *
	 * @return void
	 */
	public function testATalkFailureIsLoggedAndYieldsNoRoom(): void {
		$this->installer->method('enableInRoom')->willThrowException(new RuntimeException('bot gone'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Could not create the session room'),
			$this->callback(static fn (array $ctx): bool => $ctx['error'] === 'bot gone' && $ctx['owner'] === 'alice')
		);

		$this->assertNull($this->service()->createForSession('A session', 'alice', 'agent-1'));

	}//end testATalkFailureIsLoggedAndYieldsNoRoom()

	/**
	 * Attaching records the room on the session with origin `created` — the
	 * one write that lets the agent answer unaddressed messages there — and
	 * carries the whole payload forward, since the save is PUT-semantic.
	 *
	 * @return void
	 */
	public function testAttachingRecordsTheRoomAsCreatedAndKeepsThePayload(): void {
		$session = $this->session(['title' => 'A session', 'agent' => 'agent-1']);
		$saved   = $this->session(['saved' => true]);

		$this->objects->expects($this->once())->method('saveObject')
			->with(
				[
					'id'             => 'session-uuid',
					'title'          => 'A session',
					'agent'          => 'agent-1',
					'talkRoomToken'  => 'room-token-1',
					'talkRoomOrigin' => TalkRoomBinding::ORIGIN_CREATED,
				],
				$this->anything(),
				'hermiq',
				'agentsession',
				'session-uuid'
			)
			->willReturn($saved);

		$this->assertSame($saved, $this->service()->attachToSession($session, 'A session', 'alice', 'agent-1'));

	}//end testAttachingRecordsTheRoomAsCreatedAndKeepsThePayload()

	/**
	 * A session without an agent, or one for which no room could be made, is
	 * returned as it came — a session with no room is a pre-Talk session.
	 *
	 * @return void
	 */
	public function testWithoutARoomTheSessionIsReturnedUnchanged(): void {
		$session = $this->session(['title' => 'A session']);
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame($session, $this->service()->attachToSession($session, 'A session', 'alice', ''));

		$this->owner = null;
		$this->assertSame($session, $this->service()->attachToSession($session, 'A session', 'alice', 'agent-1'));

	}//end testWithoutARoomTheSessionIsReturnedUnchanged()

	/**
	 * When the save throws — possibly from another app's listener AFTER the
	 * row was written — the stored session is re-read rather than handing
	 * back the stale copy, so the client sees the room the store has.
	 *
	 * @return void
	 */
	public function testAFailedSaveReReadsTheStoredSession(): void {
		$session = $this->session(['title' => 'A session']);
		$stored  = $this->session(['title' => 'A session', 'talkRoomToken' => 'room-token-1']);

		$this->objects->method('saveObject')->willThrowException(new RuntimeException('a listener raised'));
		$this->objects->expects($this->once())->method('find')
			->with('session-uuid', $this->anything(), $this->anything(), 'hermiq', 'agentsession')
			->willReturn($stored);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not record the room on the session'));

		$this->assertSame($stored, $this->service()->attachToSession($session, 'A session', 'alice', 'agent-1'));

	}//end testAFailedSaveReReadsTheStoredSession()

	/**
	 * When the re-read fails as well, or finds nothing, the copy in hand is
	 * still a usable session and is returned.
	 *
	 * @return void
	 */
	public function testAFailedReReadFallsBackToTheCopyInHand(): void {
		$session = $this->session(['title' => 'A session']);

		$this->objects->method('saveObject')->willThrowException(new RuntimeException('save failed'));
		$this->objects->method('find')->willThrowException(new RuntimeException('read failed'));

		$this->assertSame($session, $this->service()->attachToSession($session, 'A session', 'alice', 'agent-1'));

	}//end testAFailedReReadFallsBackToTheCopyInHand()

	/**
	 * A room the session CREATED is renamed with it, under the same naming
	 * rule as at creation.
	 *
	 * @return void
	 */
	public function testAnOwnedRoomIsRenamedWithItsSession(): void {
		$renamed = $this->service()->renameIfOwned(
			[
				'talkRoomOrigin' => TalkRoomBinding::ORIGIN_CREATED,
				'talkRoomToken'  => 'room-token-1',
				'title'          => ' New title ',
			]
		);

		$this->assertTrue($renamed);
		$this->assertCount(1, $this->roomService->renamed);
		$this->assertSame('room-token-1', $this->roomService->renamed[0][0]->token, 'the room looked up by its token');
		$this->assertSame('New title', $this->roomService->renamed[0][1]);

	}//end testAnOwnedRoomIsRenamedWithItsSession()

	/**
	 * A room the session was merely BOUND to — somebody else's room — is
	 * never renamed, and neither is one with no recorded origin.
	 *
	 * @return void
	 */
	public function testABoundRoomIsNeverRenamed(): void {
		$this->container->expects($this->never())->method('get');
		$service = $this->service();

		$this->assertFalse($service->renameIfOwned(['talkRoomOrigin' => TalkRoomBinding::ORIGIN_BOUND, 'talkRoomToken' => 'team-room', 'title' => 'Mine now']));
		$this->assertFalse($service->renameIfOwned(['talkRoomToken' => 'team-room', 'title' => 'Mine now']));

	}//end testABoundRoomIsNeverRenamed()

	/**
	 * A rename without Talk or without a token does nothing; a Talk failure
	 * during it is logged and reported as not renamed.
	 *
	 * @return void
	 */
	public function testARenameThatCannotHappenReportsFalse(): void {
		$this->assertFalse($this->service()->renameRoom('', 'Title'), 'no token');

		$this->available = false;
		$this->assertFalse($this->service()->renameRoom('room-token-1', 'Title'), 'no Talk');

		$this->available = true;
		$this->manager = new class {

			/**
			 * @param string $token The room token.
			 *
			 * @return object
			 *
			 * @throws RuntimeException Always.
			 */
			public function getRoomByToken(string $token): object {
				throw new RuntimeException('room deleted');
			}//end getRoomByToken()

		};
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Could not rename the session room'),
			$this->callback(static fn (array $ctx): bool => $ctx['roomToken'] === 'room-token-1')
		);

		$this->assertFalse($this->service()->renameRoom('room-token-1', 'Title'));
		$this->assertSame([], $this->roomService->renamed);

	}//end testARenameThatCannotHappenReportsFalse()

	/**
	 * A stored session with the given payload.
	 *
	 * @param array<string, mixed> $payload The session payload.
	 *
	 * @return ObjectEntity
	 */
	private function session(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('session-uuid');
		$entity->setObject($payload);

		return $entity;

	}//end session()

}//end class
