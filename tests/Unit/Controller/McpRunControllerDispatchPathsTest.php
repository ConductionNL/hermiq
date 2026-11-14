<?php

/**
 * Unit tests for McpRunController's governed tools/call dispatch.
 *
 * McpRunControllerTest covers token auth, the envelope and tools/list, and
 * McpRunControllerToolPolicyTest the organisation tool policy. This file pins
 * what happens once a granted tool is dispatched: what the chat is shown of
 * the step (the conversation's output policy, or the withheld placeholder when
 * the conversation cannot be read), a failing dispatch, a granted tool the
 * loop built no function for, non-object arguments, a vanished token user and
 * an agent that cannot be loaded.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-hermiq-serves-a-governed-mcp-endpoint-scoped-to-a-single-run
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use OCA\Hermiq\Controller\McpRunController;
use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\RunStepBusInterface;
use OCA\Hermiq\Kernel\Contracts\RunTokenServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ToolLoopInterface;
use OCA\Hermiq\Kernel\Contracts\ToolSearchServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The governed tools/call dispatch of McpRunController.
 *
 * @covers \OCA\Hermiq\Controller\McpRunController
 * @uses   \OCA\Hermiq\Controller\Mcp\ToolCallRequest
 * @uses   \OCA\Hermiq\Controller\McpInputSchema
 * @uses   \OCA\Hermiq\Service\Agent\AgentAvailability
 * @uses   \OCA\Hermiq\Service\Workspace\RepoEffectingGrants
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#scenario-a-tool-call-is-governed-not-passed-through
 */
final class McpRunControllerDispatchPathsTest extends TestCase {

	private const TOOL = 'openregister_contact_search';

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * @var ToolLoopInterface&MockObject
	 */
	private ToolLoopInterface $toolLoop;

	/**
	 * @var RunStepBusInterface&MockObject
	 */
	private RunStepBusInterface $bus;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * The conversation the run token names; what `find` answers for it.
	 *
	 * @var mixed
	 */
	private mixed $conversation = null;

	/**
	 * Set up the shared collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['tools' => ['openregister.contact.*']]);
		$agent->setOrganisation('org-1');

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			function (mixed ...$args) use ($agent): mixed {
				if (in_array('agentsession', $args, true) === true) {
					if ($this->conversation instanceof \Throwable) {
						throw $this->conversation;
					}

					return $this->conversation;
				}

				return $agent;
			}
		);
		$this->toolLoop = $this->createMock(ToolLoopInterface::class);
		$this->bus = $this->createMock(RunStepBusInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === 'alice') ? $user : null
		);
	}//end setUp()

	/**
	 * A function info for the given tool name whose call answers or throws.
	 *
	 * @param string $name   The tool name.
	 * @param mixed  $answer The call's answer, or a Throwable to throw.
	 *
	 * @return FunctionInfo&MockObject
	 */
	private function functionInfo(string $name, mixed $answer): FunctionInfo {
		$info = $this->createMock(FunctionInfo::class);
		$info->name = $name;
		if ($answer instanceof \Throwable) {
			$info->method('callWithArguments')->willThrowException($answer);
		} else {
			$info->method('callWithArguments')->willReturn($answer);
		}

		return $info;
	}//end functionInfo()

	/**
	 * The controller for one request body and token binding.
	 *
	 * @param string                               $body      The raw JSON-RPC body.
	 * @param GuardrailPolicyServiceInterface|null $guardrail The output filter service.
	 * @param array<string, string>                $binding   Overrides of the token binding.
	 *
	 * @return McpRunController
	 */
	private function controller(string $body, ?GuardrailPolicyServiceInterface $guardrail=null, array $binding=[]): McpRunController {
		$tokens = $this->createMock(RunTokenServiceInterface::class);
		$tokens->method('verify')->willReturn(
			array_merge(['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'conv-1'], $binding)
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization') ? 'Bearer good' : ''
		);
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn(
			[['name' => self::TOOL, 'mcpId' => 'openregister.contact.search', 'description' => 'Search', 'parameters' => []]]
		);

		return new class($request, $tokens, $this->objects, $facade, new ToolGrantResolver(), $this->toolLoop, $this->createMock(ToolSearchServiceInterface::class), $this->userManager, $this->createMock(IUserSession::class), $this->createMock(IThrottler::class), $this->bus, $this->logger, $guardrail, $body) extends McpRunController {
			// phpcs:ignore
			public function __construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $bus, $logger, $guardrail, private string $rawBody) {
				parent::__construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $bus, $logger, null, null, $guardrail);
			}

			// phpcs:ignore
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};
	}//end controller()

