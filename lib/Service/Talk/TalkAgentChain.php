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
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
class TalkAgentChain {

	/**
	 * The hop budget key and its default (kept here for callers that read them).
	 */
	public const MAX_HOPS_KEY = TalkChainBudget::MAX_HOPS_KEY;

	public const DEFAULT_MAX_HOPS = TalkChainBudget::DEFAULT_MAX_HOPS;

	/**
	 * The per-room rate cap key and its default.
	 */
	public const RATE_KEY = TalkChainBudget::RATE_KEY;

	public const DEFAULT_RATE = TalkChainBudget::DEFAULT_RATE;

	/**
	 * Constructor.
	 *
	 * @param TalkChainMentions $mentions The agents of the room an answer mentions.
	 * @param TalkChainRoom     $room     The target's session, the hand-off and the chain-ended notice.
	 * @param TalkChainBudget   $budget   The hop budget and the per-room rate cap.
	 * @param LoggerInterface   $logger   PSR-3 logger.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		private readonly TalkChainMentions $mentions,
		private readonly TalkChainRoom $room,
		private readonly TalkChainBudget $budget,
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
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	private function followMentions(TalkTurn $turn, string $answer, string $messageId): int {
		if ($turn->agentId === '' || $turn->roomToken === '' || trim($answer) === '') {
			return 0;
		}

		$targets = $this->mentions->mentionedAgents(turn: $turn, answer: $answer);
		if ($targets === []) {
			return 0;
		}

		$next = $this->nextHop(turn: $turn, messageId: $messageId);
		$maxHops = $this->budget->maxHops();
		if ($next['hop'] > $maxHops) {
			$this->endChain(turn: $turn, notice: sprintf('This conversation between agents has ended: it reached its limit of %d hand-offs.', $maxHops));
			return 0;
		}

		$hop = new TalkChainHop(answer: $answer, messageId: $messageId, next: $next);
		$handedOff = 0;
		foreach ($targets as $target) {
			if ($this->budget->takeRateSlot(roomToken: $turn->roomToken) === false) {
				$this->endChain(turn: $turn, notice: 'This conversation between agents has ended: too many agent turns in this room in the last minute.');
				break;
			}

			if ($this->handOff(turn: $turn, target: $target, hop: $hop) === true) {
				$handedOff++;
			}
		}

		return $handedOff;
	}//end followMentions()

	/**
	 * Hand one agent-triggered turn off, as the chain's rooting human.
	 *
	 * The target agent's session in the room is found or opened for the
	 * rooting human, its roster synced with the room, and the rooting human
	 * must still be its owner or a participant: one who left fails closed.
	 *
	 * @param TalkTurn     $turn   The turn that produced the answer.
	 * @param string       $target The mentioned agent.
	 * @param TalkChainHop $hop    The answer, its message id and the next hop's descriptor.
	 *
	 * @return bool True when the turn was handed off.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TalkTurnId::derive() is the one pure turn-id function every hand-off uses.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	private function handOff(TalkTurn $turn, string $target, TalkChainHop $hop): bool {
		$room = $turn->roomToken;
		$root = $hop->next['rootSpeakerUid'];

		$session = $this->room->sessionFor(roomToken: $room, agentId: $target, rootUid: $root);
		if ($session === null) {
			$this->logger->info(
				message: '[TalkAgentChain] The chain\'s rooting user may not take a turn on the mentioned agent\'s session — no chained turn',
				context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $room, 'agentId' => $target, 'chainId' => $hop->next['chainId']]
			);
			return false;
		}

		$chained = new TalkTurn(conversationUuid: (string)$session->getUuid(), speakerUid: $root);
		$chained->message = $hop->answer;
		$chained->roomToken = $room;
		$chained->turnId = TalkTurnId::derive(roomToken: $room, messageId: $hop->turnKey(), agentId: $target);
		$chained->agentId = $target;
		$chained->messageId = $hop->messageId;
		$chained->chain = $hop->next;
		$this->room->dispatch(turn: $chained);

		return true;
	}//end handOff()

	/**
	 * End the chain with a brief notice in the room, under the answering agent's bot.
	 *
	 * @param TalkTurn $turn   The turn whose answer would have continued the chain.
	 * @param string   $notice The notice.
	 *
	 * @return void
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	private function endChain(TalkTurn $turn, string $notice): void {
		$this->logger->info(
			message: '[TalkAgentChain] Agent chain ended',
			context: ['file' => __FILE__, 'line' => __LINE__, 'roomToken' => $turn->roomToken, 'notice' => $notice]
		);
		$this->room->postNotice(roomToken: $turn->roomToken, notice: $notice, agentId: $turn->agentId);
	}//end endChain()
}//end class
