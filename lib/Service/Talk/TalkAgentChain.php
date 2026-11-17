<?php

/**
 * Hermiq TalkAgentChain
 *
 * Lets an agent's answer in a Talk room hand a turn to another agent in that
 * room, within hard limits. Spreed does not invoke bots for a bot's own
 * message, so the chain is followed here, from the answer Hermiq just posted,
 * not from a bot invocation.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\Hermiq\Kernel\Contracts\TalkAgentBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkMentionMatcherInterface;
use OCA\Hermiq\Kernel\Contracts\TalkRoomBindingInterface;
use OCA\Hermiq\Kernel\Contracts\TalkTurnDispatcherInterface;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Follows an agent's answer to the agents it mentions.
 *
 * The limits, all hard:
 * 1. mention-only: an answer that mentions no other agent of the room is inert;
 * 2. no self-trigger: an agent mentioning itself takes no turn;
 * 3. hop budget: a chain is rooted at a human turn and takes at most
 *    `talk.agentChainMaxHops` (default 3) agent-triggered turns; the turn that
 *    would exceed it posts a brief notice instead;
 * 4. per-room rate cap: at most `talk.agentChainRoomRatePerMinute` (default 6)
 *    agent-triggered turns per room per minute; without a distributed memcache
 *    the cap cannot be counted, so no agent-triggered turn is taken.
 *
 * Every agent-triggered turn runs as the human who rooted the chain, and only
 * while that human may still take a turn on the target agent's session.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each limit needs its own collaborator (agents, mentions,
 * sessions, roster, rate counter, dispatcher); splitting them apart would scatter one rule set.
 */
class TalkAgentChain {

	/**
	 * The hop budget key and its default.
	 */
	public const MAX_HOPS_KEY = 'talk.agentChainMaxHops';

	public const DEFAULT_MAX_HOPS = 3;

	/**
	 * The per-room rate cap key and its default.
	 */
	public const RATE_KEY = 'talk.agentChainRoomRatePerMinute';

	public const DEFAULT_RATE = 6;

