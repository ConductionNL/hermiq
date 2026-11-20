<?php

/**
 * Unit tests for TalkAgentChain: agent-to-agent turns in a Talk room.
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

use OCA\Hermiq\Kernel\Contracts\TalkAgentBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkRoomBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnDispatcherInterface;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkAgentChain;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use OCA\Hermiq\Service\Talk\TalkTurn;
use OCA\Hermiq\Service\Talk\TalkTurnId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mention-only, no self-trigger, hop budget, rate cap, rooted at the human.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkAgentChain
 * @uses   \OCA\Hermiq\Service\Talk\TalkMentionMatcher
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses   \OCA\Hermiq\Service\Talk\TalkTurn
 * @uses   \OCA\Hermiq\Service\Talk\TalkTurnId
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */
final class TalkAgentChainTest extends TestCase {

	/**
	 * The turns handed off: the dispatch arguments by name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $dispatched = [];

	/**
	 * The notices posted into the room: [message, agentId].
	 *
	 * @var array<int, array{0: string, 1: ?string}>
	 */
	private array $notices = [];

	/**
	 * The rate counter per cache key.
	 *
	 * @var array<string, int>
	 */
	private array $counter = [];

	/**
	 * The chain over a room holding Ada, Bram and Cas, whose members are given.
	 *
	 * @param array<int, string> $members  The room's users.
	 * @param int                $maxHops  The hop budget.
	 * @param int                $rate     The per-room per-minute cap.
	 * @param bool               $memcache Whether a distributed memcache exists.
	 * @param array<int, string> $roster   The participants on each agent's session.
	 *
	 * @return TalkAgentChain
	 */
	private function chain(
		array $members = ['alice', 'bob'],
		int $maxHops = 3,
		int $rate = 6,
		bool $memcache = true,
		array $roster = ['bob'],
	): TalkAgentChain {
		$agents = $this->createMock(TalkAgentBindingInterface::class);
		$agents->method('agentsForRoom')->willReturn(['agent-ada', 'agent-bram', 'agent-cas']);
		$agents->method('agentName')->willReturnCallback(
			static fn (string $id): string => ['agent-ada' => 'Ada', 'agent-bram' => 'Bram', 'agent-cas' => 'Cas'][$id] ?? ''
		);

		$rooms = $this->createMock(TalkRoomBindingInterface::class);
		$rooms->method('findByRoomAndAgent')->willReturnCallback(
			static function (string $roomToken, string $agentId) use ($roster): ObjectEntity {
				$session = new ObjectEntity();
				$session->setUuid('session-' . $agentId);
				$session->setObject(['userId' => 'alice', 'participants' => $roster]);
				return $session;
			}
		);
		$rooms->method('syncParticipants')->willReturnArgument(0);

		$dispatcher = $this->createMock(TalkTurnDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function (string $conversationUuid, string $speakerUid, string $message, string $roomToken, string $turnId = '', string $agentId = '', string $messageId = '', array $chain = []): string {
				$this->dispatched[] = compact('conversationUuid', 'speakerUid', 'message', 'roomToken', 'turnId', 'agentId', 'messageId', 'chain');
				return 'queued';
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(TalkTurnDispatcherInterface::class)->willReturn($dispatcher);

		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('roomUserIds')->willReturn($members);
		$bridge->method('postToRoom')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId = null): bool {
				$this->notices[] = [$message, $agentId];
				return true;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => match ($key) {
				TalkAgentChain::MAX_HOPS_KEY => $maxHops,
				TalkAgentChain::RATE_KEY => $rate,
				default => $default,
			}
		);

		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(
			function (string $key, mixed $value): bool {
				if (isset($this->counter[$key]) === true) {
					return false;
				}

				$this->counter[$key] = (int)$value;
				return true;
			}
		);
		$cache->method('inc')->willReturnCallback(
			function (string $key): int {
				$this->counter[$key] = (($this->counter[$key] ?? 0) + 1);
				return $this->counter[$key];
			}
		);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('isAvailable')->willReturn($memcache);
		$caches->method('createDistributed')->willReturn($cache);

		return new TalkAgentChain(
			$agents,
			new TalkMentionMatcher(),
			$rooms,
			new ConversationParticipation(),
			$container,
			$bridge,
			$config,
			$caches,
			$this->createMock(LoggerInterface::class)
		);
	}//end chain()

	/**
	 * A turn of Ada in room-1, spoken by the given user, with a chain or none.
	 *
	 * @param string               $speaker The turn's speaker.
	 * @param array<string, mixed> $chain   The chain descriptor.
	 *
	 * @return TalkTurn
	 */
	private function adaTurn(string $speaker = 'bob', array $chain = []): TalkTurn {
		$turn = new TalkTurn(conversationUuid: 'session-agent-ada', speakerUid: $speaker);
		$turn->roomToken = 'room-1';
		$turn->agentId = 'agent-ada';
		$turn->chain = $chain;
		return $turn;
	}//end adaTurn()

	/**
	 * An answer mentioning another agent hands that agent one turn, as the
	 * human who rooted the chain, on that agent's own session, at hop 1.
	 *
	 * @return void
	 */
	public function testAMentionedAgentTakesOneTurnAsTheRootingHuman(): void {
		$handedOff = $this->chain()->follow(turn: $this->adaTurn(), answer: '@Bram can you check the figures?', messageId: '77');

		self::assertSame(1, $handedOff);
		self::assertCount(1, $this->dispatched);
		$turn = $this->dispatched[0];
		self::assertSame('session-agent-bram', $turn['conversationUuid']);
		self::assertSame('bob', $turn['speakerUid']);
		self::assertSame('agent-bram', $turn['agentId']);
		self::assertSame('@Bram can you check the figures?', $turn['message']);
		self::assertSame(TalkTurnId::derive(roomToken: 'room-1', messageId: '77', agentId: 'agent-bram'), $turn['turnId']);
		self::assertSame(1, $turn['chain']['hop']);
		self::assertSame('bob', $turn['chain']['rootSpeakerUid']);
		self::assertNotSame('', $turn['chain']['chainId']);
		self::assertSame([], $this->notices);
	}//end testAMentionedAgentTakesOneTurnAsTheRootingHuman()

	/**
	 * An answer mentioning no agent, or only the answering agent itself, is inert.
	 *
	 * @return void
	 */
	public function testNoMentionAndASelfMentionAreInert(): void {
		$chain = $this->chain();
		self::assertSame(0, $chain->follow(turn: $this->adaTurn(), answer: 'The figures add up.', messageId: '77'));
		self::assertSame(0, $chain->follow(turn: $this->adaTurn(), answer: 'As @Ada I have checked it.', messageId: '78'));
		self::assertSame([], $this->dispatched);
		self::assertSame([], $this->notices);
	}//end testNoMentionAndASelfMentionAreInert()

	/**
	 * A later hop keeps the chain's id and root, whoever speaks the turn; the
	 * hop past the budget takes no turn and posts a notice instead.
	 *
	 * @return void
	 */
	public function testTheHopBudgetEndsTheChainWithANotice(): void {
		$chain = $this->chain(maxHops: 2);
		$hopOne = ['chainId' => 'c-1', 'hop' => 1, 'rootSpeakerUid' => 'bob'];
		self::assertSame(1, $chain->follow(turn: $this->adaTurn(chain: $hopOne), answer: '@Bram over to you', messageId: '80'));
		self::assertSame(['chainId' => 'c-1', 'hop' => 2, 'rootSpeakerUid' => 'bob'], $this->dispatched[0]['chain']);

		$hopTwo = ['chainId' => 'c-1', 'hop' => 2, 'rootSpeakerUid' => 'bob'];
		self::assertSame(0, $chain->follow(turn: $this->adaTurn(chain: $hopTwo), answer: '@Bram again', messageId: '81'));
		self::assertCount(1, $this->dispatched);
		self::assertSame(
			[['This conversation between agents has ended: it reached its limit of 2 hand-offs.', 'agent-ada']],
			$this->notices
		);
	}//end testTheHopBudgetEndsTheChainWithANotice()

	/**
	 * The per-room cap stops agent-triggered turns within the minute, with a notice.
	 *
	 * @return void
	 */
	public function testTheRoomRateCapStopsTheChain(): void {
		$chain = $this->chain(rate: 1);
		self::assertSame(1, $chain->follow(turn: $this->adaTurn(), answer: '@Bram and @Cas, your views?', messageId: '90'));
		self::assertSame(['agent-bram'], array_column($this->dispatched, 'agentId'));
		self::assertSame(
			[['This conversation between agents has ended: too many agent turns in this room in the last minute.', 'agent-ada']],
			$this->notices
		);
	}//end testTheRoomRateCapStopsTheChain()

	/**
	 * Without a memcache the cap cannot be counted, so no agent-triggered turn is taken.
	 *
	 * @return void
	 */
	public function testWithoutAMemcacheNoChainedTurnIsTaken(): void {
		self::assertSame(0, $this->chain(memcache: false)->follow(turn: $this->adaTurn(), answer: '@Bram check this', messageId: '91'));
		self::assertSame([], $this->dispatched);
	}//end testWithoutAMemcacheNoChainedTurnIsTaken()

	/**
	 * A rooting human who left the room, or who is not on the mentioned agent's
	 * session, fails closed: no chained turn.
	 *
	 * @return void
	 */
	public function testARootingHumanWhoLeftFailsClosed(): void {
		self::assertSame(0, $this->chain(members: ['alice'])->follow(turn: $this->adaTurn(), answer: '@Bram check this', messageId: '92'));
		self::assertSame(0, $this->chain(roster: [])->follow(turn: $this->adaTurn(), answer: '@Bram check this', messageId: '93'));
		self::assertSame([], $this->dispatched);

		// The owner herself is always admitted.
		self::assertSame(1, $this->chain(roster: [])->follow(turn: $this->adaTurn(speaker: 'alice'), answer: '@Bram check this', messageId: '94'));
	}//end testARootingHumanWhoLeftFailsClosed()
}//end class
