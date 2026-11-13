<?php

declare(strict_types=1);

/**
 * TalkSessionRoom behaviour tests: creating a session's room, recording it on
 * the session object, recovering from a failed write, and renaming.
 *
 * Spreed is reached only through the container, so its services are small
 * recording doubles keyed by the class-name strings the service resolves.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\Hermiq\Service\Talk\TalkBridge;
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
 * @covers \OCA\Hermiq\Service\Talk\TalkSessionRoom
 */
class TalkSessionRoomTest extends TestCase {

	private const ROOM_SERVICE = 'OCA\\Talk\\Service\\RoomService';

	private const MANAGER = 'OCA\\Talk\\Manager';

	private TalkBridge&MockObject $bridge;

	private IUserManager&MockObject $userManager;

	private ObjectService&MockObject $objectService;

	private TalkBotInstaller&MockObject $installer;

	private TalkRoomGrouping&MockObject $grouping;

	private LoggerInterface&MockObject $logger;

	/**
	 * Container entries by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Calls the spreed doubles received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	protected function setUp(): void {
		$this->bridge = $this->createMock(TalkBridge::class);
		$this->bridge->method('isAvailable')->willReturn(true);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->installer = $this->createMock(TalkBotInstaller::class);
		$this->grouping = $this->createMock(TalkRoomGrouping::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @param bool $agentOptedIn Whether the agent has talkEnabled.
	 *
	 * @return TalkSessionRoom
	 */
	private function service(bool $agentOptedIn=true): TalkSessionRoom {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException('no service ' . $id);
				}

