<?php

/**
 * Tool details reach the chat filtered, driven through the chat controllers.
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
 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

require_once __DIR__ . '/ChatStreamControllerTest.php';

use OCA\Hermiq\Controller\ChatController;
use OCA\Hermiq\Controller\McpRunController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The blocking send, the SSE stream and the cli replay under a conversation's
 * output policy. The conversation belongs to `org-1`, which redacts; the agent
 * belongs to `org-2`, which filters nothing.
 *
 * @covers \OCA\Hermiq\Controller\ChatController
 * @covers \OCA\Hermiq\Controller\ChatStreamController
 * @covers \OCA\Hermiq\Controller\McpRunController
 * @covers \OCA\Hermiq\Service\Engine\Engine
 * @covers \OCA\Hermiq\Service\Engine\ToolLoop
 * @covers \OCA\Hermiq\Service\Engine\StreamYieldChannel
 * @covers \OCA\Hermiq\Service\GuardrailPolicyService
 * @covers \OCA\Hermiq\Service\Engine\RunStepBus
 * @covers \OCA\Hermiq\Service\Engine\TurnGuardrail
 * @uses   \OCA\Hermiq\Service\Engine\FacadeToolInvoker
 * @uses   \OCA\Hermiq\Service\Engine\TurnGuard
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses   \OCA\Hermiq\Service\Engine\RunTraceCollector
 * @uses   \OCA\Hermiq\Service\Chat\ChatRefusalFrame
 * @uses   \OCA\Hermiq\Service\Agent\AgentAvailability
 * @uses   \OCA\Hermiq\Service\RedactionService
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 * @uses   \OCA\Hermiq\Service\Workspace\RepoEffectingGrants
 */
final class ToolDetailDriveTest extends TestCase {

	private const SECRET = 'sk-probeAAAABBBBCCCCDDDD';

	private const TOOL = 'openregister_contact_search';