	/**
	 * Constructor.
	 *
	 * @param TalkAgentBindingInterface   $agentBinding   The agents a room holds, and their names.
	 * @param TalkMentionMatcherInterface $mentionMatcher Whether an answer mentions an agent.
	 * @param TalkRoomBindingInterface    $roomBinding    Each agent's session in the room.
	 * @param ConversationParticipation   $participation  Owner-or-participant guard.
	 * @param ContainerInterface          $container      Resolves the dispatcher when a turn is handed off:
	 *                                                    the dispatcher's executor runs TalkTurnService,
	 *                                                    which holds this chain, so a constructor
	 *                                                    dependency would be a cycle.
	 * @param TalkBridge                  $bridge         Room roster and the chain-ended notice.
	 * @param IAppConfig                  $appConfig      The hop budget and the rate cap.
	 * @param ICacheFactory               $cacheFactory   Counts the per-room rate.
	 * @param LoggerInterface             $logger         PSR-3 logger.
	 */
	public function __construct(
		private readonly TalkAgentBindingInterface $agentBinding,
		private readonly TalkMentionMatcherInterface $mentionMatcher,
		private readonly TalkRoomBindingInterface $roomBinding,
		private readonly ConversationParticipation $participation,
		private readonly ContainerInterface $container,
		private readonly TalkBridge $bridge,
		private readonly IAppConfig $appConfig,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand a turn to every other agent of the room the answer mentions.
	 *
	 * Never throws: the answer is already in the room, and a chain that cannot
	 * be followed must not turn it into a failed turn.
	 *
	 * @param TalkTurn $turn      The turn that produced the answer.
	 * @param string   $answer    The complete answer as posted.
	 * @param string   $messageId The posted answer's Talk message id, or ''.
	 *
	 * @return int The agent-triggered turns handed off.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function follow(TalkTurn $turn, string $answer, string $messageId): int {
		try {
			return $this->followMentions(turn: $turn, answer: $answer, messageId: $messageId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkAgentChain] Could not follow the answer to the agents it mentions',
				context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $turn->roomToken, 'error' => $e->getMessage()]
			);
			return 0;
		}

	}//end follow()

	/**
	 * The chain descriptor of the next hop from a turn.
	 *
	 * @param TalkTurn $turn      The turn that produced the answer.
	 * @param string   $messageId The posted answer's message id.
	 *
	 * @return array{chainId: string, hop: int, rootSpeakerUid: string} The descriptor.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function nextHop(TalkTurn $turn, string $messageId): array {
		$chainId = (string)($turn->chain['chainId'] ?? '');
		if ($chainId === '') {
			$seed = implode('|', [$turn->roomToken, $turn->speakerUid, $turn->agentId, $messageId, $turn->conversationUuid]);
			$chainId = substr(hash('sha256', $seed), 0, 16);
		}

		$root = (string)($turn->chain['rootSpeakerUid'] ?? '');
		if ($root === '') {
			$root = $turn->speakerUid;
		}

		return [
			'chainId' => $chainId,
			'hop' => ((int)($turn->chain['hop'] ?? 0) + 1),
			'rootSpeakerUid' => $root,
		];
	}//end nextHop()

	/**
	 * Follow the mentions within the limits.
	 *
	 * @param TalkTurn $turn      The turn that produced the answer.
	 * @param string   $answer    The answer.
	 * @param string   $messageId The posted answer's message id, or ''.
	 *
	 * @return int The agent-triggered turns handed off.
	 */
	private function followMentions(TalkTurn $turn, string $answer, string $messageId): int {
		if ($turn->agentId === '' || $turn->roomToken === '' || trim($answer) === '') {
			return 0;
		}

		$targets = $this->mentionedAgents(turn: $turn, answer: $answer);
		if ($targets === []) {
			return 0;
		}

		$next = $this->nextHop(turn: $turn, messageId: $messageId);
		$maxHops = max(0, $this->appConfig->getValueInt('hermiq', self::MAX_HOPS_KEY, self::DEFAULT_MAX_HOPS));
		if ($next['hop'] > $maxHops) {
			$this->endChain(turn: $turn, notice: sprintf('This conversation between agents has ended: it reached its limit of %d hand-offs.', $maxHops));
			return 0;
		}

		$handedOff = 0;
		foreach ($targets as $target) {
			if ($this->takeRateSlot(roomToken: $turn->roomToken) === false) {
				$this->endChain(turn: $turn, notice: 'This conversation between agents has ended: too many agent turns in this room in the last minute.');
				break;
			}

			if ($this->handOff(turn: $turn, target: $target, answer: $answer, messageId: $messageId, next: $next) === true) {
				$handedOff++;
			}
		}

		return $handedOff;
	}//end followMentions()

	/**
	 * The other opted-in agents of the room the answer mentions by name.
	 *
	 * @param TalkTurn $turn   The turn.
	 * @param string   $answer The answer.
	 *
	 * @return array<int, string> The mentioned agents' uuids.
	 */
	private function mentionedAgents(TalkTurn $turn, string $answer): array {
		$targets = [];
		foreach ($this->agentBinding->agentsForRoom(roomToken: $turn->roomToken) as $agentId) {
			if ($agentId === $turn->agentId) {
				continue;
			}

			$name = $this->agentBinding->agentName(agentId: $agentId);
			if ($name !== '' && $this->mentionMatcher->matchesAny(content: $answer, names: [$name]) === true) {
				$targets[] = $agentId;
			}
		}

		return $targets;
	}//end mentionedAgents()

	/**
	 * Hand one agent-triggered turn off, as the chain's rooting human.
	 *
	 * The target agent's session in the room is found or opened for the
	 * rooting human, its roster synced with the room, and the rooting human
	 * must still be its owner or a participant: one who left fails closed.
	 *
	 * @param TalkTurn                                                $turn      The turn that produced the answer.
	 * @param string                                                  $target    The mentioned agent.
	 * @param string                                                  $answer    The answer, the chained turn's message.
	 * @param string                                                  $messageId The posted answer's message id, or ''.
	 * @param array{chainId: string, hop: int, rootSpeakerUid: string} $next      The next hop's descriptor.
	 *
	 * @return bool True when the turn was handed off.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TalkTurnId::derive() is the one pure turn-id function every hand-off uses.
	 */
	private function handOff(TalkTurn $turn, string $target, string $answer, string $messageId, array $next): bool {
		$room = $turn->roomToken;
		$root = $next['rootSpeakerUid'];
		$members = $this->bridge->roomUserIds(roomToken: $room);

		$session = $this->roomBinding->findByRoomAndAgent(roomToken: $room, agentId: $target);
		if ($session !== null) {
			$session = $this->roomBinding->syncParticipants(conversation: $session, participants: $members);
		}

		if ($session === null) {
			$session = $this->roomBinding->createBound(roomToken: $room, agentId: $target, ownerUid: $root, participants: $members);
		}

		if ($session === null
			|| in_array($root, $members, true) === false
			|| $this->participation->mayTakeTurn(conversationData: (array)$session->getObject(), userId: $root) === false
		) {
			$this->logger->info(
				message: '[TalkAgentChain] The chain\'s rooting user may not take a turn on the mentioned agent\'s session — no chained turn',
				context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $room, 'agentId' => $target, 'chainId' => $next['chainId']]
			);
			return false;
		}

		$turnKey = $messageId;
		if ($turnKey === '') {
			$turnKey = $next['chainId'] . '-' . $next['hop'];
		}

		$this->container->get(TalkTurnDispatcherInterface::class)->dispatch(
			conversationUuid: (string)$session->getUuid(),
			speakerUid: $root,
			message: $answer,
			roomToken: $room,
			turnId: TalkTurnId::derive(roomToken: $room, messageId: $turnKey, agentId: $target),
			agentId: $target,
			messageId: $messageId,
			chain: $next
		);

		return true;
	}//end handOff()

	/**
	 * Count one agent-triggered turn against the room's per-minute cap.
	 *
	 * @param string $roomToken The room.
	 *
	 * @return bool True when the turn is within the cap; false without a memcache.
	 */
	private function takeRateSlot(string $roomToken): bool {
		$limit = max(0, $this->appConfig->getValueInt('hermiq', self::RATE_KEY, self::DEFAULT_RATE));
		if ($limit === 0 || $this->cacheFactory->isAvailable() === false) {
			return false;
		}

		$cache = $this->cacheFactory->createDistributed('hermiq_talk_chain');
		if (($cache instanceof IMemcache) === false) {
			return false;
		}

		$key = 'rate-' . $roomToken . '-' . (int)floor(time() / 60);
		$cache->add($key, 0, 120);
		$count = $cache->inc($key);

		return (is_int($count) === true && $count <= $limit);
	}//end takeRateSlot()

	/**
	 * End the chain with a brief notice in the room, under the answering agent's bot.
	 *
	 * @param TalkTurn $turn   The turn whose answer would have continued the chain.
	 * @param string   $notice The notice.
	 *
	 * @return void
	 */
	private function endChain(TalkTurn $turn, string $notice): void {
		$this->logger->info(
			message: '[TalkAgentChain] Agent chain ended',
			context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $turn->roomToken, 'notice' => $notice]
		);
		$this->bridge->postToRoom(roomToken: $turn->roomToken, message: $notice, agentId: $turn->agentId);
	}//end endChain()
}//end class