				return $this->services[$id];
			}
		);

		$agentBinding = $this->createMock(TalkAgentBinding::class);
		$agentBinding->method('isAgentTalkEnabled')->willReturn($agentOptedIn);

		return new TalkSessionRoom(
			$container,
			$this->userManager,
			$this->objectService,
			$this->bridge,
			$this->installer,
			$agentBinding,
			$this->grouping,
			$this->logger
		);

	}//end service()

	/**
	 * Register a room service whose created room carries `$token`.
	 *
	 * @param string $token The token of the created room.
	 *
	 * @return void
	 */
	private function roomServiceReturning(string $token): void {
		$test = $this;
		$this->services[self::ROOM_SERVICE] = new class($test, $token) {
			public function __construct(private readonly TalkSessionRoomTest $test, private readonly string $token) {
			}

			public function createConversation(int $type, string $name, IUser $owner): object {
				$this->test->calls[] = ['create', $type, $name, $owner];
				return new class($this->token) {
					public function __construct(private readonly string $token) {
					}

					public function getToken(): string {
						return $this->token;
					}
				};
			}

			public function setName(object $room, string $name): void {
				$this->test->calls[] = ['setName', $room, $name];
			}
		};

	}//end roomServiceReturning()

	/**
	 * A session object with a uuid and payload.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('session-1');
		$entity->setObject(['title' => 'Plan', 'agent' => 'agent-1']);

		return $entity;

	}//end conversation()

	public function testCreatesAGroupRoomEnablesTheBotAndFilesIt(): void {
		$owner = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())->method('get')->with('alice')->willReturn($owner);
		$this->roomServiceReturning('tok123');
		$this->installer->expects($this->once())->method('enableInRoom')->with('agent-1', 'tok123');
		$this->grouping->expects($this->once())->method('groupRoom')->with('tok123');

		$this->assertSame('tok123', $this->service()->createForSession('  Quarterly plan  ', 'alice', 'agent-1'));
		$this->assertSame([['create', 2, 'Quarterly plan', $owner]], $this->calls);

	}//end testCreatesAGroupRoomEnablesTheBotAndFilesIt()

	public function testABlankTitleGetsTheDefaultNameAndALongOneIsCut(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->roomServiceReturning('tok');
		$service = $this->service();

		$service->createForSession('   ', 'alice', 'agent-1');
		$service->createForSession(str_repeat('é', 300), 'alice', 'agent-1');

		$this->assertSame('Hermiq session', $this->calls[0][2]);
		$this->assertSame(str_repeat('é', 255), $this->calls[1][2]);

	}//end testABlankTitleGetsTheDefaultNameAndALongOneIsCut()

	public function testNoRoomWhenTalkIsUnavailable(): void {
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn(false);
		$this->bridge = $bridge;
		$this->userManager->expects($this->never())->method('get');

		$this->assertNull($this->service()->createForSession('Plan', 'alice', 'agent-1'));

	}//end testNoRoomWhenTalkIsUnavailable()

	public function testAnEmptyTokenIsNoRoomAndNothingIsEnabled(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->roomServiceReturning('');
		$this->installer->expects($this->never())->method('enableInRoom');
		$this->grouping->expects($this->never())->method('groupRoom');

		$this->assertNull($this->service()->createForSession('Plan', 'alice', 'agent-1'));

	}//end testAnEmptyTokenIsNoRoomAndNothingIsEnabled()

	public function testASpreedFailureIsLoggedAndYieldsNoRoom(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->logger->expects($this->once())->method('warning')->with(
			'[TalkSessionRoom] Could not create the session room (the session is unaffected)',
			$this->callback(
				fn (array $context): bool => $context['owner'] === 'alice' && $context['error'] === 'no service ' . self::ROOM_SERVICE
			)
		);

		$this->assertNull($this->service()->createForSession('Plan', 'alice', 'agent-1'));

	}//end testASpreedFailureIsLoggedAndYieldsNoRoom()

	public function testAttachRecordsTheCreatedRoomOnTheSession(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->roomServiceReturning('tok123');
		$saved = new ObjectEntity();
		$args = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$received) use (&$args, $saved): ObjectEntity {
				$args = $received;
				return $saved;
			}
		);
		$this->objectService->expects($this->never())->method('find');

		$this->assertSame($saved, $this->service()->attachToSession($this->conversation(), 'Plan', 'alice', 'agent-1'));
		$this->assertSame(
			[['id' => 'session-1', 'title' => 'Plan', 'agent' => 'agent-1', 'talkRoomToken' => 'tok123', 'talkRoomOrigin' => 'created'], 'hermiq', 'agentsession', 'session-1'],
			[$args[0], $args[2], $args[3], $args[4]]
		);

	}//end testAttachRecordsTheCreatedRoomOnTheSession()

	public function testAttachLeavesTheSessionAloneWithoutAnAgentOrARoom(): void {
		$conversation = $this->conversation();
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertSame($conversation, $this->service()->attachToSession($conversation, 'Plan', 'alice', ''));
		$this->assertSame($conversation, $this->service(agentOptedIn: false)->attachToSession($conversation, 'Plan', 'alice', 'agent-1'));

	}//end testAttachLeavesTheSessionAloneWithoutAnAgentOrARoom()

	public function testAFailedSaveReturnsTheStoredSessionNotTheStaleCopy(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->roomServiceReturning('tok123');
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('listener failed'));
		$fresh = new ObjectEntity();
		$this->objectService->expects($this->once())->method('find')
			->with('session-1', [], false, 'hermiq', 'agentsession')
			->willReturn($fresh);
		$this->logger->expects($this->once())->method('warning')->with(
			'[TalkSessionRoom] Could not record the room on the session',
			$this->callback(fn (array $context): bool => $context['uuid'] === 'session-1' && $context['error'] === 'listener failed')
		);

		$this->assertSame($fresh, $this->service()->attachToSession($this->conversation(), 'Plan', 'alice', 'agent-1'));

	}//end testAFailedSaveReturnsTheStoredSessionNotTheStaleCopy()

	public function testAFailedReReadFallsBackToTheCopyInHand(): void {
		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->roomServiceReturning('tok123');
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('listener failed'));
		$this->objectService->method('find')->willThrowException(new RuntimeException('gone'));
		$conversation = $this->conversation();

		$this->assertSame($conversation, $this->service()->attachToSession($conversation, 'Plan', 'alice', 'agent-1'));

	}//end testAFailedReReadFallsBackToTheCopyInHand()

	public function testRenameIfOwnedRenamesOnlyRoomsHermiqCreated(): void {
		$room = new \stdClass();
		$test = $this;
		$this->services[self::MANAGER] = new class($test, $room) {
			public function __construct(private readonly TalkSessionRoomTest $test, private readonly object $room) {
			}

			public function getRoomByToken(string $token): object {
				$this->test->calls[] = ['lookup', $token];
				return $this->room;
			}
		};
		$this->roomServiceReturning('unused');
		$service = $this->service();

		$this->assertFalse($service->renameIfOwned(['talkRoomOrigin' => 'linked', 'talkRoomToken' => 'tok', 'title' => 'New']));
		$this->assertFalse($service->renameIfOwned(['talkRoomToken' => 'tok', 'title' => 'New']));
		$this->assertSame([], $this->calls);

		$this->assertTrue($service->renameIfOwned(['talkRoomOrigin' => 'created', 'talkRoomToken' => 'tok', 'title' => ' New name ']));
		$this->assertSame([['lookup', 'tok'], ['setName', $room, 'New name']], $this->calls);

	}//end testRenameIfOwnedRenamesOnlyRoomsHermiqCreated()

	public function testRenameRefusesWithoutTalkOrAToken(): void {
		$this->assertFalse($this->service()->renameRoom('', 'New'));

		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn(false);
		$this->bridge = $bridge;
		$this->assertFalse($this->service()->renameRoom('tok', 'New'));

	}//end testRenameRefusesWithoutTalkOrAToken()

	public function testARenameFailureIsLoggedAndReportsFalse(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[TalkSessionRoom] Could not rename the session room (the session is unaffected)',
			$this->callback(fn (array $context): bool => $context['roomToken'] === 'tok' && $context['error'] === 'no service ' . self::MANAGER)
		);

		$this->assertFalse($this->service()->renameRoom('tok', 'New'));

	}//end testARenameFailureIsLoggedAndReportsFalse()
}//end class
