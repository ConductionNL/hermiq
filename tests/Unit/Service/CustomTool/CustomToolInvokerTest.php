<?php

/**
 * CustomToolInvoker unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-validates-its-input-and-calls-only-through-the-flow-tool-or-integriq-req-custool-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CustomTool;

use OCA\Hermiq\Service\CustomTool\CustomToolDefinition;
use OCA\Hermiq\Service\CustomTool\CustomToolInvoker;
use OCA\Hermiq\Service\CustomTool\CustomToolService;
use OCA\Hermiq\Service\Handoff\FlowTurn;
use OCA\Hermiq\Service\Handoff\WebhookHandoffs;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Checking a custom tool's arguments and calling its flow or integriq source.
 */
final class CustomToolInvokerTest extends TestCase {

	/** @var list<array{toolId: string, arguments: array<string, mixed>}> */
	private array $facadeCalls = [];

	/** @var list<array<string, mixed>> */
	private array $sourceCalls = [];

	/**
	 * A call's arguments in a conversation turn, as the agent engine sends them.
	 */
	private const IN_A_TURN = [
		'postcode'    => '1234AB',
		'huisnummer'  => 3,
		'triggeredBy' => 'alice',
		'hermiqTurn'  => ['agentId' => 'agent-1', 'conversationId' => 'conv-1'],
	];

	/**
	 * The webhook handoffs the container resolves, null when it resolves none.
	 *
	 * @var (WebhookHandoffs&MockObject)|null
	 */
	private ?WebhookHandoffs $webhookHandoffs = null;

	/** @var list<array{id: mixed, options: ObjectFindOptions|null}> */
	private array $sourceReads = [];

