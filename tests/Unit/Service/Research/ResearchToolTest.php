<?php

/**
 * Unit tests for ResearchTool, the `hermiq.startResearch` tool (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use InvalidArgumentException;
use OCA\Hermiq\Mcp\NativeToolCall;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCA\Hermiq\Service\Research\ResearchTool;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchTool
 * @uses   \OCA\Hermiq\Mcp\NativeToolCall
 */
final class ResearchToolTest extends TestCase {

	/**
	 * @var ResearchStarter&MockObject
	 */
	private ResearchStarter&MockObject $starter;

	protected function setUp(): void {
		$this->starter = $this->createMock(ResearchStarter::class);
	}

	public function testTheDescriptorIsADefaultDeniedCreateThatReachesThePerson(): void {
		$descriptor = ResearchTool::DESCRIPTORS[0];

		self::assertSame('hermiq.startResearch', $descriptor['id']);
		self::assertSame('user', $descriptor['reach']);
		self::assertSame('create', $descriptor['scope']);
		self::assertFalse($descriptor['readOnlyHint']);
		self::assertFalse($descriptor['destructiveHint']);
		self::assertSame(['question'], $descriptor['inputSchema']['required']);
	}

	public function testItStartsAResearchRunInTheInjectedConversation(): void {
		$this->starter->expects(self::once())->method('start')
			->with('agent-1', 'conv-1', 'anne', 'Welke gemeenten?', 'thorough')
			->willReturn(['id' => 'r-1', 'status' => 'waiting']);

		$result = (new ResearchTool($this->starter))->invoke('hermiq.startResearch', $this->call(['question' => 'Welke gemeenten?', 'agentId' => 'agent-1', 'conversationId' => 'conv-1']));

		self::assertSame('r-1', $result['researchId']);
		self::assertSame('waiting', $result['status']);
	}

	public function testAnotherToolIdIsNotThisTools(): void {
		self::assertNull((new ResearchTool($this->starter))->invoke('hermiq.webSearch', $this->call([])));
	}

	public function testWithoutAnAgentConversationOrStarterItIsUnavailable(): void {
		$this->starter->expects(self::never())->method('start');

		$noConversation = (new ResearchTool($this->starter))->invoke('hermiq.startResearch', $this->call(['question' => 'Q', 'agentId' => 'agent-1']));
		$noAgent = (new ResearchTool($this->starter))->invoke('hermiq.startResearch', $this->call(['question' => 'Q', 'conversationId' => 'conv-1']));
		$noStarter = (new ResearchTool(null))->invoke('hermiq.startResearch', $this->call(['question' => 'Q', 'agentId' => 'a', 'conversationId' => 'c']));

		self::assertSame('unavailable', $noConversation['error']['code']);
		self::assertSame('unavailable', $noAgent['error']['code']);
		self::assertSame('unavailable', $noStarter['error']['code']);
	}

	public function testARefusedOrUnreadableStartIsAnError(): void {
		$this->starter->method('start')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('The depth is quick or thorough.')),
			null
		);
		$tool = new ResearchTool($this->starter);
		$arguments = ['question' => 'Q', 'depth' => 'deep', 'agentId' => 'agent-1', 'conversationId' => 'conv-1'];

		self::assertSame('invalid_argument', $tool->invoke('hermiq.startResearch', $this->call($arguments))['error']['code']);
		self::assertSame('not_found', $tool->invoke('hermiq.startResearch', $this->call($arguments))['error']['code']);
	}

	/**
	 * A tool call by anne.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return NativeToolCall
	 */
	private function call(array $arguments): NativeToolCall {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anne');

		return new NativeToolCall($user, $arguments);
	}
}