	/**
	 * In-memory cache store behind the step bus.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * A guardrail service that redacts for `org-1` and filters nothing elsewhere.
	 *
	 * @return GuardrailPolicyService
	 */
	private function guardrail(): GuardrailPolicyService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');
		$service = $this->getMockBuilder(GuardrailPolicyService::class)
			->setConstructorArgs([$this->createMock(ObjectService::class), new RedactionService($config)])
			->onlyMethods(['effectivePolicyFor'])
			->getMock();
		$service->method('effectivePolicyFor')->willReturnCallback(
			static fn (string $organisation): array => [
				'source' => 'organisation',
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => ($organisation === 'org-1') ? 'redact' : 'off'],
				'toolPolicy' => [],
				'enabled' => true,
			]
		);

		return $service;
	}//end guardrail()

	/**
	 * A RunStepBus over the in-memory store.
	 *
	 * @return RunStepBus
	 */
	private function bus(): RunStepBus {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->store[$key] ?? null));
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->store[$key]);
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new RunStepBus($factory);
	}//end bus()

	/**
	 * The search tool descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private function descriptor(): array {
		return [
			'name' => self::TOOL,
			'mcpId' => 'openregister.contact.search',
			'description' => 'Search',
			'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
		];
	}//end descriptor()

	/**
	 * The agent as OpenRegister stores it: organisation `org-2` on the entity.
	 *
	 * @return ObjectEntity
	 */
	private function agent(): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['tools' => ['openregister.contact.*']]);
		$agent->setOrganisation('org-2');

		return $agent;
	}//end agent()

	/**
	 * The conversation, of organisation `org-1`.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(): ObjectEntity {
		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['userId' => 'alice', 'agentId' => null]);
		$conversation->setOrganisation('org-1');

		return $conversation;
	}//end conversation()

	/**
	 * An ObjectService that returns the conversation for the session schema and
	 * the agent for anything else.
	 *
	 * @return ObjectService
	 */
	private function objects(): ObjectService {
		$conversation = $this->conversation();
		$agent = $this->agent();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('find')->willReturnCallback(
			static function (string $id, ?array $extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($conversation, $agent): ObjectEntity {
				if ($schema === 'agent') {
					return $agent;
				}

				return $conversation;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The tool's result JSON, as FacadeToolInvoker encodes it.
	 *
	 * @return string
	 */
	private function rawResult(): string {
		return (string)json_encode(['result' => ['note' => 'key ' . self::SECRET], 'isError' => false]);
	}//end rawResult()

	/**
	 * One cli tools/call for conversation conv-1, recorded on `$bus`.
	 *
	 * @param RunStepBus $bus        The step bus.
	 * @param string     $resultJson The JSON the tool returns.
	 *
	 * @return array{data: array<string, mixed>, sent: array<string, mixed>}
	 */
	private function cliCall(RunStepBus $bus, string $resultJson): array {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturn(['runId' => 'r', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'conv-1']);
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);
		$functionInfo = new class($resultJson) {
			public string $name = 'openregister_contact_search';

			/**
			 * The arguments the last call received.
			 *
			 * @var array<string, mixed>
			 */
			public array $received = [];

			/**
			 * Constructor.
			 *
			 * @param string $json The result JSON.
			 */
			public function __construct(private string $json) {
			}

			/**
			 * The tool's result JSON.
			 *
			 * @param array<string, mixed> $args The call arguments.
			 *
			 * @return string
			 */
			public function callWithArguments(array $args): string {
				$this->received = $args;
				return $this->json;
			}
		};
		$toolLoop = $this->createMock(ToolLoop::class);
		$toolLoop->method('buildFunctionInfos')->willReturn([$functionInfo]);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? 'Bearer good' : '');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$body = '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"' . self::TOOL . '","arguments":{"q":"find ' . self::SECRET . '"}}}';
		$controller = new class($request, $tokens, $this->objects(), $facade, new ToolGrantResolver(), $toolLoop, $this->createMock(ToolSearchService::class), $userManager, $this->createMock(IUserSession::class), $this->createMock(IThrottler::class), $bus, new NullLogger(), $this->guardrail(), $body) extends McpRunController {
			// phpcs:ignore
			public function __construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $bus, $logger, $guardrail, private string $rawBody) {
				parent::__construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $bus, $logger, null, null, $guardrail);
			}

			// phpcs:ignore
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};
		$data = $controller->handle()->getData();

		return ['data' => $data, 'sent' => $functionInfo->received];
	}//end cliCall()

	/**
	 * A real Engine over mocked handlers whose response runs `$inTurn` with the
	 * turn's channel.
	 *
	 * @param callable $inTurn Receives the channel (or null); runs the turn's tool calls.
	 *
	 * @return Engine
	 */
	private function engine(callable $inTurn): Engine {
		$responseHandler = $this->createMock(ResponseGenerationHandler::class);
		$responseHandler->lastUsage = [];
		$responseHandler->method('generateResponse')->willReturnCallback(
			static function (string $text, array $context, array $history, ?ObjectEntity $agent, array $tools, ?StreamYieldChannel $channel) use ($inTurn): string {
				$inTurn($channel);
				return 'done';
			}
		);
		$contextHandler = $this->createMock(ContextRetrievalHandler::class);
		$contextHandler->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);
		$historyHandler = $this->createMock(MessageHistoryHandler::class);
		$historyHandler->method('storeMessage')->willReturnCallback(
			static function (string $conversationId, string $role): ObjectEntity {
				$message = new ObjectEntity();
				$message->setUuid('msg-' . $role);
				return $message;
			}
		);
		$historyHandler->method('buildTurnHistory')->willReturnCallback(
			static fn (string $conversationId, ObjectEntity $turn, string $text): array => ['history' => [], 'turnText' => $text]
		);
		$contextAssembler = $this->createMock(ContextAssembler::class);
		$contextAssembler->method('assembleForAgent')->willReturn('');

		return new Engine(
			$this->objects(),
			$contextHandler,
			$responseHandler,
			$this->createMock(ConversationManagementHandler::class),
			$historyHandler,
			$contextAssembler,
			new NullLogger(),
			$this->guardrail()
		);
	}//end engine()

	/**
	 * A signed-in alice.
	 *
	 * @return IUserSession
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * ChatController::sendMessage returns the cli steps recorded during the turn;
	 * none carries the key, and the tool got the real arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-cli-turns-recorded-steps-are-filtered-before-they-are-stored
	 */
	public function testSendToolCallsAreFiltered(): void {
		$bus = $this->bus();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => (['conversation' => 'conv-1', 'message' => 'hi'][$key] ?? $default));
		$sent = [];
		$engine = $this->engine(function (?StreamYieldChannel $channel) use ($bus, &$sent): void {
			$sent = $this->cliCall(bus: $bus, resultJson: $this->rawResult())['sent'];
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$controller = new ChatController(
			request: $request,
			engine: $engine,
			objectService: $this->objects(),
			userSession: $this->session(),
			l10n: $l10n,
			runStepBus: $bus,
			providerFactory: $this->createMock(ProviderFactory::class),
			accessRequests: $this->createMock(ToolAccessRequestService::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$response = $controller->sendMessage();

		$this->assertSame(200, $response->getStatus());
		$toolCalls = $response->getData()['toolCalls'];
		$this->assertCount(1, $toolCalls);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($toolCalls));
		$this->assertStringContainsString('"note"', $toolCalls[0]['result']);
		$this->assertSame('find ' . self::SECRET, $sent['q']);
	}//end testSendToolCallsAreFiltered()

	/**
	 * The SSE stream: the in-process frames and the cli replay carry no key, while
	 * the tools got the real arguments and the model read the real results.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-tool-details-follow-the-conversations-policy
	 */
	public function testStreamFramesAndReplayAreFiltered(): void {
		$bus = $this->bus();
		$sentInProcess = [];
		$sentCli = [];
		$modelRead = '';
		$cliData = [];
		$engine = $this->engine(
			function (?StreamYieldChannel $channel) use ($bus, &$sentInProcess, &$sentCli, &$modelRead, &$cliData): void {
				$facade = $this->createMock(ToolRegistryFacade::class);
				$facade->method('invokeTool')->willReturnCallback(static function (string $toolId, array $args) use (&$sentInProcess): array {
					$sentInProcess = $args;
					return ['result' => ['note' => 'key ' . self::SECRET], 'isError' => false];
				});
				$appConfig = $this->createMock(IAppConfig::class);
				$appConfig->method('getValueInt')->willReturn(30);
				$loop = new ToolLoop($facade, new NullLogger(), new ToolGrantResolver(), new ToolSearchService(), $this->createMock(ApprovalService::class), $appConfig, $this->guardrail());
				$infos = $loop->buildFunctionInfos(functions: [$this->descriptor()], channel: $channel, organisation: 'org-2');
				$modelRead = (string)$infos[0]->callWithArguments(['q' => 'find ' . self::SECRET]);
				$cli = $this->cliCall(bus: $bus, resultJson: $this->rawResult());
				$sentCli = $cli['sent'];
				$cliData = $cli['data'];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$objects = $this->objects();
		$controller = new TestableChatStreamController(
			$this->createMock(IRequest::class),
			$engine,
			$objects,
			$this->session(),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			$l10n,
			$bus,
			$this->createMock(ToolAccessRequestService::class),
			new AgentAccessService($objects, $this->createMock(LoggerInterface::class), $this->createMock(IGroupManager::class))
		);
		$controller->requestBody = '{"message":"hi","conversationUuid":"conv-1"}';
		try {
			$controller->stream();
		} catch (ChatStreamControllerStopSignal) {
		}

		$tool = array_values(array_filter($controller->capturedEvents, static fn (array $event): bool => in_array($event['type'], ['tool_call', 'tool_result'], true)));
		$this->assertCount(4, $tool, (string)json_encode($controller->capturedEvents));
		foreach ($tool as $frame) {
			$this->assertStringNotContainsString(self::SECRET, (string)json_encode($frame['payload']));
		}

		$this->assertSame('find ' . self::SECRET, $sentInProcess['q']);
		$this->assertSame('find ' . self::SECRET, $sentCli['q']);
		$this->assertStringContainsString(self::SECRET, $modelRead);
		$this->assertStringContainsString(self::SECRET, (string)json_encode($cliData['result']));
	}//end testStreamFramesAndReplayAreFiltered()

	/**
	 * The cli step follows the conversation's organisation, not the agent's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-tool-details-follow-the-conversations-policy
	 */
	public function testCliUsesTheConversationsOrganisation(): void {
		$bus = $this->bus();
		$this->cliCall(bus: $bus, resultJson: $this->rawResult());

		$steps = $bus->read(conversationId: 'conv-1');
		$this->assertCount(1, $steps);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($steps));
	}//end testCliUsesTheConversationsOrganisation()

	/**
	 * The cli result is JSON with escaped slashes; a URL credential the answer
	 * filter masks does not survive in the step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-cli-turns-recorded-steps-are-filtered-before-they-are-stored
	 */
	public function testCliResultWithEscapedSlashesIsFiltered(): void {
		$bus = $this->bus();
		$json = (string)json_encode(['result' => ['dsn' => 'postgres://admin:Sup3rS3cretPw@db.internal:5432/app'], 'isError' => false]);
		$this->cliCall(bus: $bus, resultJson: $json);

		$steps = $bus->read(conversationId: 'conv-1');
		$this->assertStringNotContainsString('Sup3rS3cretPw', $steps[0]['result']);
	}//end testCliResultWithEscapedSlashesIsFiltered()
}//end class