	/**
	 * The tool the service finds, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $tool = null;

	/**
	 * What the fake integriq call log answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $callLog = ['statusCode' => 200, 'response' => ['body' => '{"rule":"APV 2:10"}']];

	/**
	 * The integriq BAG tool.
	 *
	 * @param array<string, mixed> $target Changes to its target.
	 *
	 * @return array<string, mixed>
	 */
	private static function bagTool(array $target=[]): array {
		$tool = CustomToolDefinition::normalise(
			payload: [
				'slug' => 'bag_adres',
				'name' => 'Adres opzoeken in BAG',
				'description' => 'Finds an address in the BAG.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'postcode' => ['type' => 'string'],
						'huisnummer' => ['type' => 'integer'],
					],
					'required' => ['postcode', 'huisnummer'],
				],
				'changesData' => 'no',
				'target' => array_replace(
					['type' => 'integriq', 'source' => 'bag-api', 'method' => 'GET', 'path' => '/adressen?postcode={{input.postcode}}&huisnummer={{input.huisnummer}}'],
					$target
				),
			]
		);
		$tool['version'] = '1.0.3';

		return $tool;
	}//end bagTool()

	/**
	 * The invoker over a fake service, facade, source read and CallService.
	 *
	 * @param bool $withIntegriq Whether integriq's CallService resolves.
	 *
	 * @return CustomToolInvoker
	 */
	private function invoker(bool $withIntegriq=true): CustomToolInvoker {
		$service = $this->createMock(CustomToolService::class);
		$service->method('findEnabledForActingUser')->willReturnCallback(
			fn (string $slug): ?array => ($this->tool !== null && $this->tool['slug'] === $slug) ? $this->tool : null
		);

		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId, array $arguments): array {
				$this->facadeCalls[] = ['toolId' => $toolId, 'arguments' => $arguments];
				return ['result' => ['runUuid' => 'run-1', 'queued' => true], 'isError' => false];
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options=null): ?ObjectEntity {
				$this->sourceReads[] = ['id' => $id, 'options' => $options];
				$source = new ObjectEntity();
				$source->setUuid('source-uuid');
				return $source;
			}
		);

		$test        = $this;
		$callService = new class ($test) {
			/**
			 * @param CustomToolInvokerTest $test The test that records the calls.
			 */
			public function __construct(private readonly CustomToolInvokerTest $test) {
			}

			/**
			 * Records the call and answers with the test's call log.
			 *
			 * @param ObjectEntity         $source   The source.
			 * @param string               $endpoint The endpoint.
			 * @param string               $method   The method.
			 * @param array<string, mixed> $config   The request configuration.
			 *
			 * @return ObjectEntity
			 */
			public function call(ObjectEntity $source, string $endpoint='', string $method='GET', array $config=[]): ObjectEntity {
				return $this->test->recordSourceCall(source: $source, endpoint: $endpoint, method: $method, config: $config);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($facade, $callService, $withIntegriq): object {
				if ($id === ToolRegistryFacade::class) {
					return $facade;
				}

				if ($id === WebhookHandoffs::class && $this->webhookHandoffs !== null) {
					return $this->webhookHandoffs;
				}

				if ($withIntegriq === true && $id === 'OCA\Integriq\Service\CallService') {
					return $callService;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new CustomToolInvoker(customTools: $service, objectService: $objects, container: $container, logger: new NullLogger());
	}//end invoker()

	/**
	 * The fake CallService's record of one call.
	 *
	 * @param ObjectEntity         $source   The source.
	 * @param string               $endpoint The endpoint.
	 * @param string               $method   The method.
	 * @param array<string, mixed> $config   The request configuration.
	 *
	 * @return ObjectEntity The call log.
	 */
	public function recordSourceCall(ObjectEntity $source, string $endpoint, string $method, array $config): ObjectEntity {
		$this->sourceCalls[] = ['source' => $source->getUuid(), 'endpoint' => $endpoint, 'method' => $method, 'config' => $config];
		$log = new ObjectEntity();
		$log->setObject($this->callLog);

		return $log;
	}//end recordSourceCall()

	/**
	 * A flow tool's call runs openregister.runFlow for that flow only, with the owner.
	 *
	 * @return void
	 */
	public function testAFlowTargetRunsThatFlowForTheOwner(): void {
		$this->tool = CustomToolDefinition::normalise(payload: CustomToolDefinitionTest::vergunningcheck());

		$answer = $this->invoker()->invoke(
			toolId: 'hermiq.custom_vergunningcheck',
			arguments: ['adres' => 'Markt 3', 'activiteit' => 'terras', 'triggeredBy' => 'behandelaar', 'flowId' => 'other-flow']
		);

		$this->assertSame(['error' => ['code' => 'invalid_argument', 'message' => 'flowId: this tool has no such input']], array_diff_key($answer, ['customTool' => true]));
		$this->assertSame([], $this->facadeCalls);

		$answer = $this->invoker()->invoke(
			toolId: 'hermiq.custom_vergunningcheck',
			arguments: ['adres' => 'Markt 3', 'activiteit' => 'terras', 'triggeredBy' => 'behandelaar']
		);

		$this->assertSame(
			[['toolId' => 'openregister.runFlow', 'arguments' => ['flowId' => 'flow-42', 'input' => ['adres' => 'Markt 3', 'activiteit' => 'terras'], 'triggeredBy' => 'behandelaar']]],
			$this->facadeCalls
		);
		$this->assertSame(['runUuid' => 'run-1', 'queued' => true], $answer['result']);
		$this->assertSame(['id' => 'hermiq.custom_vergunningcheck', 'name' => 'Vergunningcheck', 'version' => '', 'target' => 'flow:flow-42'], $answer['customTool']);
	}//end testAFlowTargetRunsThatFlowForTheOwner()

	/**
	 * A flow is never queued on nobody's behalf.
	 *
	 * @return void
	 */
	public function testAFlowTargetWithoutAnOwnerIsRefused(): void {
		$this->tool = CustomToolDefinition::normalise(payload: CustomToolDefinitionTest::vergunningcheck());

		$answer = $this->invoker()->invoke(toolId: 'hermiq.custom_vergunningcheck', arguments: ['adres' => 'Markt 3']);

		$this->assertSame('owner_unresolved', $answer['error']['code']);
		$this->assertSame([], $this->facadeCalls);
	}//end testAFlowTargetWithoutAnOwnerIsRefused()

	/**
	 * Wrong input is refused, naming the field, before any call.
	 *
	 * @return void
	 */
	public function testWrongInputIsRefusedBeforeTheCall(): void {
		$this->tool = CustomToolDefinition::normalise(payload: CustomToolDefinitionTest::vergunningcheck());
		$invoker    = $this->invoker();

		$enum     = $invoker->invoke(toolId: 'hermiq.custom_vergunningcheck', arguments: ['adres' => 'Markt 3', 'activiteit' => 'bouw', 'triggeredBy' => 'u']);
		$missing  = $invoker->invoke(toolId: 'hermiq.custom_vergunningcheck', arguments: ['activiteit' => 'kap', 'triggeredBy' => 'u']);
		$wrongTyp = $invoker->invoke(toolId: 'hermiq.custom_vergunningcheck', arguments: ['adres' => 3, 'triggeredBy' => 'u']);

		$this->assertSame(['code' => 'invalid_argument', 'message' => 'activiteit: must be one of terras, evenement, kap'], $enum['error']);
		$this->assertSame(['code' => 'invalid_argument', 'message' => 'adres: this input is required'], $missing['error']);
		$this->assertSame(['code' => 'invalid_argument', 'message' => 'adres: must be a string'], $wrongTyp['error']);
		$this->assertSame([], $this->facadeCalls);
	}//end testWrongInputIsRefusedBeforeTheCall()

	/**
	 * A tool the acting user cannot read, or an id that is no custom tool, is unknown.
	 *
	 * @return void
	 */
	public function testAToolTheUserCannotReadIsUnknown(): void {
		$this->tool = null;

		$this->assertSame('unknown_tool', $this->invoker()->invoke(toolId: 'hermiq.custom_vergunningcheck', arguments: [])['error']['code']);
		$this->assertSame('unknown_tool', $this->invoker()->invoke(toolId: 'hermiq.listFiles', arguments: [])['error']['code']);
		$this->assertSame([], $this->facadeCalls);
	}//end testAToolTheUserCannotReadIsUnknown()

	/**
	 * An integriq tool calls CallService on its source with the rendered path, delimited.
	 *
	 * @return void
	 */
	public function testAnIntegriqTargetCallsTheSourceThroughCallService(): void {
		$this->tool = self::bagTool();

		$answer = $this->invoker()->invoke(toolId: 'hermiq.custom_bag_adres', arguments: ['postcode' => '1234 AB', 'huisnummer' => 3]);

		$this->assertSame('bag-api', $this->sourceReads[0]['id']);
		$this->assertSame('source', $this->sourceReads[0]['options']?->schema);
		$this->assertSame(
			[['source' => 'source-uuid', 'endpoint' => '/adressen?postcode=1234%20AB&huisnummer=3', 'method' => 'GET', 'config' => []]],
			$this->sourceCalls
		);
		$this->assertSame(200, $answer['status']);
		$this->assertSame(CustomToolInvoker::UNTRUSTED_BEGIN . "\n" . '{"rule":"APV 2:10"}' . "\n" . CustomToolInvoker::UNTRUSTED_END, $answer['content']);
		$this->assertFalse($answer['truncated']);
		$this->assertArrayNotHasKey('error', $answer);
		$this->assertSame('integriq:bag-api GET /adressen?postcode={{input.postcode}}&huisnummer={{input.huisnummer}}', $answer['customTool']['target']);
		$this->assertSame('1.0.3', $answer['customTool']['version']);
	}//end testAnIntegriqTargetCallsTheSourceThroughCallService()

	/**
	 * The body template is rendered into the JSON body of the call.
	 *
	 * @return void
	 */
	public function testTheBodyTemplateIsRendered(): void {
		$this->tool = self::bagTool(target: ['method' => 'POST', 'path' => '/zoek', 'bodyTemplate' => '{"q": "nr {{input.huisnummer}} in {{input.postcode}}", "nr": {{input.huisnummer}}}']);

		$this->invoker()->invoke(toolId: 'hermiq.custom_bag_adres', arguments: ['postcode' => '1234 "AB"', 'huisnummer' => 3]);

		$this->assertSame(['json' => ['q' => 'nr 3 in 1234 "AB"', 'nr' => 3]], $this->sourceCalls[0]['config']);
		$this->assertSame('POST', $this->sourceCalls[0]['method']);
	}//end testTheBodyTemplateIsRendered()

	/**
	 * A long answer is cut to 20 KB, and a failing status is an error.
	 *
	 * @return void
	 */
	public function testALongAnswerIsCappedAndAFailingStatusIsAnError(): void {
		$this->tool    = self::bagTool();
		$this->callLog = ['statusCode' => 502, 'response' => ['body' => str_repeat('x', 30000)]];

		$answer = $this->invoker()->invoke(toolId: 'hermiq.custom_bag_adres', arguments: ['postcode' => '1234AB', 'huisnummer' => 1]);

		$this->assertTrue($answer['truncated']);
		$this->assertSame(CustomToolInvoker::MAX_ANSWER_BYTES, substr_count($answer['content'], 'x'));
		$this->assertSame(['code' => 'tool_failed', 'message' => 'The system answered with status 502.'], $answer['error']);
	}//end testALongAnswerIsCappedAndAFailingStatusIsAnError()

	/**
	 * Without integriq the tool answers an error and calls nothing.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsCalled(): void {
		$this->tool = self::bagTool();

		$answer = $this->invoker(withIntegriq: false)->invoke(toolId: 'hermiq.custom_bag_adres', arguments: ['postcode' => '1234AB', 'huisnummer' => 1]);

		$this->assertSame('tool_failed', $answer['error']['code']);
		$this->assertSame([], $this->sourceCalls);
		$this->assertSame([], $this->sourceReads);
	}//end testWithoutIntegriqNothingIsCalled()

	/**
	 * A waiting tool opens its webhook handoff first and hands the workflow the result
	 * address and token in the body; the agent gets the handoff, never the token.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function testAWaitingToolHandsTheWorkflowItsResultAddressAndToken(): void {
		$this->tool = self::bagTool(
			target: ['method' => 'POST', 'path' => '/webhook/bag', 'bodyTemplate' => '{"nr": {{input.huisnummer}}}', 'waitForCallback' => true, 'deadlineHours' => 48]
		);
		$this->webhookHandoffs = $this->createMock(originalClassName: WebhookHandoffs::class);
		$this->webhookHandoffs->expects($this->once())->method('open')
			->willReturnCallback(
				function (FlowTurn $turn, string $label, string $target, ?int $deadlineHours): array {
					$this->assertSame(expected: ['agent-1', 'conv-1', 'alice'], actual: [$turn->agentId, $turn->conversationId, $turn->owner]);
					$this->assertSame(expected: ['Adres opzoeken in BAG', 'bag_adres', 48], actual: [$label, $target, $deadlineHours]);
					return ['handoffId' => 'h-1', 'hermiqResultUrl' => 'https://nc/apps/hermiq/api/handoffs/h-1/result', 'hermiqResultToken' => 'secret-token'];
				}
			);
		$this->webhookHandoffs->expects($this->never())->method('abandon');

		$answer = $this->invoker()->invoke(
			toolId: 'hermiq.custom_bag_adres',
			arguments: self::IN_A_TURN
		);

		$this->assertSame(
			expected: ['json' => ['nr' => 3, 'hermiqResultUrl' => 'https://nc/apps/hermiq/api/handoffs/h-1/result', 'hermiqResultToken' => 'secret-token']],
			actual: $this->sourceCalls[0]['config']
		);
		$this->assertSame(expected: 'h-1', actual: $answer['handoffId']);
		$this->assertSame(expected: CustomToolInvoker::STARTED, actual: $answer['message']);
		$this->assertStringNotContainsString(needle: 'secret-token', haystack: (string)json_encode($answer));
	}//end testAWaitingToolHandsTheWorkflowItsResultAddressAndToken()

	/**
	 * A waiting tool whose workflow cannot be started closes its handoff at once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function testAWaitingToolWhoseCallFailsClosesItsHandoff(): void {
		$this->tool    = self::bagTool(target: ['method' => 'POST', 'path' => '/webhook/bag', 'waitForCallback' => true]);
		$this->callLog = ['statusCode' => 502, 'response' => ['body' => 'down']];
		$this->webhookHandoffs = $this->createMock(originalClassName: WebhookHandoffs::class);
		$this->webhookHandoffs->method('open')->willReturn(['handoffId' => 'h-1', 'hermiqResultUrl' => 'https://nc/r', 'hermiqResultToken' => 't']);
		$this->webhookHandoffs->expects($this->once())->method('abandon')->with('h-1', 'The system answered with status 502.')->willReturn(true);

		$answer = $this->invoker()->invoke(
			toolId: 'hermiq.custom_bag_adres',
			arguments: self::IN_A_TURN
		);

		$this->assertSame(expected: 'tool_failed', actual: $answer['error']['code']);
		$this->assertArrayNotHasKey(key: 'handoffId', array: $answer);
		$this->assertSame(
			expected: ['json' => ['postcode' => '1234AB', 'huisnummer' => 3, 'hermiqResultUrl' => 'https://nc/r', 'hermiqResultToken' => 't']],
			actual: $this->sourceCalls[0]['config']
		);
	}//end testAWaitingToolWhoseCallFailsClosesItsHandoff()

	/**
	 * A waiting tool outside a conversation, or without the handoffs, calls nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function testAWaitingToolOutsideAConversationCallsNothing(): void {
		$this->tool = self::bagTool(target: ['method' => 'POST', 'path' => '/webhook/bag', 'waitForCallback' => true]);
		$this->webhookHandoffs = $this->createMock(originalClassName: WebhookHandoffs::class);
		$this->webhookHandoffs->expects($this->never())->method('open');

		$withoutTurn = self::IN_A_TURN;
		unset($withoutTurn['hermiqTurn']);
		$noTurn = $this->invoker()->invoke(toolId: 'hermiq.custom_bag_adres', arguments: $withoutTurn);
		$tried  = $this->invoker()->run(tool: $this->tool, arguments: ['postcode' => '1234AB', 'huisnummer' => 3], owner: 'alice');
		$this->webhookHandoffs = null;
		$noHandoffs = $this->invoker()->invoke(
			toolId: 'hermiq.custom_bag_adres',
			arguments: self::IN_A_TURN
		);

		$this->assertSame(expected: 'tool_failed', actual: $noTurn['error']['code']);
		$this->assertSame(expected: 'tool_failed', actual: $tried['error']['code']);
		$this->assertSame(expected: 'tool_failed', actual: $noHandoffs['error']['code']);
		$this->assertSame(expected: [], actual: $this->sourceCalls);
	}//end testAWaitingToolOutsideAConversationCallsNothing()

	/**
	 * A body that is not a JSON object is kept under `payload` next to the result fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
	 */
	public function testABodyThatIsNotAnObjectIsKeptUnderPayload(): void {
		$this->tool = self::bagTool(
			target: ['method' => 'POST', 'path' => '/webhook/bag', 'bodyTemplate' => '[{{input.huisnummer}}]', 'waitForCallback' => true]
		);
		$this->webhookHandoffs = $this->createMock(originalClassName: WebhookHandoffs::class);
		$this->webhookHandoffs->method('open')->willReturn(['handoffId' => 'h-1', 'hermiqResultUrl' => 'https://nc/r', 'hermiqResultToken' => 't']);

		$this->invoker()->invoke(
			toolId: 'hermiq.custom_bag_adres',
			arguments: self::IN_A_TURN
		);

		$this->assertSame(expected: ['json' => ['payload' => [3], 'hermiqResultUrl' => 'https://nc/r', 'hermiqResultToken' => 't']], actual: $this->sourceCalls[0]['config']);
	}//end testABodyThatIsNotAnObjectIsKeptUnderPayload()
}//end class
