<?php

/**
 * Tool call details shown in the chat pass the output filters.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Controller\McpRunController;
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
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The stream frames, the recorded cli steps and their replay under an output policy.
 *
 * @covers \OCA\Hermiq\Service\Engine\Engine
 * @covers \OCA\Hermiq\Service\Engine\ToolLoop
 * @covers \OCA\Hermiq\Service\Engine\StreamYieldChannel
 * @covers \OCA\Hermiq\Service\GuardrailPolicyService
 * @covers \OCA\Hermiq\Controller\McpRunController
 * @covers \OCA\Hermiq\Service\Engine\RunStepBus
 * @covers \OCA\Hermiq\Service\Engine\TurnGuardrail
 * @uses   \OCA\Hermiq\Service\Engine\FacadeToolInvoker
 * @uses   \OCA\Hermiq\Service\Engine\TurnGuard
 * @uses   \OCA\Hermiq\Service\Talk\ConversationParticipation
 * @uses   \OCA\Hermiq\Service\Engine\RunTraceCollector
 * @uses   \OCA\Hermiq\Service\Agent\AgentAvailability
 * @uses   \OCA\Hermiq\Service\RedactionService
 * @uses   \OCA\Hermiq\Service\Workspace\RepoEffectingGrants
 */
final class ToolDetailOutputFilterTest extends TestCase {

	private const SECRET = 'sk-probeAAAABBBBCCCCDDDD';

	private const TOOL = 'openregister_contact_search';

	/**
	 * How often the cli path read a conversation.
	 *
	 * @var integer
	 */
	private int $conversationReads = 0;

