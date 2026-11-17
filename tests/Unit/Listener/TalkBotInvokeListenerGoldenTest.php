<?php

/**
 * Golden tests for the inbound Talk bridge listener's addressing, binding and
 * hand-off.
 *
 * Pins, call for call: when a group-room message counts as addressed (a room hermiq
 * created, a one-to-one room, the agent's name or the shared bot name in the text or
 * the mention parameters, a reply to the per-agent or the shared bot), how the room
 * is bound to a conversation (an existing one re-grouped and its roster synced
 * first, a new one created with the room's users and grouped, a failed create
 * taking no turn), and the hand-off and its log line.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\TalkBotInvokeListener;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the Talk invoke listener.
 *
 * @spec openspec/specs/talk-chat-bridge/spec.md#requirement-the-agent-responds-only-when-addressed-in-a-group-room
 */
class TalkBotInvokeListenerGoldenTest extends TestCase {

	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * An invocation event carrying the given note object.
	 *
	 * @param array $object The note object (content, parameters, inReplyTo).
	 *
	 * @return Event
	 */
	private function event(array $object): Event {
		return new class($object) extends Event {
			/**
			 * Reactions added.
			 *
			 * @var array<int, string>
			 */
			public array $reactions = [];

			/**
			 * @param array $object The note object.
			 */
			public function __construct(
				private readonly array $object,
			) {
			}

			public function getBotUrl(): string {
				return TalkBridge::BOT_URL;
			}

			public function getMessage(): array {
				return [
					'type' => 'Create',
					'actor' => ['id' => 'users/alice'],
					'object' => $this->object,
					'target' => ['id' => 'room-9'],
				];
			}

			public function addReaction(string $emoji): void {
				$this->reactions[] = $emoji;
			}
		};
	}//end event()

	/**
	 * Run the listener in a group room bound to agent `ag-1` named `Ada`.
	 *
	 * @param array $object The note object.
	 * @param array $room How the room answers: created, oneToOne, existing, createFails.
	 *
	 * @return array<int, string>
	 */
	private function invoke(array $object, array $room = []): array {
		$this->log = [];
		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['userId' => 'alice', 'participants' => []]);

		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn(true);
		$bridge->method('isOneToOne')->willReturn($room['oneToOne'] ?? false);
		$bridge->method('roomUserIds')->willReturnCallback(
			function (string $roomToken): array {
				$this->log[] = 'roomUserIds ' . $roomToken;
				return ['alice', 'bob'];
			}
		);
		$bridge->method('botActorId')->willReturnCallback(static fn (?string $agentId = null): string => 'bots/' . ($agentId ?? 'shared'));

		$binding = $this->createMock(TalkRoomBinding::class);
		$binding->method('isCreatedRoom')->willReturn($room['created'] ?? false);
		$binding->method('findByRoomToken')->willReturn(($room['existing'] ?? true) === true ? $conversation : null);
		$binding->method('syncParticipants')->willReturnCallback(
			function (ObjectEntity $conv, array $participants): ObjectEntity {
				$this->log[] = 'sync ' . implode(',', $participants);
				return $conv;
			}
		);
		$binding->method('createBound')->willReturnCallback(
			function (string $roomToken, string $agentId, string $ownerUid, array $participants) use ($conversation, $room): ?ObjectEntity {
				$this->log[] = 'create ' . $roomToken . ' ' . $agentId . ' ' . $ownerUid . ' ' . implode(',', $participants);
				return ($room['createFails'] ?? false) === true ? null : $conversation;
			}
		);

		$agents = $this->createMock(TalkAgentBinding::class);
		$agents->method('agentForRoom')->willReturn('ag-1');
		$agents->method('agentName')->willReturn('Ada');

		$dispatcher = $this->createMock(TalkTurnDispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (string $conversationUuid, string $speakerUid, string $message, string $roomToken): string {
				$this->log[] = 'dispatch ' . $conversationUuid . ' ' . $speakerUid . ' ' . $message . ' ' . $roomToken;
				return 'queued';
			}
		);

