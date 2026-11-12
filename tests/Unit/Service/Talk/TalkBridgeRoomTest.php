<?php

/**
 * TalkBridge's room calls: posting a message for its id, the room type and the roster.
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
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkBridge;
use OCP\Talk\IBroker;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guards, results and the logged failures of the three room calls.
 */
final class TalkBridgeRoomTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	public array $sent = [];

	/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
	private array $logged = [];

	public int $roomType = 2;

	/** @var array<int, mixed> */
	public array $participants = [];

	public bool $fail = false;

	/**
	 * The bridge over recording doubles.
	 *
	 * @param bool $backend Whether Talk has a backend.
	 *
	 * @return TalkBridge
	 */
	private function bridge(bool $backend = true): TalkBridge {
		$broker = $this->createMock(IBroker::class);
		$broker->method('hasBackend')->willReturn($backend);
		$test = $this;
		$room = new class ($test) {
			/**
			 * Keep the test.
			 *
			 * @param TalkBridgeRoomTest $test The test.
			 */
			public function __construct(private TalkBridgeRoomTest $test) {
			}

			/**
			 * The room type.
			 *
			 * @return string
			 */
			public function getType(): string {
				return (string)$this->test->roomType;
			}
		};
		$service = new class ($test, $room) {
			/**
			 * Keep the test and room.
			 *
			 * @param TalkBridgeRoomTest $test The test.
			 * @param object             $room The room.
			 */
			public function __construct(private TalkBridgeRoomTest $test, private object $room) {
			}

			/**
			 * The room for a token.
			 *
			 * @param string $token The token.
			 *
			 * @return object
			 */
			public function getRoomByToken(string $token): object {
				if ($this->test->fail === true) {
					throw new RuntimeException('no room ' . $token);
				}

				return $this->room;
			}

			/**
			 * Send a message.
			 *
			 * @param mixed ...$args The arguments.
			 *
			 * @return object
			 */
			public function sendMessage(mixed ...$args): object {
				$this->test->sent[] = $args;
				return new class {
					/**
					 * The comment id.
					 *
					 * @return int
					 */
					public function getId(): int {
						return 77;
					}
				};
			}

			/**
			 * The participants' user ids.
			 *
			 * @param object $room The room.
			 *
			 * @return array<int, mixed>
			 */
			public function getParticipantUserIds(object $room): array {
				return $this->test->participants;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, (string)$message, $context];
				}
			);
		}

		return new TalkBridge(talkBroker: $broker, container: $container, logger: $logger);
	}//end bridge()

	/**
	 * A post goes to the room as the agent's bot, now, and returns the comment id.
	 *
	 * @return void
	 */
	public function testAPostReturnsTheCommentIdAndIsSignedByTheBot(): void {
		self::assertSame('77', $this->bridge()->postToRoomReturningId(roomToken: 'room-1', message: 'Hello', agentId: 'agent-1'));

		[$room, $replyTo, $actorType, $actorId, $message, $when] = $this->sent[0];
		self::assertNull($replyTo);
		self::assertSame('bots', $actorType);
		self::assertSame('bot-' . sha1(TalkBridge::botUrlFor(agentId: 'agent-1')), $actorId);
		self::assertSame('Hello', $message);
		self::assertInstanceOf(\DateTime::class, $when);
		self::assertSame('bot-' . sha1(TalkBridge::BOT_URL), $this->bridge()->botActorId());
	}//end testAPostReturnsTheCommentIdAndIsSignedByTheBot()

	/**
	 * No Talk, no token or a blank message posts nothing; a failure is a warned null.
	 *
	 * @return void
	 */
	public function testAPostIsGuardedAndFailsAsAWarnedNull(): void {
		self::assertNull($this->bridge(backend: false)->postToRoomReturningId(roomToken: 'room-1', message: 'Hello'));
		self::assertNull($this->bridge()->postToRoomReturningId(roomToken: '', message: 'Hello'));
		self::assertNull($this->bridge()->postToRoomReturningId(roomToken: 'room-1', message: "  \n"));
		self::assertSame([], $this->sent);

		$this->fail = true;
		self::assertNull($this->bridge()->postToRoomReturningId(roomToken: 'room-1', message: 'Hello'));
		self::assertSame(['warning', '[TalkBridge] Could not post the agent answer into the room'], array_slice($this->logged[0], 0, 2));
		self::assertSame(['file', 'line', 'roomToken', 'error'], array_keys($this->logged[0][2]));
		self::assertSame(['room-1', 'no room room-1'], [$this->logged[0][2]['roomToken'], $this->logged[0][2]['error']]);
	}//end testAPostIsGuardedAndFailsAsAWarnedNull()

	/**
	 * One-to-one is room type 1 or 5; anything else, no Talk, no token or a failure is a group room.
	 *
	 * @return void
	 */
	public function testOneToOneIsTypeOneOrFive(): void {
		foreach ([1 => true, 5 => true, 2 => false, 3 => false] as $type => $expected) {
			$this->roomType = $type;
			self::assertSame($expected, $this->bridge()->isOneToOne(roomToken: 'room-1'));
		}

		self::assertFalse($this->bridge(backend: false)->isOneToOne(roomToken: 'room-1'));
		self::assertFalse($this->bridge()->isOneToOne(roomToken: ''));
		$this->fail = true;
		$this->roomType = 1;
		self::assertFalse($this->bridge()->isOneToOne(roomToken: 'room-1'));
		self::assertSame(['debug', '[TalkBridge] Could not resolve the room type; treating it as a group room'], array_slice($this->logged[0], 0, 2));
		self::assertSame(['file', 'line', 'roomToken', 'error'], array_keys($this->logged[0][2]));
	}//end testOneToOneIsTypeOneOrFive()

	/**
	 * The roster keeps each non-empty string uid once, in order.
	 *
	 * @return void
	 */
	public function testTheRosterKeepsEachUserOnce(): void {
		$this->participants = ['alice', '', 'bob', 7, 'alice', null, 'carol'];
		self::assertSame(['alice', 'bob', 'carol'], $this->bridge()->roomUserIds(roomToken: 'room-1'));

		self::assertSame([], $this->bridge(backend: false)->roomUserIds(roomToken: 'room-1'));
		self::assertSame([], $this->bridge()->roomUserIds(roomToken: ''));
		$this->fail = true;
		self::assertSame([], $this->bridge()->roomUserIds(roomToken: 'room-1'));
		self::assertSame(['debug', '[TalkBridge] Could not resolve room participants'], array_slice($this->logged[0], 0, 2));
		self::assertSame(['file', 'line', 'roomToken', 'error'], array_keys($this->logged[0][2]));
	}//end testTheRosterKeepsEachUserOnce()
}//end class
