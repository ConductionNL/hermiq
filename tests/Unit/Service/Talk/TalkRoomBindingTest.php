<?php

/**
 * TalkRoomBinding tests (talk-chat-bridge): binding a session to a room,
 * opening a session for a room, and reading a room's origin.
 *
 * `TalkRoomBindingSyncTest` covers the roster sync and the room lookup. This
 * covers the writes around them. Every one of them is PUT-semantic, so each
 * is asserted to carry the whole stored payload forward; and every one of
 * them runs on the delivery path, so each failure is asserted to degrade to
 * "not bound" rather than to an error that would drop the message.
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
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-a-room-message-becomes-a-turn-on-the-bound-session-and-is-answered-in-the-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Binding, opening and origin reads.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkRoomBinding
 */
class TalkRoomBindingTest extends TestCase {

	private ObjectService&MockObject $objects;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every saveObject() call, as [payload, register, schema, uuid].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saves = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The binding under test.
	 *
	 * @return TalkRoomBinding
	 */
	private function binding(): TalkRoomBinding {
		return new TalkRoomBinding($this->objects, $this->logger);
	}//end binding()

	/**
	 * Record every save and answer with a stored entity carrying the payload.
	 *
	 * @return void
	 */
	private function recordSaves(): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saves[] = [$object, $register, $schema, $uuid];