	/**
	 * A guardrail service whose policy for one organisation has the given output
	 * action, and whose policy for every other organisation filters nothing.
	 *
	 * @param string $action       `off`, `redact` or `block`.
	 * @param string $organisation The organisation the action applies to.
	 *
	 * @return GuardrailPolicyService
	 */
	private function guardrail(string $action, string $organisation = 'org-1'): GuardrailPolicyService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		$service = $this->getMockBuilder(GuardrailPolicyService::class)
			->setConstructorArgs([$this->createMock(ObjectService::class), new RedactionService($config)])
			->onlyMethods(['effectivePolicyFor'])
			->getMock();
		$service->method('effectivePolicyFor')->willReturnCallback(
			static fn (string $asked): array => [
				'source' => 'organisation',
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => ($asked === $organisation) ? $action : 'off'],
				'toolPolicy' => [],
				'enabled' => true,
			]
		);

		return $service;
	}//end guardrail()

	/**
	 * The search tool descriptor.
	 *
	 * @return array<string, mixed>
	 */
	private function descriptor(): array {
		return [
			'name' => self::TOOL,
			'mcpId' => 'openregister.contact.search',
			'description' => 'Search contacts',
			'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
		];
	}//end descriptor()

	/**
	 * Run one streamed turn through the Engine whose response makes one in-process
	 * tool call through ToolLoop, and collect the stream frames.
	 *
	 * The conversation belongs to `$conversationOrg`; the tools are built for the
	 * agent's organisation, `$agentOrg`.
	 *
	 * @param GuardrailPolicyService|null $guardrail       The guardrail service, or none.
	 * @param array<string, mixed>        $sent            Filled with the arguments the tool received.
	 * @param string                      $conversationOrg The conversation's organisation.
	 * @param string                      $agentOrg        The organisation the tools are built for.
	 *
	 * @return array{frames: list<array{0: string, 1: array<string, mixed>}>, model: string}
	 */
	private function streamOneCall(
		?GuardrailPolicyService $guardrail,
		array &$sent,
		string $conversationOrg = 'org-1',
		string $agentOrg = 'org-2',
	): array {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			static function (string $toolId, array $arguments) use (&$sent): array {
				$sent = $arguments;
				return ['result' => ['note' => 'key ' . self::SECRET], 'isError' => false];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);
		$loop = new ToolLoop(
			$facade,
			new NullLogger(),
			new ToolGrantResolver(),
			new ToolSearchService(),
			$this->createMock(ApprovalService::class),
			$appConfig,
			$guardrail
		);

		$model = '';
		$responseHandler = $this->createMock(ResponseGenerationHandler::class);
		$responseHandler->lastUsage = [];
		$responseHandler->method('generateResponse')->willReturnCallback(
			function (string $text, array $context, array $history, ?ObjectEntity $agent, array $tools, ?StreamYieldChannel $channel) use ($loop, $agentOrg, &$model): string {
				$infos = $loop->buildFunctionInfos(functions: [$this->descriptor()], channel: $channel, organisation: $agentOrg);
				$model = (string)$infos[0]->callWithArguments(['q' => 'find ' . self::SECRET]);
				return 'done';
			}
		);

		$conversation = new ObjectEntity();
		$conversation->setUuid('conv-1');
		$conversation->setObject(['userId' => 'alice', 'agentId' => null]);
		$conversation->setOrganisation($conversationOrg);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($conversation);

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

		$engine = new Engine(
			$objects,
			$contextHandler,
			$responseHandler,
			$this->createMock(ConversationManagementHandler::class),
			$historyHandler,
			$contextAssembler,
			new NullLogger(),
			$guardrail
		);

		$frames = [];
		$channel = new StreamYieldChannel();
		$channel->onToolCall(callback: static function (array $payload) use (&$frames): void {
			$frames[] = ['tool_call', $payload];
		});
		$channel->onToolResult(callback: static function (array $payload) use (&$frames): void {
			$frames[] = ['tool_result', $payload];
		});

		$engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: 'hi', channel: $channel);

		return ['frames' => $frames, 'model' => $model];
	}//end streamOneCall()

	/**
	 * Under `redact`, the tool_result frame shows the result without the key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-secret-in-a-tool-result-is-redacted-before-the-chat-shows-it
	 */
	public function testToolResultFrameIsRedacted(): void {
		$sent = [];
		$run = $this->streamOneCall(guardrail: $this->guardrail(action: 'redact'), sent: $sent);

		$this->assertSame('tool_result', $run['frames'][1][0]);
		$result = $run['frames'][1][1]['result'];
		$this->assertIsArray($result);
		$this->assertArrayHasKey('note', $result);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($result));
		$this->assertStringStartsWith('key ', (string)$result['note']);
	}//end testToolResultFrameIsRedacted()

	/**
	 * Under `redact`, the tool_call frame hides the key the model wrote, while the
	 * tool still runs with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-tool-call-arguments-are-filtered-for-the-chat-but-not-for-the-tool
	 */
	public function testToolCallFrameIsRedactedButTheToolGetsItsArguments(): void {
		$sent = [];
		$run = $this->streamOneCall(guardrail: $this->guardrail(action: 'redact'), sent: $sent);

		$this->assertSame('tool_call', $run['frames'][0][0]);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($run['frames'][0][1]['arguments']));
		$this->assertSame('find ' . self::SECRET, $sent['q']);
		$this->assertStringContainsString(self::SECRET, $run['model']);
	}//end testToolCallFrameIsRedactedButTheToolGetsItsArguments()

	/**
	 * Under `block`, both frames show the withheld placeholder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-blocked-tool-detail-is-shown-as-withheld
	 */
	public function testBlockedFramesShowThePlaceholder(): void {
		$sent = [];
		$run = $this->streamOneCall(guardrail: $this->guardrail(action: 'block'), sent: $sent);

		$this->assertStringContainsString('withheld', (string)$run['frames'][0][1]['arguments']);
		$this->assertStringContainsString('withheld', (string)$run['frames'][1][1]['result']);
		$this->assertSame('find ' . self::SECRET, $sent['q']);
	}//end testBlockedFramesShowThePlaceholder()

	/**
	 * With the output filter off, or no guardrail service, the frames are exactly
	 * what the tool call produced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-with-the-output-filter-off-tool-details-are-shown-as-they-are
	 */
	public function testWithoutAnOutputFilterTheFramesAreUnchanged(): void {
		$expected = [
			['tool_call', ['toolId' => self::TOOL, 'arguments' => ['q' => 'find ' . self::SECRET]]],
			['tool_result', ['toolId' => self::TOOL, 'result' => ['note' => 'key ' . self::SECRET], 'isError' => false]],
		];

		foreach ([$this->guardrail(action: 'off'), null] as $guardrail) {
			$sent = [];
			$run = $this->streamOneCall(guardrail: $guardrail, sent: $sent);

			$this->assertSame($expected, $run['frames']);
			$this->assertSame('find ' . self::SECRET, $sent['q']);
		}
	}//end testWithoutAnOutputFilterTheFramesAreUnchanged()

	/**
	 * The value filter: text stays text, key-based patterns match, a value with
	 * nothing to redact comes back as given, and a redaction that breaks the JSON
	 * comes back as the redacted text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-secret-in-a-tool-result-is-redacted-before-the-chat-shows-it
	 */
	public function testFilterOutputValueShapes(): void {
		$service = $this->guardrail(action: 'redact');
		$policy = $service->effectivePolicyFor(organisation: 'org-1');

		$text = $service->filterOutputValue(policy: $policy, value: 'key ' . self::SECRET);
		$this->assertIsString($text);
		$this->assertStringStartsWith('key ', $text);
		$this->assertStringNotContainsString(self::SECRET, $text);

		$jsonText = $service->filterOutputValue(policy: $policy, value: '{"note":"key ' . self::SECRET . '"}');
		$this->assertIsString($jsonText);
		$this->assertStringNotContainsString(self::SECRET, $jsonText);

		$this->assertSame(['password' => '***'], $service->filterOutputValue(policy: $policy, value: ['password' => 'hunter2']));

		$clean = ['q' => 'nothing here', 'n' => 3];
		$this->assertSame($clean, $service->filterOutputValue(policy: $policy, value: $clean));
		$object = new \stdClass();
		$object->q = 'nothing here';
		$this->assertSame($object, $service->filterOutputValue(policy: $policy, value: $object));

		$url = $service->filterOutputValue(policy: $policy, value: ['u' => 'postgres://u:secretpw@h/db']);
		$this->assertStringNotContainsString('secretpw', (string)json_encode($url));
		$this->assertStringContainsString('postgres://', (string)json_encode($url, JSON_UNESCAPED_SLASHES));

		$broken = $service->filterOutputValue(policy: $policy, value: ['h' => 'Authorization: Bearer abcdefghijklmnop"x']);
		$this->assertIsString($broken);
		$this->assertStringNotContainsString('abcdefghijklmnop', $broken);
	}//end testFilterOutputValueShapes()

	/**
	 * The screen leaves a frame without the screened key as it is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-with-the-output-filter-off-tool-details-are-shown-as-they-are
	 */
	public function testScreenSkipsAbsentKeys(): void {
		$frames = [];
		$channel = new StreamYieldChannel();
		$channel->onToolResult(callback: static function (array $payload) use (&$frames): void {
			$frames[] = $payload;
		});
		$channel->screenToolDetails(screen: static fn (mixed $value): string => 'screened');
		$channel->emitToolResult(payload: ['toolId' => 't', 'isError' => true]);
		$channel->emitToolResult(payload: ['toolId' => 't', 'result' => 'raw', 'isError' => false]);

		$this->assertSame(
			[['toolId' => 't', 'isError' => true], ['toolId' => 't', 'result' => 'screened', 'isError' => false]],
			$frames
		);

	}//end testScreenSkipsAbsentKeys()

	/**
	 * A RunStepBus over an in-memory cache.
	 *
	 * @return RunStepBus
	 */
	private function bus(): RunStepBus {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store) {
			return ($store[$key] ?? null);
		});
		$cache->method('remove')->willReturnCallback(static function (string $key) use (&$store): bool {
			unset($store[$key]);
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new RunStepBus($factory);
	}//end bus()

	/**
	 * Run one cli tools/call through McpRunController and return its JSON-RPC data.
	 *
	 * @param RunStepBus                  $bus       The step bus the call records into.
	 * @param GuardrailPolicyService|null $guardrail The guardrail service, or none.
	 * @param array<string, mixed>        $sent      Filled with the arguments the tool received.
	 * @param boolean                     $fails     Whether the tool throws.
	 * @param string                      $conversationOrg The organisation of the conversation the
	 *                                                     token names; `#absent` reads no conversation,
	 *                                                     `#throws` makes the read throw.
	 * @param string|null                 $resultJson   The JSON the tool returns; defaults to a
	 *                                                  result carrying the key.
	 * @param string                      $conversationId The conversation id the token binds.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Test helper: each parameter is one scenario knob.
	 */
	private function cliCall(
		RunStepBus $bus,
		?GuardrailPolicyService $guardrail,
		array &$sent,
		bool $fails = false,
		string $conversationOrg = 'org-1',
		?string $resultJson = null,
		string $conversationId = 'c1',
	): array {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturn(
			['runId' => 'r', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => $conversationId]
		);

		// The agent belongs to another organisation than the conversation, in
		// both places an organisation could be read from.
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['tools' => ['openregister.contact.*'], 'organisation' => 'org-2']);
		$agent->setOrganisation('org-2');
		$conversation = null;
		if (str_starts_with($conversationOrg, '#') === false) {
			$conversation = $this->conversation(organisation: $conversationOrg);
		}

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (string $id, ?array $extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($agent, $conversation, $conversationOrg): ?ObjectEntity {
				if ($schema !== 'agentsession') {
					return $agent;
				}

				$this->conversationReads++;

				if ($conversationOrg === '#throws') {
					throw new \RuntimeException('not found');
				}

				return $conversation;
			}
		);

		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);

		$resultJson ??= (string)json_encode(['result' => ['note' => 'key ' . self::SECRET], 'isError' => false]);
		$functionInfo = new class($resultJson, $fails) {
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
			 * @param string $json  The result JSON.
			 * @param bool   $fails Whether the call throws.
			 */
			public function __construct(private string $json, private bool $fails) {
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
				if ($this->fails === true) {
					throw new \RuntimeException('tool failed');
				}

				return $this->json;
			}
		};
		$toolLoop = $this->createMock(ToolLoop::class);
		$toolLoop->method('buildFunctionInfos')->willReturn([$functionInfo]);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($name === 'Authorization') ? 'Bearer good' : ''
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$body = '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"' . self::TOOL . '","arguments":{"q":"find ' . self::SECRET . '"}}}';
		$controller = new class($request, $tokens, $objects, $facade, new ToolGrantResolver(), $toolLoop, $this->createMock(ToolSearchService::class), $userManager, $this->createMock(IUserSession::class), $this->createMock(IThrottler::class), $bus, new NullLogger(), $guardrail, $body) extends McpRunController {
			// phpcs:ignore
			public function __construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $runStepBus, $logger, $guardrail, private string $rawBody) {
				parent::__construct($request, $tokens, $objects, $facade, $grant, $toolLoop, $search, $userManager, $userSession, $throttler, $runStepBus, $logger, null, null, $guardrail);
			}

			// phpcs:ignore
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};

		$response = $controller->handle();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$sent = $functionInfo->received;

		return $data;
	}//end cliCall()

	/**
	 * A conversation of the given organisation.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(string $organisation): ObjectEntity {
		$conversation = new ObjectEntity();
		$conversation->setUuid('c1');
		$conversation->setObject(['userId' => 'alice', 'agentId' => 'agent-1']);
		$conversation->setOrganisation($organisation);

		return $conversation;
	}//end conversation()

	/**
	 * Under `redact`, the recorded cli step, which the stream replays and the
	 * blocking send returns as `toolCalls`, holds neither the key in the
	 * arguments nor the key in the result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-cli-turns-recorded-steps-are-filtered-before-they-are-stored
	 */
	public function testRecordedCliStepIsRedacted(): void {
		$bus = $this->bus();
		$sent = [];
		$data = $this->cliCall(bus: $bus, guardrail: $this->guardrail(action: 'redact'), sent: $sent);

		$steps = $bus->drain(conversationId: 'c1');
		$this->assertCount(1, $steps);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($steps[0]['arguments']));
		$this->assertStringNotContainsString(self::SECRET, $steps[0]['result']);
		$this->assertStringContainsString('"note"', $steps[0]['result']);

		$this->assertSame('find ' . self::SECRET, $sent['q']);
		$this->assertStringContainsString(self::SECRET, (string)json_encode($data['result']));
	}//end testRecordedCliStepIsRedacted()

	/**
	 * Under `redact`, a cli step whose tool failed records the redacted arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-cli-turns-recorded-steps-are-filtered-before-they-are-stored
	 */
	public function testFailedCliStepRecordsRedactedArguments(): void {
		$bus = $this->bus();
		$sent = [];
		$this->cliCall(bus: $bus, guardrail: $this->guardrail(action: 'redact'), sent: $sent, fails: true);

		$steps = $bus->read(conversationId: 'c1');
		$this->assertSame(RunStepBus::OUTCOME_ERROR, $steps[0]['outcome']);
		$this->assertStringNotContainsString(self::SECRET, (string)json_encode($steps[0]['arguments']));
		$this->assertSame('find ' . self::SECRET, $sent['q']);
	}//end testFailedCliStepRecordsRedactedArguments()

	/**
	 * Under `block`, the recorded cli step shows the placeholder for both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-blocked-tool-detail-is-shown-as-withheld
	 */
	public function testBlockedCliStepShowsThePlaceholder(): void {
		$bus = $this->bus();
		$sent = [];
		$this->cliCall(bus: $bus, guardrail: $this->guardrail(action: 'block'), sent: $sent);

		$steps = $bus->read(conversationId: 'c1');
		$this->assertIsString($steps[0]['arguments']);
		$this->assertStringContainsString('withheld', $steps[0]['arguments']);
		$this->assertStringStartsWith('This response was withheld', $steps[0]['result']);
		$this->assertSame('find ' . self::SECRET, $sent['q']);
	}//end testBlockedCliStepShowsThePlaceholder()

	/**
	 * With the output filter off, or no guardrail service, the recorded cli step is
	 * the raw arguments and result, as before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-with-the-output-filter-off-tool-details-are-shown-as-they-are
	 */
	public function testWithoutAnOutputFilterTheCliStepIsUnchanged(): void {
		$json = (string)json_encode(['result' => ['note' => 'key ' . self::SECRET, 'url' => 'https://example.org/x'], 'isError' => false]);
		foreach ([$this->guardrail(action: 'off'), null] as $guardrail) {
			$bus = $this->bus();
			$sent = [];
			$this->cliCall(bus: $bus, guardrail: $guardrail, sent: $sent, resultJson: $json);

			$steps = $bus->read(conversationId: 'c1');
			$this->assertSame(['q' => 'find ' . self::SECRET], $steps[0]['arguments']);
			$this->assertSame($json, $steps[0]['result']);
		}
	}//end testWithoutAnOutputFilterTheCliStepIsUnchanged()

	/**
	 * The frames follow the conversation's policy, the one that filters the
	 * answer, not the organisation the tools were built for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-tool-details-follow-the-conversations-policy
	 */
	public function testFramesFollowTheConversationsPolicy(): void {
		$sent = [];
		$run = $this->streamOneCall(guardrail: $this->guardrail(action: 'redact'), sent: $sent, conversationOrg: 'org-2', agentOrg: 'org-1');

		$this->assertSame(['q' => 'find ' . self::SECRET], $run['frames'][0][1]['arguments']);
		$this->assertSame(['note' => 'key ' . self::SECRET], $run['frames'][1][1]['result']);
	}//end testFramesFollowTheConversationsPolicy()

	/**
	 * The cli step follows the conversation's policy, not the agent's organisation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-tool-details-follow-the-conversations-policy
	 */
	public function testCliStepFollowsTheConversationsPolicy(): void {
		$bus = $this->bus();
		$sent = [];
		$this->cliCall(bus: $bus, guardrail: $this->guardrail(action: 'redact', organisation: 'org-2'), sent: $sent);

		$steps = $bus->read(conversationId: 'c1');
		$this->assertSame(['q' => 'find ' . self::SECRET], $steps[0]['arguments']);
		$this->assertStringContainsString(self::SECRET, $steps[0]['result']);
	}//end testCliStepFollowsTheConversationsPolicy()

	/**
	 * A cli result is JSON with escaped slashes; a URL credential in it is still
	 * redacted, and the rest of the result keeps its shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-cli-turns-recorded-steps-are-filtered-before-they-are-stored
	 */
	public function testCliResultWithEscapedSlashesIsFiltered(): void {
		$bus = $this->bus();
		$sent = [];
		$json = (string)json_encode(['result' => ['dsn' => 'postgres://admin:Sup3rS3cretPw@db.internal:5432/app'], 'isError' => false]);
		$this->assertStringContainsString('\/\/', $json);
		$this->cliCall(bus: $bus, guardrail: $this->guardrail(action: 'redact'), sent: $sent, resultJson: $json);

		$result = $bus->read(conversationId: 'c1')[0]['result'];
		$this->assertStringNotContainsString('Sup3rS3cretPw', $result);
		$this->assertStringContainsString('db.internal', $result);
		$this->assertFalse(json_decode($result, true)['isError']);
	}//end testCliResultWithEscapedSlashesIsFiltered()

	/**
	 * A cli result that is not JSON is filtered as text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-a-cli-turns-recorded-steps-are-filtered-before-they-are-stored
	 */
	public function testPlainTextCliResultIsFiltered(): void {
		$bus = $this->bus();
		$sent = [];
		$this->cliCall(bus: $bus, guardrail: $this->guardrail(action: 'redact'), sent: $sent, resultJson: 'key ' . self::SECRET);

		$result = $bus->read(conversationId: 'c1')[0]['result'];
		$this->assertStringStartsWith('key ', $result);
		$this->assertStringNotContainsString(self::SECRET, $result);
	}//end testPlainTextCliResultIsFiltered()

	/**
	 * When the conversation cannot be read (the read throws, or no conversation
	 * comes back), the cli step shows the withheld placeholder for the arguments
	 * and the result, even where the instance default filters nothing. The tool
	 * and the CLI still get the real values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/output-filter-tool-details/specs/agent-guardrails/spec.md#scenario-an-unreadable-conversation-shows-the-cli-step-as-withheld
	 */
	public function testUnreadableConversationWithholdsTheCliStep(): void {
		foreach (['#throws', '#absent'] as $conversationOrg) {
			$bus = $this->bus();
			$sent = [];
			$data = $this->cliCall(
				bus: $bus,
				guardrail: $this->guardrail(action: 'redact'),
				sent: $sent,
				conversationOrg: $conversationOrg
			);

			$steps = $bus->read(conversationId: 'c1');
			$this->assertCount(expectedCount: 1, haystack: $steps, message: $conversationOrg);
			$this->assertStringStartsWith(prefix: 'This response was withheld', string: $steps[0]['arguments'], message: $conversationOrg);
			$this->assertStringStartsWith(prefix: 'This response was withheld', string: $steps[0]['result'], message: $conversationOrg);
			$this->assertSame(expected: 'find ' . self::SECRET, actual: $sent['q'], message: $conversationOrg);
			$this->assertStringContainsString(needle: self::SECRET, haystack: (string)json_encode(value: $data['result']), message: $conversationOrg);
		}

		// A run without a conversation reads none.
		$this->conversationReads = 0;
		$sent = [];
		$this->cliCall(bus: $this->bus(), guardrail: $this->guardrail(action: 'redact'), sent: $sent, conversationId: '');
		$this->assertSame(expected: 0, actual: $this->conversationReads);
		$this->assertSame(expected: 'find ' . self::SECRET, actual: $sent['q']);
	}//end testUnreadableConversationWithholdsTheCliStep()

}//end class
