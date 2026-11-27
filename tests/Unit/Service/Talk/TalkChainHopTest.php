<?php

/**
 * Unit tests for TalkChainHop and TalkChainMentions.
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
use OCA\Hermiq\Service\Talk\TalkChainHop;
use OCA\Hermiq\Service\Talk\TalkChainMentions;
use OCA\Hermiq\Service\Talk\TalkMentionMatcher;
use OCA\Hermiq\Service\Talk\TalkTurn;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see TalkChainHop} and {@see TalkChainMentions}.
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkChainHopTest extends TestCase {

	/**
	 * The chained turn's id derives from the posted answer's message id, else from the chain and hop.
	 *
	 * @return void
	 */
	public function testTheTurnKeyPrefersThePostedMessage(): void {
		$next = ['chainId' => 'abc', 'hop' => 2, 'rootSpeakerUid' => 'alice'];

		$this->assertSame(expected: '77', actual: (new TalkChainHop(answer: '@Bram', messageId: '77', next: $next))->turnKey());
		$this->assertSame(expected: 'abc-2', actual: (new TalkChainHop(answer: '@Bram', messageId: '', next: $next))->turnKey());
	}//end testTheTurnKeyPrefersThePostedMessage()

	/**
	 * Only other agents of the room that the answer names are mentioned.
	 *
	 * @return void
	 */
	public function testOnlyOtherNamedAgentsAreMentioned(): void {
		$agents = $this->createMock(originalClassName: TalkAgentBindingInterface::class);
		$agents->method('agentsForRoom')->willReturn(['agent-ada', 'agent-bram', 'agent-cas', 'agent-nameless']);
		$agents->method('agentName')->willReturnCallback(
			static fn (string $id): string => ['agent-ada' => 'Ada', 'agent-bram' => 'Bram', 'agent-cas' => 'Cas'][$id] ?? ''
		);
		$turn = new TalkTurn(conversationUuid: 'session-ada', speakerUid: 'alice');
		$turn->roomToken = 'room-1';
		$turn->agentId = 'agent-ada';

		$mentions = new TalkChainMentions(agentBinding: $agents, mentionMatcher: new TalkMentionMatcher());

		$this->assertSame(expected: ['agent-bram'], actual: $mentions->mentionedAgents(turn: $turn, answer: '@Ada and @Bram, over to you.'));
		$this->assertSame(expected: [], actual: $mentions->mentionedAgents(turn: $turn, answer: 'No one else.'));
	}//end testOnlyOtherNamedAgentsAreMentioned()
}//end class
