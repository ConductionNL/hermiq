<?php

/**
 * The Talk message tool (tools-post-a-talk-message): a granted agent posts in
 * a conversation its bot is a member of, and nowhere else.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-post-a-talk-message/specs/nc-native-tools/spec.md#requirement-an-agent-can-post-a-message-in-a-talk-conversation-it-is-a-member-of-req-nctalk-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Kernel\Contracts\TalkMessageToolInterface;
use OCA\Hermiq\Mcp\NcTalkToolDescriptors;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\NcNative\TalkMessageTool;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * One tool, four answers: posted, not a member, Talk missing, no agent.
 */
class TalkMessageToolTest extends TestCase {

	/**
	 * Every message posted, as [room, text, agent].
	 *
	 * @var array<int, array<int, string|null>>
	 */
	private array $posts = [];

	/**
	 * A tool over a Talk where the agent `agent-1` bot is in `servicedesk` only.
	 *
	 * @param bool        $talk     Whether Talk is installed.
	 * @param string|null $postedId The id Talk answers a post with, or null when it refuses.
	 *
	 * @return TalkMessageTool The tool.
	 */
	private function tool(bool $talk = true, ?string $postedId = 'msg-42'): TalkMessageTool {
		$this->posts = [];
		$bridge = $this->createMock(TalkBridge::class);
		$bridge->method('isAvailable')->willReturn($talk);
		$bridge->method('isAgentBotIn')->willReturnCallback(
			static fn (string $roomToken, string $agentId): bool => $roomToken === 'servicedesk' && $agentId === 'agent-1'
		);
		$bridge->method('postToRoomReturningId')->willReturnCallback(
			function (string $roomToken, string $message, ?string $agentId = null) use ($postedId): ?string {
				$this->posts[] = [$roomToken, $message, $agentId];
				return $postedId;
			}
		);

		return new TalkMessageTool($bridge, new NullLogger());
	}//end tool()

	public function testAGrantedAgentPostsInItsOwnConversationAsItsOwnBot(): void {
		$result = $this->tool()->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, [
			'agentId' => 'agent-1',
			'conversation' => 'servicedesk',
			'message' => 'De storing is verholpen.',
		]);

		$this->assertSame(['posted' => true, 'conversation' => 'servicedesk', 'messageId' => 'msg-42'], $result);
		$this->assertSame([['servicedesk', 'De storing is verholpen.', 'agent-1']], $this->posts);
	}//end testAGrantedAgentPostsInItsOwnConversationAsItsOwnBot()

	public function testAConversationTheAgentIsNotInIsRefusedWithoutPosting(): void {
		$result = $this->tool()->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, [
			'agentId' => 'agent-1',
			'conversation' => 'directie',
			'message' => 'Hallo',
		]);

		$this->assertSame('not_a_member', $result['error']['code'] ?? null);
		$this->assertStringContainsString('not a member', $result['error']['message']);
		$this->assertSame([], $this->posts);

		// Another agent's bot being in the room does not make this agent a member.
		$other = $this->tool()->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, ['agentId' => 'agent-2', 'conversation' => 'servicedesk', 'message' => 'x']);
		$this->assertSame('not_a_member', $other['error']['code'] ?? null);
		$this->assertSame([], $this->posts);
	}//end testAConversationTheAgentIsNotInIsRefusedWithoutPosting()

	public function testWithoutTalkTheAgentGetsAReadableErrorAndNothingIsPosted(): void {
		$result = $this->tool(talk: false)->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, [
			'agentId' => 'agent-1',
			'conversation' => 'servicedesk',
			'message' => 'Hallo',
		]);

		$this->assertSame('talk_unavailable', $result['error']['code'] ?? null);
		$this->assertSame([], $this->posts);
	}//end testWithoutTalkTheAgentGetsAReadableErrorAndNothingIsPosted()

	public function testWithoutARunThereIsNoAgentToPostAs(): void {
		$result = $this->tool()->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, ['conversation' => 'servicedesk', 'message' => 'Hallo']);

		$this->assertSame('no_agent_context', $result['error']['code'] ?? null);
		$this->assertSame([], $this->posts);
	}//end testWithoutARunThereIsNoAgentToPostAs()

	public function testMissingArgumentsARefusedPostAndAnUnknownToolAreErrors(): void {
		$this->assertSame('invalid_arguments', $this->tool()->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, ['agentId' => 'agent-1', 'conversation' => 'servicedesk', 'message' => '  '])['error']['code']);
		$this->assertSame('invalid_arguments', $this->tool()->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, ['agentId' => 'agent-1', 'message' => 'Hallo'])['error']['code']);
		$this->assertSame([], $this->posts);

		$this->assertSame('post_failed', $this->tool(postedId: null)->invoke('anne', NcTalkToolDescriptors::SEND_MESSAGE, ['agentId' => 'agent-1', 'conversation' => 'servicedesk', 'message' => 'Hallo'])['error']['code']);
		$this->assertSame('unknown_tool', $this->tool()->invoke('anne', 'hermiq.other', [])['error']['code']);
	}//end testMissingArgumentsARefusedPostAndAnUnknownToolAreErrors()

	public function testTheToolIsAMediumRiskWriteThatNeedsAGrant(): void {
		$descriptor = NcTalkToolDescriptors::ALL[0];

		$this->assertSame('hermiq.sendTalkMessage', $descriptor['id']);
		$this->assertSame(ToolReachResolver::REACH_INSTANCE, $descriptor['reach']);
		$this->assertFalse($descriptor['readOnlyHint'], 'A write is gated until granted, never auto-allowed as a read.');
		$this->assertSame('create', $descriptor['scope']);
		$this->assertSame(['conversation', 'message'], $descriptor['inputSchema']['required']);
		$this->assertArrayNotHasKey('agentId', $descriptor['inputSchema']['properties'], 'The agent is never the model\'s to name.');
		$this->assertInstanceOf(TalkMessageToolInterface::class, $this->tool());
	}//end testTheToolIsAMediumRiskWriteThatNeedsAGrant()

	public function testTheRunInjectsItsOwnAgentOverWhatTheModelSent(): void {
		$seen = [];
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId, array $arguments) use (&$seen): array {
				$seen[] = [$toolId, ($arguments['agentId'] ?? null)];
				return ['result' => ['posted' => true], 'isError' => false];
			}
		);

		$invoker = new FacadeToolInvoker(
			facade: $facade,
			trace: new RunTraceCollector(),
			agentId: 'agent-1',
			mcpIdByName: ['hermiq_sendTalkMessage' => 'hermiq.sendTalkMessage']
		);
		$invoker->hermiq_sendTalkMessage(conversation: 'servicedesk', message: 'Hallo', agentId: 'agent-evil');

		// The facade is called by the function name; what matters is whose agent id it carries.
		$this->assertSame([['hermiq_sendTalkMessage', 'agent-1']], $seen);
	}//end testTheRunInjectsItsOwnAgentOverWhatTheModelSent()
}//end class