				return $this->session((array)$object, $uuid ?? 'new-uuid');
			}
		);

	}//end recordSaves()

	/**
	 * Binding writes the token onto the session and carries every other
	 * stored field forward, under the session's own uuid.
	 *
	 * @return void
	 */
	public function testBindingWritesTheTokenAndCarriesThePayloadForward(): void {
		$this->recordSaves();

		$bound = $this->binding()->bind($this->session(['title' => 'Report', 'userId' => 'alice']), 'room-1');

		$this->assertTrue($bound);
		$this->assertCount(1, $this->saves);
		$this->assertSame('room-1', $this->saves[0][0]['talkRoomToken']);
		$this->assertSame('Report', $this->saves[0][0]['title'], 'PUT-semantic: the title survives');
		$this->assertSame('alice', $this->saves[0][0]['userId']);
		$this->assertSame(['hermiq', 'agentsession', 'conv-1'], array_slice($this->saves[0], 1));

	}//end testBindingWritesTheTokenAndCarriesThePayloadForward()

	/**
	 * A session already bound to that room is not written again.
	 *
	 * @return void
	 */
	public function testAnAlreadyBoundSessionIsNotWrittenAgain(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertTrue($this->binding()->bind($this->session(['talkRoomToken' => 'room-1']), 'room-1'));

	}//end testAnAlreadyBoundSessionIsNotWrittenAgain()

	/**
	 * An empty token binds nothing; a failed save is logged and reported as
	 * not bound — delivery itself is unaffected.
	 *
	 * @return void
	 */
	public function testAnEmptyTokenOrAFailedSaveIsNotBound(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Could not bind the conversation to the room'),
			$this->callback(static fn (array $ctx): bool => $ctx['roomToken'] === 'room-1' && $ctx['conversation'] === 'conv-1' && $ctx['error'] === 'store down')
		);

		$binding = $this->binding();
		$this->assertFalse($binding->bind($this->session([]), ''));
		$this->assertFalse($binding->bind($this->session([]), 'room-1'));

	}//end testAnEmptyTokenOrAFailedSaveIsNotBound()

	/**
	 * Binding by uuid loads the session from the conversation schema and binds
	 * it.
	 *
	 * @return void
	 */
	public function testBindingByUuidLoadsTheSessionThenBindsIt(): void {
		$this->objects->expects($this->once())->method('find')
			->with('conv-1', $this->anything(), $this->anything(), 'hermiq', 'agentsession')
			->willReturn($this->session(['title' => 'Report']));
		$this->recordSaves();

		$this->assertTrue($this->binding()->bindByUuid('conv-1', 'room-1'));
		$this->assertSame('room-1', $this->saves[0][0]['talkRoomToken']);

	}//end testBindingByUuidLoadsTheSessionThenBindsIt()

	/**
	 * A missing uuid or token, a session that is not found, and a load that
	 * throws are all "not bound" — and only the throw is worth a warning.
	 *
	 * @return void
	 */
	public function testBindingByUuidThatCannotLoadIsNotBound(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$binding = $this->binding();

		$this->assertFalse($binding->bindByUuid('', 'room-1'));
		$this->assertFalse($binding->bindByUuid('conv-1', ''));

		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new RuntimeException('store down'))
		);
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Could not load the conversation to bind'),
			$this->callback(static fn (array $ctx): bool => $ctx['conversation'] === 'conv-1')
		);

		$this->assertFalse($binding->bindByUuid('conv-1', 'room-1'), 'not found');
		$this->assertFalse($binding->bindByUuid('conv-1', 'room-1'), 'load threw');

	}//end testBindingByUuidThatCannotLoadIsNotBound()

	/**
	 * Opening a session for a room stores it bound to that room, owned by the
	 * owner, with the roster minus the owner, blanks and non-strings — and
	 * with no uuid, so it is a new row.
	 *
	 * @return void
	 */
	public function testOpeningASessionForARoomStoresItBoundWithTheRoster(): void {
		$this->recordSaves();
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('Opened a conversation bound to a Talk room'),
			$this->callback(static fn (array $ctx): bool => $ctx['participants'] === 2 && $ctx['roomToken'] === 'room-1')
		);

		$created = $this->binding()->createBound('room-1', 'agent-1', 'alice', ['alice', 'bob', '', 42, 'carol']);

		$this->assertInstanceOf(ObjectEntity::class, $created);
		$this->assertSame(
			[
				'title'         => 'Talk conversation',
				'userId'        => 'alice',
				'agentId'       => 'agent-1',
				'talkRoomToken' => 'room-1',
				'participants'  => ['bob', 'carol'],
			],
			$this->saves[0][0]
		);
		$this->assertSame(['hermiq', 'agentsession', null], array_slice($this->saves[0], 1));

	}//end testOpeningASessionForARoomStoresItBoundWithTheRoster()

	/**
	 * A failed open is an error in the log and no session — the caller then
	 * has nothing to deliver to, which it handles.
	 *
	 * @return void
	 */
	public function testAFailedOpenIsLoggedAsAnErrorAndYieldsNoSession(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('schema missing'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('Could not open a conversation for the room'),
			$this->callback(static fn (array $ctx): bool => $ctx['error'] === 'schema missing')
		);

		$this->assertNull($this->binding()->createBound('room-1', 'agent-1', 'alice'));

	}//end testAFailedOpenIsLoggedAsAnErrorAndYieldsNoSession()

	/**
	 * A room is "created" only when the session bound to it says so; a bound
	 * room, a session with no origin, and a room with no session are not —
	 * this is what keeps the agent behind the mention gate in other people's
	 * rooms.
	 *
	 * @return void
	 */
	public function testOnlyARoomWhoseSessionSaysCreatedIsACreatedRoom(): void {
		$answers = [
			[$this->session(['talkRoomOrigin' => TalkRoomBinding::ORIGIN_CREATED])],
			[$this->session(['talkRoomOrigin' => TalkRoomBinding::ORIGIN_BOUND])],
			[$this->session([])],
			[],
		];
		$this->objects->method('findAll')->willReturnCallback(
			static function () use (&$answers): array {
				return array_shift($answers);
			}
		);
		$binding = $this->binding();

		$this->assertTrue($binding->isCreatedRoom('room-1'), 'created');
		$this->assertFalse($binding->isCreatedRoom('room-1'), 'bound');
		$this->assertFalse($binding->isCreatedRoom('room-1'), 'no origin');
		$this->assertFalse($binding->isCreatedRoom('room-1'), 'no session');

	}//end testOnlyARoomWhoseSessionSaysCreatedIsACreatedRoom()

	/**
	 * A stored session.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string               $uuid    The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function session(array $payload, string $uuid = 'conv-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($payload);

		return $entity;

	}//end session()

}//end class
