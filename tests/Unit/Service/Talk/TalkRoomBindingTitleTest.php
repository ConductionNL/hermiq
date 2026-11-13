<?php

/**
 * A session bound to a Talk room takes the room's name as its title, and
 * follows a rename of the room until its owner names it themselves
 * (chat-carry-a-conversation-across-channels).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-a-session-begun-in-talk-can-be-continued-on-the-chat-page-req-schan-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Listener\TalkRoomRenameListener;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The bound session's title, at binding and after a room rename.
 */
class TalkRoomBindingTitleTest extends TestCase {

	/**
	 * Every payload handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * A binding over one stored session bound to `room-1`, or none.
	 *
	 * @param array<string, mixed>|null $session The stored session's data.
	 * @param bool                      $failing Whether saving throws.
	 *
	 * @return TalkRoomBinding The binding.
	 */
	private function binding(?array $session, bool $failing = false): TalkRoomBinding {
		$this->saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			static function (mixed ...$args) use ($session): array {
				if ($session === null || ($args[0]['filters']['talkRoomToken'] ?? null) !== 'room-1') {
					return [];
				}

				$entity = new ObjectEntity();
				$entity->setUuid('sess-1');
				$entity->setObject($session);
				return [$entity];
			}
		);
		// Variadic: the caller uses named arguments (see TalkRoomBindingSyncTest).
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($failing): ObjectEntity {
				if ($failing === true) {
					throw new RuntimeException('register down');
				}

				$this->saved[] = $args[0];
				$entity = new ObjectEntity();
				$entity->setUuid('sess-1');
				$entity->setObject($args[0]);
				return $entity;
			}
		);

		return new TalkRoomBinding($objectService, new NullLogger());
	}//end binding()

	public function testABoundSessionIsNamedAfterItsRoom(): void {
		$created = $this->binding(null)->createBound(roomToken: 'room-1', agentId: 'agent-1', ownerUid: 'anne.devries', roomName: '  Inkoop overleg ');

		$this->assertSame('Inkoop overleg', $created?->getObject()['title']);
		$this->assertSame('Inkoop overleg', $this->saved[0]['title']);
	}//end testABoundSessionIsNamedAfterItsRoom()

	public function testARoomWithoutAKnownNameKeepsTheDefaultTitle(): void {
		$this->binding(null)->createBound(roomToken: 'room-1', agentId: 'agent-1', ownerUid: 'anne.devries');
		$this->assertSame(TalkRoomBinding::DEFAULT_BOUND_TITLE, $this->saved[0]['title']);

		$this->binding(null)->createBound(roomToken: 'room-1', agentId: 'agent-1', ownerUid: 'anne.devries', roomName: '   ');
		$this->assertSame('Talk conversation', $this->saved[0]['title']);
	}//end testARoomWithoutAKnownNameKeepsTheDefaultTitle()

	public function testARenamedRoomRenamesASessionStillCarryingTheRoomsName(): void {
		$session = ['title' => 'Inkoop overleg', 'userId' => 'anne.devries', 'talkRoomToken' => 'room-1', 'talkRoomOrigin' => 'bound', 'participants' => ['bram']];

		$this->assertTrue($this->binding($session)->followRoomRename('room-1', 'Inkoop overleg', 'Inkoop en aanbesteding'));
		$this->assertCount(1, $this->saved);
		$this->assertSame('Inkoop en aanbesteding', $this->saved[0]['title']);
		$this->assertSame(['bram'], $this->saved[0]['participants'], 'Every other field is carried forward.');

		// A session bound before this change still carries the default title, and follows too.
		$this->assertTrue($this->binding(['title' => 'Talk conversation'] + $session)->followRoomRename('room-1', 'Inkoop overleg', 'Inkoop'));
	}//end testARenamedRoomRenamesASessionStillCarryingTheRoomsName()

	public function testTheOwnersOwnTitleIsNeverOverwritten(): void {
		$session = ['title' => 'Offertes Q4', 'talkRoomToken' => 'room-1', 'talkRoomOrigin' => 'bound'];

		$this->assertFalse($this->binding($session)->followRoomRename('room-1', 'Inkoop overleg', 'Inkoop en aanbesteding'));
		$this->assertSame([], $this->saved);
	}//end testTheOwnersOwnTitleIsNeverOverwritten()

	public function testARoomHermiqCreatedDoesNotRenameItsSession(): void {
		$session = ['title' => 'Inkoop overleg', 'talkRoomToken' => 'room-1', 'talkRoomOrigin' => 'created'];

		$this->assertFalse($this->binding($session)->followRoomRename('room-1', 'Inkoop overleg', 'Iets anders'));
		$this->assertSame([], $this->saved);
	}//end testARoomHermiqCreatedDoesNotRenameItsSession()

	public function testNothingHappensForAnUnboundRoomABlankNameOrAFailedSave(): void {
		$session = ['title' => 'Inkoop overleg', 'talkRoomToken' => 'room-1'];

		$this->assertFalse($this->binding($session)->followRoomRename('room-2', 'Inkoop overleg', 'Nieuw'));
		$this->assertFalse($this->binding($session)->followRoomRename('room-1', 'Inkoop overleg', '  '));
		$this->assertFalse($this->binding($session)->followRoomRename('room-1', 'Inkoop overleg', 'Inkoop overleg'));
		$this->assertSame([], $this->saved);
		$this->assertFalse($this->binding($session, failing: true)->followRoomRename('room-1', 'Inkoop overleg', 'Nieuw'));
	}//end testNothingHappensForAnUnboundRoomABlankNameOrAFailedSave()

	public function testTheListenerFollowsOnlyARename(): void {
		$session = ['title' => 'Inkoop overleg', 'talkRoomToken' => 'room-1', 'talkRoomOrigin' => 'bound'];
		$listener = new TalkRoomRenameListener($this->binding($session));

		$listener->handle(self::modified(property: 'description', old: 'a', new: 'b'));
		$this->assertSame([], $this->saved, 'Only a rename is followed.');

		$listener->handle(new Event());
		$this->assertSame([], $this->saved, 'An event without the getters is ignored.');

		$listener->handle(self::modified(property: 'name', old: 'Inkoop overleg', new: 'Inkoop en aanbesteding'));
		$this->assertSame('Inkoop en aanbesteding', $this->saved[0]['title'] ?? null);
	}//end testTheListenerFollowsOnlyARename()

	public function testTheListenerIsRegisteredForTalksRoomModifiedEvent(): void {
		$this->assertSame('OCA\\Talk\\Events\\RoomModifiedEvent', TalkRoomRenameListener::EVENT_ROOM_MODIFIED);
		$this->assertStringContainsString(
			'TalkRoomRenameListener::class',
			(string)file_get_contents(__DIR__ . '/../../../../lib/Listener/ListenerRegistrar.php')
		);
	}//end testTheListenerIsRegisteredForTalksRoomModifiedEvent()

	/**
	 * A stand-in for Talk's RoomModifiedEvent on room `room-1`.
	 *
	 * @param string $property The modified property.
	 * @param string $old      The old value.
	 * @param string $new      The new value.
	 *
	 * @return Event The event.
	 */
	private static function modified(string $property, string $old, string $new): Event {
		$room = new class {
			public function getToken(): string {
				return 'room-1';
			}
		};

		return new class($property, $old, $new, $room) extends Event {
			public function __construct(private string $property, private string $old, private string $new, private object $room) {
				parent::__construct();
			}

			public function getProperty(): string {
				return $this->property;
			}

			public function getOldValue(): string {
				return $this->old;
			}

			public function getNewValue(): string {
				return $this->new;
			}

			public function getRoom(): object {
				return $this->room;
			}
		};
	}//end modified()
}//end class
