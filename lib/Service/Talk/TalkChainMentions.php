<?php

/**
 * Hermiq TalkChainMentions
 *
 * Which other agents of a room an agent's answer mentions by name. Only the
 * room's opted-in agents count, and an agent never mentions itself into a turn.
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

/**
 * Finds the agents an answer mentions.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */
class TalkChainMentions {

	/**
	 * Constructor.
	 *
	 * @param TalkAgentBindingInterface   $agentBinding   The agents a room holds, and their names.
	 * @param TalkMentionMatcherInterface $mentionMatcher Whether an answer mentions an agent.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		private readonly TalkAgentBindingInterface $agentBinding,
		private readonly TalkMentionMatcherInterface $mentionMatcher,
	) {
	}//end __construct()

	/**
	 * The other opted-in agents of the room the answer mentions by name.
	 *
	 * @param TalkTurn $turn   The turn that produced the answer.
	 * @param string   $answer The answer.
	 *
	 * @return array<int, string> The mentioned agents' uuids.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function mentionedAgents(TalkTurn $turn, string $answer): array {
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
}//end class