		$grouping = $this->createMock(TalkRoomGrouping::class);
		$grouping->method('groupRoom')->willReturnCallback(
			function (string $roomToken): int {
				$this->log[] = 'group ' . $roomToken;
				return 1;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->log[] = 'info ' . $message . ' ' . json_encode($context);
			}
		);

		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = 'error ' . ($context['error'] ?? '');
			}
		);

		$listener = new TalkBotInvokeListener($bridge, $binding, $agents, $dispatcher, $grouping, new ConversationParticipation(), new TalkMentionMatcher(), $logger);
		$event = $this->event($object);
		$listener->handle($event);
		$this->log[] = 'reactions ' . implode(',', $event->reactions);
		return $this->log;
	}//end invoke()

	/**
	 * Each way a group-room message is addressed, and the full hand-off.
	 *
	 * @return void
	 */
	public function testEachWayAMessageIsAddressed(): void {
		$handedOff = [
			'group room-9',
			'roomUserIds room-9',
			'sync alice,bob',
			'dispatch conv-1 alice %s room-9',
			'info [TalkBotInvokeListener] Talk turn handed off {"roomToken":"room-9","conversation":"conv-1","speaker":"alice","path":"queued"}',
			'reactions ⏳',
		];
		$expect = static fn (string $text): array => array_map(static fn (string $line): string => sprintf($line, $text), $handedOff);

		$this->assertSame($expect('plain words'), $this->invoke(['content' => 'plain words'], ['created' => true]));
		$this->assertSame($expect('plain words'), $this->invoke(['content' => 'plain words'], ['oneToOne' => true]));
		$this->assertSame($expect('hi @Ada'), $this->invoke(['content' => 'hi @Ada']));
		$this->assertSame($expect('hi @Hermiq'), $this->invoke(['content' => 'hi @Hermiq']));
		$this->assertSame($expect('hi you'), $this->invoke(['content' => 'hi you', 'parameters' => ['x' => 'no', 'm' => ['name' => 'ada']]]));
		$this->assertSame($expect('ok'), $this->invoke(['content' => 'ok', 'inReplyTo' => ['actor' => ['id' => 'bots/ag-1']]]));
		$this->assertSame($expect('ok'), $this->invoke(['content' => 'ok', 'inReplyTo' => ['actor' => ['id' => 'bots/shared']]]));
	}//end testEachWayAMessageIsAddressed()

	/**
	 * A group-room message that addresses nobody takes no turn.
	 *
	 * @return void
	 */
	public function testAnUnaddressedMessageTakesNoTurn(): void {
		$this->assertSame(['reactions '], $this->invoke(['content' => 'ok', 'inReplyTo' => ['actor' => ['id' => 'bots/other']]]));
		$this->assertSame(['reactions '], $this->invoke(['content' => 'ok', 'parameters' => 'none']));
	}//end testAnUnaddressedMessageTakesNoTurn()

	/**
	 * A room seen for the first time is bound to a new conversation; a failed bind takes no turn.
	 *
	 * @return void
	 */
	public function testANewRoomIsBoundOnFirstContact(): void {
		$this->assertSame(
			[
				'roomUserIds room-9',
				'create room-9 ag-1 alice alice,bob',
				'group room-9',
				'dispatch conv-1 alice hi @Ada room-9',
				'info [TalkBotInvokeListener] Talk turn handed off {"roomToken":"room-9","conversation":"conv-1","speaker":"alice","path":"queued"}',
				'reactions ⏳',
			],
			$this->invoke(['content' => 'hi @Ada'], ['existing' => false])
		);

		$this->assertSame(
			['roomUserIds room-9', 'create room-9 ag-1 alice alice,bob', 'reactions '],
			$this->invoke(['content' => 'hi @Ada'], ['existing' => false, 'createFails' => true])
		);
	}//end testANewRoomIsBoundOnFirstContact()
}//end class
