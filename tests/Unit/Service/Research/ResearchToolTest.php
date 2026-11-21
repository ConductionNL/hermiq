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

use OCA\Hermiq\Mcp\NativeToolCall;
use OCA\Hermiq\Service\Research\ResearchRequest;
use OCA\Hermiq\Service\Research\ResearchStarter;
use OCA\Hermiq\Service\Research\ResearchTool;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchTool
 * @uses   \OCA\Hermiq\Service\Research\ResearchRequest
 * @uses   \OCA\Hermiq\Mcp\NativeToolCall
 */
final class ResearchToolTest extends TestCase {

	/**
	 * The starter.
	 *
	 * @var ResearchStarter&MockObject
	 */
	private ResearchStarter&MockObject $starter;

	/**
	 * A starter double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->starter = $this->createMock(originalClassName: ResearchStarter::class);
	}//end setUp()

	/**
	 * The descriptor is a default-denied create that reaches the person.
	 *
	 * @return void
	 */
	public function testTheDescriptorIsADefaultDeniedCreateThatReachesThePerson(): void {
		$descriptor = ResearchTool::DESCRIPTORS[0];

		self::assertSame(expected: 'hermiq.startResearch', actual: $descriptor['id']);
		self::assertSame(expected: 'user', actual: $descriptor['reach']);
		self::assertSame(expected: 'create', actual: $descriptor['scope']);
		self::assertFalse(condition: $descriptor['readOnlyHint']);
		self::assertFalse(condition: $descriptor['destructiveHint']);
		self::assertSame(expected: ['question'], actual: $descriptor['inputSchema']['required']);
	}//end testTheDescriptorIsADefaultDeniedCreateThatReachesThePerson()

	/**
	 * It starts a thorough run in the injected agent's conversation by default.
	 *
	 * @return void
	 */
	public function testItStartsAResearchRunInTheInjectedConversation(): void {
		$started = null;
		$this->starter->expects(self::once())->method('start')
			->willReturnCallback(function (ResearchRequest $request, string $userId) use (&$started): array {
				$started = [$request->agentId, $request->conversationId, $request->question, $request->depth, $userId];
				return ['id' => 'r-1', 'status' => 'waiting'];
			});

		$result = (new ResearchTool(starter: $this->starter))->invoke(
			toolId: 'hermiq.startResearch',
			call: $this->call(arguments: ['question' => 'Welke gemeenten?', 'agentId' => 'agent-1', 'conversationId' => 'conv-1'])
		);

		self::assertSame(expected: ['agent-1', 'conv-1', 'Welke gemeenten?', 'thorough', 'anne'], actual: $started);
		self::assertSame(expected: 'r-1', actual: $result['researchId']);
		self::assertSame(expected: 'waiting', actual: $result['status']);
	}//end testItStartsAResearchRunInTheInjectedConversation()

	/**
	 * Another tool id is not this tool's.
	 *
	 * @return void
	 */
	public function testAnotherToolIdIsNotThisTools(): void {
		self::assertNull(actual: (new ResearchTool(starter: $this->starter))->invoke(toolId: 'hermiq.webSearch', call: $this->call(arguments: [])));
	}//end testAnotherToolIdIsNotThisTools()

	/**
	 * Without a starter the tool is unavailable; without an agent or conversation the request is refused.
	 *
	 * @return void
	 */
	public function testWithoutAStarterItIsUnavailableAndWithoutContextItIsRefused(): void {
		$this->starter->expects(self::never())->method('start');
		$tool = new ResearchTool(starter: $this->starter);

		$complete  = ['question' => 'Q', 'agentId' => 'a', 'conversationId' => 'c'];
		$noStarter = (new ResearchTool(starter: null))->invoke(toolId: 'hermiq.startResearch', call: $this->call(arguments: $complete));
		$noConversation = $tool->invoke(toolId: 'hermiq.startResearch', call: $this->call(arguments: ['question' => 'Q', 'agentId' => 'agent-1']));
		$badDepth       = $tool->invoke(toolId: 'hermiq.startResearch', call: $this->call(arguments: ['depth' => 'deep'] + $complete));

		self::assertSame(expected: 'unavailable', actual: $noStarter['error']['code']);
		self::assertSame(expected: 'invalid_argument', actual: $noConversation['error']['code']);
		self::assertSame(expected: 'invalid_argument', actual: $badDepth['error']['code']);
	}//end testWithoutAStarterItIsUnavailableAndWithoutContextItIsRefused()

	/**
	 * An agent or conversation the person cannot read is not found.
	 *
	 * @return void
	 */
	public function testAnUnreadableAgentOrConversationIsNotFound(): void {
		$this->starter->method('start')->willReturn(null);

		$result = (new ResearchTool(starter: $this->starter))->invoke(
			toolId: 'hermiq.startResearch',
			call: $this->call(arguments: ['question' => 'Q', 'depth' => 'quick', 'agentId' => 'agent-1', 'conversationId' => 'conv-1'])
		);

		self::assertSame(expected: 'not_found', actual: $result['error']['code']);
	}//end testAnUnreadableAgentOrConversationIsNotFound()

	/**
	 * A tool call by anne.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return NativeToolCall
	 */
	private function call(array $arguments): NativeToolCall {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('anne');

		return new NativeToolCall(user: $user, arguments: $arguments);
	}//end call()
}//end class
