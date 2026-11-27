<?php

/**
 * Unit tests for TalkChainRoom: the target's session, the hand-off and the chain-ended notice.
 *
 * @category Test
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Kernel\Contracts\TalkRoomBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnDispatcherInterface;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkChainRoom;
use OCA\Hermiq\Service\Talk\TalkTurn;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for {@see TalkChainRoom}.
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkChainRoomTest extends TestCase {

	/**
	 * The room binding.
	 *
	 * @var TalkRoomBindingInterface&MockObject
	 */
	private TalkRoomBindingInterface&MockObject $rooms;

	/**
	 * The Talk bridge.
	 *
	 * @var TalkBridge&MockObject
	 */
	private TalkBridge&MockObject $bridge;

	/**
	 * The dispatcher the container resolves.
	 *
	 * @var TalkTurnDispatcherInterface&MockObject
	 */
	private TalkTurnDispatcherInterface&MockObject $dispatcher;

	/**
	 * The room under test.
	 *
	 * @var TalkChainRoom
	 */
	private TalkChainRoom $room;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rooms = $this->createMock(TalkRoomBindingInterface::class);
		$this->bridge = $this->createMock(TalkBridge::class);
		$this->dispatcher = $this->createMock(TalkTurnDispatcherInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(TalkTurnDispatcherInterface::class)->willReturn($this->dispatcher);

		$this->room = new TalkChainRoom($this->rooms, new ConversationParticipation(), $this->bridge, $container);
	}//end setUp()

	/**
	 * A session owned by the given user, with the given participants.
	 *
	 * @param string             $owner        The owner.
	 * @param array<int, string> $participants The participants.
	 *
	 * @return ObjectEntity
	 */
	private static function session(string $owner, array $participants): ObjectEntity {
		$session = new ObjectEntity();
		$session->setUuid('session-1');
		$session->setObject(['userId' => $owner, 'participants' => $participants]);

		return $session;
	}//end session()

	/**
	 * An existing session is synced with the room's roster and returned.
	 *
	 * @return void
	 */
	public function testAnExistingSessionIsSyncedWithTheRoom(): void {
		$this->bridge->method('roomUserIds')->willReturn(['alice', 'bob']);
		$this->rooms->method('findByRoomAndAgent')->willReturn(self::session('alice', []));
		$this->rooms->expects($this->once())->method('syncParticipants')
			->with($this->isInstanceOf(ObjectEntity::class), ['alice', 'bob'])
			->willReturn(self::session('alice', ['bob']));
		$this->rooms->expects($this->never())->method('createBound');

		$this->assertSame('session-1', (string)$this->room->sessionFor(roomToken: 'room-1', agentId: 'agent-b', rootUid: 'bob')?->getUuid());
	}//end testAnExistingSessionIsSyncedWithTheRoom()

	/**
	 * Without a session, one is opened for the rooting human.
	 *
	 * @return void
	 */
	public function testAMissingSessionIsOpenedForTheRootingHuman(): void {
		$this->bridge->method('roomUserIds')->willReturn(['alice']);
		$this->rooms->method('findByRoomAndAgent')->willReturn(null);
		$this->rooms->expects($this->once())->method('createBound')
			->with('room-1', 'agent-b', 'alice', ['alice'])
			->willReturn(self::session('alice', []));

		$this->assertNotNull($this->room->sessionFor(roomToken: 'room-1', agentId: 'agent-b', rootUid: 'alice'));
	}//end testAMissingSessionIsOpenedForTheRootingHuman()

	/**
	 * A rooting human who left the room, or who is neither owner nor participant, gets no session.
	 *
	 * @return void
	 */
	public function testARootingHumanWhoLeftFailsClosed(): void {
		$this->bridge->method('roomUserIds')->willReturn(['alice']);
		$this->rooms->method('findByRoomAndAgent')->willReturn(self::session('alice', ['carol']));
		$this->rooms->method('syncParticipants')->willReturnArgument(0);

		$this->assertNull($this->room->sessionFor(roomToken: 'room-1', agentId: 'agent-b', rootUid: 'carol'), 'Carol left the room.');
		$this->assertNull($this->room->sessionFor(roomToken: 'room-1', agentId: 'agent-b', rootUid: 'dave'), 'Dave is not in the room.');
	}//end testARootingHumanWhoLeftFailsClosed()

	/**
	 * A chained turn goes to the dispatcher the container resolves, unchanged.
	 *
	 * @return void
	 */
	public function testAChainedTurnIsHandedToTheDispatcher(): void {
		$turn = new TalkTurn(conversationUuid: 'session-1', speakerUid: 'alice');
		$this->dispatcher->expects($this->once())->method('dispatch')->with($this->identicalTo($turn))->willReturn('instant');

		$this->assertSame('instant', $this->room->dispatch(turn: $turn));
	}//end testAChainedTurnIsHandedToTheDispatcher()

	/**
	 * The chain-ended notice is posted under the agent's bot.
	 *
	 * @return void
	 */
	public function testANoticeIsPostedUnderTheAgentsBot(): void {
		$this->bridge->expects($this->once())->method('postToRoom')->with('room-1', 'Ended.', 'agent-a')->willReturn(true);

		$this->room->postNotice(roomToken: 'room-1', notice: 'Ended.', agentId: 'agent-a');
	}//end testANoticeIsPostedUnderTheAgentsBot()
}//end class
