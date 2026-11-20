<?php

/**
 * Hermiq TalkBotInvokeListener tests: several agents in one room.
 *
 * Spreed invokes every bot enabled in a room on its own, so each agent's bot
 * decides for itself whether the message addresses its agent. These tests pin
 * that a per-agent bot answers for its own agent only, that a message naming
 * two agents yields one hand-off per agent with its own turn id, and that an
 * unaddressed group message yields none.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
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
 * @spec openspec/changes/instant-talk/tasks.md#4-multi-agent-rooms
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
use OCA\Hermiq\Service\Talk\TalkTurnId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Per-agent addressing in a shared group room.
 *
 * @covers \OCA\Hermiq\Listener\TalkBotInvokeListener
 * @covers \OCA\Hermiq\Listener\TalkInvocation
 * @uses \OCA\Hermiq\Service\Talk\TalkTurnId
 * @uses \OCA\Hermiq\Service\Talk\TalkMentionMatcher
 * @uses \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses \OCA\Hermiq\Listener\TalkMessageText
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-in-a-group-room-only-an-addressed-agent-takes-a-turn
 */
final class TalkBotInvokeListenerAgentsTest extends TestCase {

	/**
	 * Hand-offs the dispatcher received: [agentId, turnId, messageId].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $handOffs = [];

	/**
	 * Session lookups the listener made: [roomToken, agentId].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $sessionLookups = [];

	/**
	 * The listener over a group room holding the agents Ada and Bram.
	 *
	 * @param array<string, bool> $enabled     Talk opt-in per agent uuid.
	 * @param bool                $createdRoom Whether Hermiq created the room for a session.
	 * @param array<int, string>  $roomAgents  The agents whose bot is enabled in the room.
	 *
	 * @return TalkBotInvokeListener
	 */
	private function listener(
		array $enabled = ['agent-ada' => true, 'agent-bram' => true],
		bool $createdRoom = false,
		array $roomAgents = ['agent-ada', 'agent-bram'],
	): TalkBotInvokeListener {
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn(true);
		$bridge->method('isOneToOne')->willReturn(false);
		$bridge->method('botActorId')->willReturnCallback(static fn (?string $agentId = null): string => 'bot-' . ($agentId ?? 'shared'));

		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['userId' => 'alice', 'participants' => []]);
		$roomBinding = $this->createMock(TalkRoomBinding::class);
		$roomBinding->method('findByRoomAndAgent')->willReturnCallback(
			function (string $roomToken, string $agentId) use ($conversation): ObjectEntity {
				$this->sessionLookups[] = [$roomToken, $agentId];
				return $conversation;
			}
		);
		$roomBinding->method('syncParticipants')->willReturnArgument(0);
		$roomBinding->method('isCreatedRoom')->willReturn($createdRoom);

		$agentBinding = $this->createMock(TalkAgentBinding::class);
		$agentBinding->method('agentsForRoom')->willReturn($roomAgents);
		$agentBinding->method('isAgentTalkEnabled')->willReturnCallback(static fn (string $agentId): bool => ($enabled[$agentId] ?? false));
		$agentBinding->method('agentName')->willReturnCallback(
			static fn (string $agentId): string => ['agent-ada' => 'Ada', 'agent-bram' => 'Bram'][$agentId] ?? ''
		);
		$agentBinding->expects($this->never())->method('agentForRoom');

		$dispatcher = $this->createMock(TalkTurnDispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (string $conversationUuid, string $speakerUid, string $message, string $roomToken, string $turnId, string $agentId, string $messageId): string {
				$this->handOffs[] = [$agentId, $turnId, $messageId];
				return 'instant';
			}
		);

