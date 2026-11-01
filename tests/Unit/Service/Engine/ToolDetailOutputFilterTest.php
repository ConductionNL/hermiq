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
use OCA\Hermiq\Service\Engine\RunStepBus;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
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
 * @covers \OCA\Hermiq\Service\Engine\ToolLoop
 * @covers \OCA\Hermiq\Service\Engine\StreamYieldChannel
 * @covers \OCA\Hermiq\Service\GuardrailPolicyService
 * @covers \OCA\Hermiq\Controller\McpRunController
 * @covers \OCA\Hermiq\Service\Engine\RunStepBus
 */
final class ToolDetailOutputFilterTest extends TestCase {

	private const SECRET = 'sk-probeAAAABBBBCCCCDDDD';

	private const TOOL = 'openregister_contact_search';

	/**
	 * A guardrail service whose effective policy has the given output action.
	 *
	 * @param string $action `off`, `redact` or `block`.
	 *
	 * @return GuardrailPolicyService
	 */
	private function guardrail(string $action): GuardrailPolicyService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		$service = $this->getMockBuilder(GuardrailPolicyService::class)
			->setConstructorArgs([$this->createMock(ObjectService::class), new RedactionService($config)])
			->onlyMethods(['effectivePolicyFor'])
			->getMock();
		$service->method('effectivePolicyFor')->willReturn(
			[
				'source' => 'organisation',
				'inputFilters' => ['piiAction' => 'off', 'promptInjectionAction' => 'off'],
				'outputFilters' => ['piiAction' => $action],
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
	 * Run one in-process tool call through ToolLoop and collect the stream frames.
	 *
	 * @param GuardrailPolicyService|null $guardrail The guardrail service, or none.
	 * @param array<string, mixed>        $sent      Filled with the arguments the tool received.
	 *
	 * @return array{frames: list<array{0: string, 1: array<string, mixed>}>, model: string}
	 */
	private function streamOneCall(?GuardrailPolicyService $guardrail, array &$sent): array {
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

		$frames = [];
		$channel = new StreamYieldChannel();
		$channel->onToolCall(callback: static function (array $payload) use (&$frames): void {
			$frames[] = ['tool_call', $payload];
		});
		$channel->onToolResult(callback: static function (array $payload) use (&$frames): void {
			$frames[] = ['tool_result', $payload];
		});

		$infos = $loop->buildFunctionInfos(functions: [$this->descriptor()], channel: $channel, organisation: 'org-1');
		$model = (string)$infos[0]->callWithArguments(['q' => 'find ' . self::SECRET]);

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

		$this->assertSame(['password' => '***'], $service->filterOutputValue(policy: $policy, value: ['password' => 'hunter2']));

		$clean = ['q' => 'nothing here', 'n' => 3];
		$this->assertSame($clean, $service->filterOutputValue(policy: $policy, value: $clean));

		$broken = $service->filterOutputValue(policy: $policy, value: ['h' => 'Authorization: Bearer abcdefghijklmnop"x']);
		$this->assertIsString($broken);
		$this->assertStringNotContainsString('abcdefghijklmnop', $broken);
	}//end testFilterOutputValueShapes()

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
	 *
	 * @return array<string, mixed>
	 */
	private function cliCall(RunStepBus $bus, ?GuardrailPolicyService $guardrail, array &$sent): array {
		$tokens = $this->createMock(RunTokenService::class);
		$tokens->method('verify')->willReturn(
			['runId' => 'r', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => 'c1']
		);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['tools' => ['openregister.contact.*'], 'organisation' => 'org-1']);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($agent);

		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn([$this->descriptor()]);

		$functionInfo = new class(self::SECRET) {
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
			 * @param string $secret The key the result carries.
			 */
			public function __construct(private string $secret) {
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
				return (string)json_encode(['result' => ['note' => 'key ' . $this->secret], 'isError' => false]);
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
		$this->assertStringContainsString('withheld', $steps[0]['result']);
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
		foreach ([$this->guardrail(action: 'off'), null] as $guardrail) {
			$bus = $this->bus();
			$sent = [];
			$this->cliCall(bus: $bus, guardrail: $guardrail, sent: $sent);

			$steps = $bus->read(conversationId: 'c1');
			$this->assertSame(['q' => 'find ' . self::SECRET], $steps[0]['arguments']);
			$this->assertSame(
				(string)json_encode(['result' => ['note' => 'key ' . self::SECRET], 'isError' => false]),
				$steps[0]['result']
			);
		}
	}//end testWithoutAnOutputFilterTheCliStepIsUnchanged()

}//end class