	/**
	 * A tools/call body for the search tool.
	 *
	 * @param mixed $arguments The call's arguments.
	 *
	 * @return string
	 */
	private function callBody(mixed $arguments=['q' => 'x']): string {
		return (string)json_encode(
			['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => self::TOOL, 'arguments' => $arguments]]
		);
	}//end callBody()

	/**
	 * The chat is shown the step as the conversation organisation's output policy filters it.
	 *
	 * @return void
	 */
	public function testTheChatIsShownTheStepThroughTheConversationsOutputPolicy(): void {
		$conversation = new ObjectEntity();
		$conversation->setOrganisation('org-9');
		$this->conversation = $conversation;
		$policy = ['source' => 'organisation', 'outputFilters' => ['piiAction' => 'redact']];

		$guardrail = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrail->expects(self::once())->method('effectivePolicyFor')->with('org-9')->willReturn($policy);
		$guardrail->method('filterOutputValue')->willReturnCallback(
			static fn (array $p, mixed $value): mixed => is_array($value) ? array_merge($value, ['filtered' => true]) : $value
		);
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, '{"n":1}')]);
		$this->bus->expects(self::once())->method('record')->with(
			'conv-1',
			self::TOOL,
			['q' => 'x', 'agentId' => 'agent-1', 'filtered' => true],
			'{"n":1,"filtered":true}',
			self::anything()
		);

		$data = $this->controller(body: $this->callBody(), guardrail: $guardrail)->handle()->getData();

		self::assertSame(7, $data['id']);
		self::assertFalse($data['result']['isError']);
		self::assertSame([['type' => 'text', 'text' => '{"n":1}']], $data['result']['content']);
	}//end testTheChatIsShownTheStepThroughTheConversationsOutputPolicy()

	/**
	 * A result the invoker marks as an error reaches the model as a tool error.
	 *
	 * @return void
	 */
	public function testAnInvokerErrorResultIsAToolLevelError(): void {
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, '{"isError":true,"error":"nope"}')]);

		$data = $this->controller(body: $this->callBody())->handle()->getData();

		self::assertTrue($data['result']['isError']);
		self::assertSame('{"isError":true,"error":"nope"}', $data['result']['content'][0]['text']);
	}//end testAnInvokerErrorResultIsAToolLevelError()

	/**
	 * A filter that answers a string for an object result is shown as that string.
	 *
	 * @return void
	 */
	public function testAFilterAnsweringAStringIsShownAsThatString(): void {
		$conversation = new ObjectEntity();
		$conversation->setOrganisation('org-9');
		$this->conversation = $conversation;
		$guardrail = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrail->method('effectivePolicyFor')->willReturn(['source' => 'organisation']);
		$guardrail->method('filterOutputValue')->willReturnCallback(
			static fn (array $p, mixed $value): mixed => (is_array($value) && isset($value['n'])) ? '[redacted]' : $value
		);
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, '{"n":1}')]);
		$this->bus->expects(self::once())->method('record')->with(
			'conv-1',
			self::TOOL,
			['q' => 'x', 'agentId' => 'agent-1'],
			'[redacted]',
			self::anything()
		);

		$this->controller(body: $this->callBody(), guardrail: $guardrail)->handle();
	}//end testAFilterAnsweringAStringIsShownAsThatString()

	/**
	 * A result that is not JSON is filtered as a plain string.
	 *
	 * @return void
	 */
	public function testANonJsonResultIsFilteredAsText(): void {
		$conversation = new ObjectEntity();
		$conversation->setOrganisation('org-9');
		$this->conversation = $conversation;
		$guardrail = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrail->method('effectivePolicyFor')->willReturn(['source' => 'organisation']);
		$guardrail->method('filterOutputValue')->willReturnCallback(
			static fn (array $p, mixed $value): mixed => is_string($value) ? strtoupper($value) : $value
		);
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, 'plain text')]);
		$this->bus->expects(self::once())->method('record')->with(
			self::anything(),
			self::anything(),
			self::anything(),
			'PLAIN TEXT',
			self::anything()
		);

		$data = $this->controller(body: $this->callBody(), guardrail: $guardrail)->handle()->getData();

		self::assertSame('plain text', $data['result']['content'][0]['text']);
	}//end testANonJsonResultIsFilteredAsText()

	/**
	 * An unreadable conversation withholds the step's arguments and result from the chat.
	 *
	 * @return void
	 */
	public function testAnUnreadableConversationWithholdsTheStep(): void {
		$this->conversation = new RuntimeException('gone');
		$guardrail = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrail->expects(self::never())->method('effectivePolicyFor');
		$guardrail->method('withheldPlaceholder')->willReturn('[withheld]');
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, '{"n":1}')]);
		$this->logger->expects(self::once())->method('warning')->with(
			'[McpRunController] Conversation lookup failed',
			['conversationId' => 'conv-1', 'error' => 'gone']
		);
		$this->bus->expects(self::once())->method('record')->with('conv-1', self::TOOL, '[withheld]', '[withheld]', self::anything());

		$data = $this->controller(body: $this->callBody(), guardrail: $guardrail)->handle()->getData();

		self::assertSame('{"n":1}', $data['result']['content'][0]['text']);
	}//end testAnUnreadableConversationWithholdsTheStep()

	/**
	 * A conversation lookup that answers no object also withholds the step.
	 *
	 * @return void
	 */
	public function testAMissingConversationWithholdsTheStep(): void {
		$this->conversation = null;
		$guardrail = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrail->expects(self::never())->method('effectivePolicyFor');
		$guardrail->expects(self::exactly(2))->method('withheldPlaceholder')->willReturn('[withheld]');
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, '{"n":1}')]);

		$this->controller(body: $this->callBody(), guardrail: $guardrail)->handle();
	}//end testAMissingConversationWithholdsTheStep()

	/**
	 * A run without a conversation reads the policy of the empty organisation.
	 *
	 * @return void
	 */
	public function testARunWithoutAConversationUsesTheEmptyOrganisationsPolicy(): void {
		$guardrail = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrail->expects(self::once())->method('effectivePolicyFor')->with('')->willReturn(['source' => 'default']);
		$guardrail->method('filterOutputValue')->willReturnArgument(1);
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, '{"n":1}')]);
		$this->bus->expects(self::once())->method('record')->with('', self::TOOL, self::anything(), '{"n":1}', self::anything());

		$this->controller(body: $this->callBody(), guardrail: $guardrail, binding: ['conversationId' => ''])->handle();
	}//end testARunWithoutAConversationUsesTheEmptyOrganisationsPolicy()

	/**
	 * A dispatch that throws is logged, recorded as an error step and answered as a tool error.
	 *
	 * @return void
	 */
	public function testAFailingDispatchIsAToolErrorAndAnErrorStep(): void {
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$this->functionInfo(self::TOOL, new RuntimeException('boom'))]);
		$this->logger->expects(self::once())->method('error')->with(
			'[McpRunController] Governed tool dispatch failed',
			['tool' => self::TOOL, 'error' => 'boom']
		);
		$this->bus->expects(self::once())->method('record')->with(
			'conv-1',
			self::TOOL,
			['q' => 'x', 'agentId' => 'agent-1'],
			'The tool call could not be completed.',
			self::anything(),
			RunStepBusInterface::OUTCOME_ERROR
		);

		$response = $this->controller(body: $this->callBody())->handle();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($data['result']['isError']);
		self::assertSame('The tool call could not be completed.', $data['result']['content'][0]['text']);
	}//end testAFailingDispatchIsAToolErrorAndAnErrorStep()

	/**
	 * A granted tool the loop built no function for is refused as unavailable and never run.
	 *
	 * @return void
	 */
	public function testAGrantedToolWithoutABuiltFunctionIsUnavailable(): void {
		$other = $this->functionInfo('some_other_tool', '{}');
		$other->expects(self::never())->method('callWithArguments');
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$other]);
		$this->bus->expects(self::never())->method('record');

		$data = $this->controller(body: $this->callBody())->handle()->getData();

		self::assertTrue($data['result']['isError']);
		self::assertSame("Tool '" . self::TOOL . "' is not available to this agent.", $data['result']['content'][0]['text']);
	}//end testAGrantedToolWithoutABuiltFunctionIsUnavailable()

	/**
	 * Arguments that are not an object are dropped; only the token's agent is passed.
	 *
	 * @return void
	 */
	public function testNonObjectArgumentsBecomeOnlyTheCallingAgent(): void {
		$info = $this->createMock(FunctionInfo::class);
		$info->name = self::TOOL;
		$info->expects(self::once())->method('callWithArguments')->with(['agentId' => 'agent-1'])->willReturn('{}');
		$this->toolLoop->method('buildFunctionInfos')->willReturn([$info]);

		$data = $this->controller(body: $this->callBody(arguments: 'not-an-object'))->handle()->getData();

		self::assertFalse($data['result']['isError']);
	}//end testNonObjectArgumentsBecomeOnlyTheCallingAgent()

	/**
	 * A token whose user no longer exists cannot be served.
	 *
	 * @return void
	 */
	public function testATokenWhoseUserIsGoneIsRefused(): void {
		$this->toolLoop->expects(self::never())->method('buildFunctionInfos');

		$response = $this->controller(body: $this->callBody(), binding: ['userId' => 'bob'])->handle();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'run_not_active'], $response->getData());
	}//end testATokenWhoseUserIsGoneIsRefused()

	/**
	 * An agent lookup that answers no object lists no tools.
	 *
	 * @return void
	 */
	public function testAnAgentLookupAnsweringNoObjectListsNoTools(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(null);
		$this->objects = $objects;

		$data = $this->controller(body: '{"jsonrpc":"2.0","id":3,"method":"tools/list"}')->handle()->getData();

		self::assertSame(['tools' => []], $data['result']);
	}//end testAnAgentLookupAnsweringNoObjectListsNoTools()

	/**
	 * A token bound to no agent lists no tools and never looks one up.
	 *
	 * @return void
	 */
	public function testATokenWithoutAnAgentListsNoTools(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('find');
		$this->objects = $objects;

		$data = $this->controller(body: '{"jsonrpc":"2.0","id":3,"method":"tools/list"}', binding: ['agentId' => ''])->handle()->getData();

		self::assertSame(['tools' => []], $data['result']);
	}//end testATokenWithoutAnAgentListsNoTools()

	/**
	 * Without an overridden body, the request body is read from the input stream,
	 * which is empty under the CLI: a parse error.
	 *
	 * @return void
	 */
	public function testTheRealBodyReaderReadsTheInputStream(): void {
		$tokens = $this->createMock(RunTokenServiceInterface::class);
		$tokens->method('verify')->willReturn(['runId' => 'r', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => '']);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer good');
		$controller = new McpRunController(
			$request,
			$tokens,
			$this->objects,
			$this->createMock(ToolRegistryFacade::class),
			new ToolGrantResolver(),
			$this->toolLoop,
			$this->createMock(ToolSearchServiceInterface::class),
			$this->userManager,
			$this->createMock(IUserSession::class),
			$this->createMock(IThrottler::class),
			$this->bus,
			$this->logger
		);

		$response = $controller->handle();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(-32700, $response->getData()['error']['code']);
	}//end testTheRealBodyReaderReadsTheInputStream()

	/**
	 * The project key in the verified token reaches the governed invoker; the
	 * model never supplies it (memory-project-scopes, CLI transport).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
	 */
	public function testTheTokensProjectKeyReachesTheInvoker(): void {
		$this->toolLoop->expects(self::once())
			->method('buildFunctionInfos')
			->with(self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), 'omgevingsvisie-2040')
			->willReturn([$this->functionInfo(self::TOOL, '{}')]);

		$this->controller(body: $this->callBody(), binding: ['projectKey' => 'omgevingsvisie-2040'])->handle();
	}//end testTheTokensProjectKeyReachesTheInvoker()

	/**
	 * A token without a project key builds the invoker with an empty one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
	 */
	public function testATokenWithoutAProjectBuildsNoProjectKey(): void {
		$this->toolLoop->expects(self::once())
			->method('buildFunctionInfos')
			->with(self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), self::anything(), '')
			->willReturn([$this->functionInfo(self::TOOL, '{}')]);

		$this->controller(body: $this->callBody())->handle();
	}//end testATokenWithoutAProjectBuildsNoProjectKey()
}//end class