		return new TalkBotInvokeListener(
			$bridge,
			$roomBinding,
			$agentBinding,
			$dispatcher,
			$this->createMock(TalkRoomGrouping::class),
			new ConversationParticipation(),
			new TalkMentionMatcher(),
			$this->createMock(LoggerInterface::class)
		);
	}//end listener()

	/**
	 * The invocation spreed makes for one bot.
	 *
	 * @param string $agentId The agent whose bot is invoked.
	 * @param string $content The message text.
	 *
	 * @return Event
	 */
	private function invoke(string $agentId, string $content): Event {
		return new class(TalkBridge::BOT_URL_PREFIX . $agentId, $content) extends Event {
			/**
			 * Constructor.
			 *
			 * @param string $botUrl  The invoked bot's URL.
			 * @param string $content The message text.
			 */
			public function __construct(private readonly string $botUrl, private readonly string $content) {
			}

			/**
			 * The invoked bot's URL.
			 *
			 * @return string
			 */
			public function getBotUrl(): string {
				return $this->botUrl;
			}

			/**
			 * The invocation payload of Talk message 42.
			 *
			 * @return array
			 */
			public function getMessage(): array {
				return [
					'type' => 'Create',
					'actor' => ['type' => 'Person', 'id' => 'users/alice', 'name' => 'Alice'],
					'object' => ['type' => 'Note', 'id' => 42, 'content' => $this->content],
					'target' => ['type' => 'Collection', 'id' => 'room-1', 'name' => 'Room'],
				];
			}

			/**
			 * Acknowledge.
			 *
			 * @param string $emoji The emoji.
			 *
			 * @return void
			 */
			public function addReaction(string $emoji): void {
				unset($emoji);
			}
		};
	}//end invoke()

	/**
	 * Of two agents in the room, only the addressed one takes the turn.
	 *
	 * @return void
	 */
	public function testOnlyTheAddressedAgentTakesTheTurn(): void {
		$listener = $this->listener();
		$listener->handle($this->invoke(agentId: 'agent-ada', content: '@Bram what is the deadline?'));
		$listener->handle($this->invoke(agentId: 'agent-bram', content: '@Bram what is the deadline?'));

		$this->assertSame(
			[['agent-bram', TalkTurnId::derive(roomToken: 'room-1', messageId: '42', agentId: 'agent-bram'), '42']],
			$this->handOffs
		);
	}//end testOnlyTheAddressedAgentTakesTheTurn()

	/**
	 * A message naming both agents yields two hand-offs, each with its own turn id.
	 *
	 * @return void
	 */
	public function testTwoMentionedAgentsTakeTwoTurns(): void {
		$listener = $this->listener();
		$listener->handle($this->invoke(agentId: 'agent-ada', content: '@Ada and @Bram, compare the bids'));
		$listener->handle($this->invoke(agentId: 'agent-bram', content: '@Ada and @Bram, compare the bids'));

		$this->assertSame(['agent-ada', 'agent-bram'], array_column($this->handOffs, 0));
		$this->assertNotSame($this->handOffs[0][1], $this->handOffs[1][1]);
		// Sessions are per (room, agent): each agent's turn binds its own session.
		$this->assertSame([['room-1', 'agent-ada'], ['room-1', 'agent-bram']], $this->sessionLookups);
	}//end testTwoMentionedAgentsTakeTwoTurns()

	/**
	 * An unaddressed group message triggers nobody.
	 *
	 * @return void
	 */
	public function testAnUnaddressedMessageTriggersNobody(): void {
		$listener = $this->listener();
		$listener->handle($this->invoke(agentId: 'agent-ada', content: 'Lunch at noon?'));
		$listener->handle($this->invoke(agentId: 'agent-bram', content: 'Lunch at noon?'));

		$this->assertSame([], $this->handOffs);
	}//end testAnUnaddressedMessageTriggersNobody()

	/**
	 * A bot whose agent opted out of Talk is silent even when addressed.
	 *
	 * @return void
	 */
	public function testAnAgentThatLeftTalkStaysSilent(): void {
		$this->listener(enabled: ['agent-ada' => false])->handle($this->invoke(agentId: 'agent-ada', content: '@Ada hello'));

		$this->assertSame([], $this->handOffs);
	}//end testAnAgentThatLeftTalkStaysSilent()

	/**
	 * A Hermiq-created room with one agent keeps every message as a turn.
	 *
	 * @return void
	 */
	public function testACreatedRoomWithOneAgentTakesEveryMessage(): void {
		$this->listener(createdRoom: true, roomAgents: ['agent-ada'])
			->handle($this->invoke(agentId: 'agent-ada', content: 'Lunch at noon?'));

		$this->assertSame(['agent-ada'], array_column($this->handOffs, 0));
	}//end testACreatedRoomWithOneAgentTakesEveryMessage()

	/**
	 * Once a second agent is enabled in a Hermiq-created room, only the
	 * addressed agent takes a turn there, and an unaddressed message none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-a-room-may-host-several-agents-managed-by-talk-moderators
	 */
	public function testACreatedRoomWithTwoAgentsNeedsAddressing(): void {
		$listener = $this->listener(createdRoom: true);
		$listener->handle($this->invoke(agentId: 'agent-ada', content: 'Lunch at noon?'));
		$listener->handle($this->invoke(agentId: 'agent-bram', content: 'Lunch at noon?'));
		$this->assertSame([], $this->handOffs);

		$listener->handle($this->invoke(agentId: 'agent-ada', content: '@Ada summarise the case'));
		$listener->handle($this->invoke(agentId: 'agent-bram', content: '@Ada summarise the case'));
		$this->assertSame(['agent-ada'], array_column($this->handOffs, 0));
	}//end testACreatedRoomWithTwoAgentsNeedsAddressing()
}//end class
