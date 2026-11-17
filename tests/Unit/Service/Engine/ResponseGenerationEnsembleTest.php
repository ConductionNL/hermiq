<?php

/**
 * An ensemble turn through ResponseGenerationHandler: references first without
 * tools, then the agent's own model merges with its tools.
 *
 * @category Tests
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
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The ensemble turn end to end, with stubbed drivers.
 *
 * @covers \OCA\Hermiq\Service\Engine\ResponseGenerationHandler
 * @uses \OCA\Hermiq\Service\Engine\ReferenceAnswerRound
 * @uses \OCA\Hermiq\Service\Engine\EnsembleUsage
 */
final class ResponseGenerationEnsembleTest extends TestCase {

	/**
	 * Each Fireworks call: its model, how many functions it was offered, and its system prompt.
	 *
	 * @var array<int, array{model: string, functions: int, system: string}>
	 */
	private array $calls = [];

	/**
	 * The model of the last call, for the usage it reports.
	 *
	 * @var string
	 */
	private string $lastModel = '';

	/**
	 * A provider factory whose every driver is Fireworks, named by the override model.
	 *
	 * @return ProviderFactory
	 */
	private function factory(): ProviderFactory {
		$factory = $this->createMock(originalClassName: ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn(['chatProvider' => 'fireworks']);
		$factory->method('fallbackChainFor')->willReturn([]);
		$factory->method('createChatDriver')->willReturnCallback(
			static function (array $llmConfig, ...$rest): ChatDriver {
				$args = func_get_args();
				return new ChatDriver(provider: 'fireworks', chat: null, model: (string)($args[10] ?? 'llama-agent'), credentialId: 'c', baseUrl: 'https://x');
			}
		);
		$factory->method('callFireworksChat')->willReturnCallback(
			function (string $credentialId, string $model, string $baseUrl, array $messageHistory, array $functions = []): string {
				$this->lastModel = $model;
				$this->calls[] = ['model' => $model, 'functions' => count($functions), 'system' => (string)$messageHistory[0]->content];
				return 'Answer from ' . $model;
			}
		);
		$factory->method('lastCallUsage')->willReturnCallback(
			fn (): array => ['promptTokens' => ($this->lastModel === 'llama-agent' ? 500 : 100), 'completionTokens' => 10]
		);
		return $factory;
	}//end factory()

	/**
	 * A tool loop offering one tool.
	 *
	 * @return ToolLoop
	 */
	private function loopWithATool(): ToolLoop {
		$loop = $this->createMock(originalClassName: ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn([['name' => 'hermiq.searchTools', 'description' => 'Find tools', 'parameters' => []]]);
		return $loop;
	}//end loopWithATool()

	/**
	 * An agent with this ensemble.
	 *
	 * @param array<string, mixed> $ensemble The ensemble.
	 *
	 * @return ObjectEntity
	 */
	private static function agent(array $ensemble): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['prompt' => 'You advise on policy.', 'ensemble' => $ensemble]);
		return $agent;
	}//end agent()

	/**
	 * Two references answer without tools; the agent's own model merges with its
	 * tools and the drafts in its prompt; the usage is the sum of all three calls.
	 *
	 * @return void
	 */
	public function testReferencesAnswerWithoutToolsAndTheAgentsModelMerges(): void {
		$handler = new ResponseGenerationHandler($this->factory(), $this->loopWithATool(), new NullLogger());
		$trace = new RunTraceCollector();

		$response = $handler->generateResponse(
			userMessage: 'Welke regels gelden voor een terrasvergunning?',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: self::agent(ensemble: ['enabled' => true, 'referenceModels' => [['provider' => 'fireworks', 'model' => 'ref-a'], ['provider' => 'fireworks', 'model' => 'ref-b']]]),
			trace: $trace
		);

		self::assertSame('Answer from llama-agent', $response);
		self::assertSame(['ref-a', 'ref-b', 'llama-agent'], array_column($this->calls, 'model'));
		self::assertSame([0, 0, 1], array_column($this->calls, 'functions'), 'Only the merging call is offered tools.');
		self::assertStringNotContainsString('DRAFT ANSWERS', $this->calls[0]['system']);
		self::assertStringContainsString('DRAFT ANSWERS FROM OTHER MODELS', $this->calls[2]['system']);
		self::assertStringContainsString("--- Draft 1 (fireworks ref-a) ---\nAnswer from ref-a", $this->calls[2]['system']);
		self::assertStringContainsString("--- Draft 2 (fireworks ref-b) ---\nAnswer from ref-b", $this->calls[2]['system']);

		self::assertSame(700, $handler->lastUsage['promptTokens']);
		self::assertSame(30, $handler->lastUsage['completionTokens']);
		self::assertSame(['ref-a', 'ref-b', 'llama-agent'], array_column($handler->lastUsage['usageByModel'], 'model'));

		self::assertSame('Asked 2 models', $trace->toArray()[0]['name']);
		self::assertSame(['Answer from ref-a', 'Answer from ref-b'], array_column($trace->referenceAnswers(), 'text'));
	}//end testReferencesAnswerWithoutToolsAndTheAgentsModelMerges()

	/**
	 * A budget at its cap calls no reference; the agent answers alone and the steps say why.
	 *
	 * @return void
	 */
	public function testABudgetAtItsCapCallsNoReference(): void {
		$budget = $this->createMock(originalClassName: BudgetService::class);
		$budget->method('isBlocked')->willReturn(true);
		$handler = new ResponseGenerationHandler($this->factory(), $this->loopWithATool(), new NullLogger(), null, null, null, $budget);
		$trace = new RunTraceCollector();

		$response = $handler->generateResponse(
			userMessage: 'Hi',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: self::agent(ensemble: ['enabled' => true, 'referenceModels' => [['provider' => 'fireworks', 'model' => 'ref-a'], ['provider' => 'fireworks', 'model' => 'ref-b']]]),
			trace: $trace
		);

		self::assertSame('Answer from llama-agent', $response);
		self::assertSame(['llama-agent'], array_column($this->calls, 'model'));
		self::assertStringNotContainsString('DRAFT ANSWERS', $this->calls[0]['system']);
		self::assertSame('Budget reached: answered from 0 models', $trace->toArray()[0]['name']);
		self::assertSame(500, $handler->lastUsage['promptTokens'], 'Only the agent\'s own call counted.');
		self::assertArrayNotHasKey('usageByModel', $handler->lastUsage);
	}//end testABudgetAtItsCapCallsNoReference()

	/**
	 * An agent without an enabled ensemble runs exactly one call, as before.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutAnEnsembleRunsOneCall(): void {
		$handler = new ResponseGenerationHandler($this->factory(), $this->loopWithATool(), new NullLogger());

		$handler->generateResponse(
			userMessage: 'Hi',
			context: ['text' => '', 'sources' => []],
			messageHistory: [],
			agent: self::agent(ensemble: ['enabled' => false, 'referenceModels' => [['provider' => 'fireworks', 'model' => 'ref-a'], ['provider' => 'fireworks', 'model' => 'ref-b']]])
		);

		self::assertSame(['llama-agent'], array_column($this->calls, 'model'));
		self::assertArrayNotHasKey('usageByModel', $handler->lastUsage);
	}//end testAnAgentWithoutAnEnsembleRunsOneCall()
}//end class
