<?php

/**
 * The answer cache on the provider path of ResponseGenerationHandler.
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
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\Engine\ResponseGenerationHandler
 */
class ResponseAnswerCacheTest extends TestCase {

	/**
	 * A factory on the Fireworks path; $calls counts the model calls.
	 *
	 * @param int $calls The model calls made.
	 */
	private function factory(int &$calls): ProviderFactory {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('getLlmConfig')->willReturn([]);
		$factory->method('createChatDriver')->willReturn(
			new ChatDriver(provider: 'fireworks', chat: null, model: 'llama', credentialId: 'c', baseUrl: 'https://x')
		);
		$factory->method('lastCallUsage')->willReturn(['promptTokens' => 40, 'completionTokens' => 8]);
		$factory->method('callFireworksChat')->willReturnCallback(
			static function () use (&$calls): string {
				$calls++;
				return 'From the model.';
			}
		);

		return $factory;
	}

	private function loop(): ToolLoop {
		$loop = $this->createMock(ToolLoop::class);
		$loop->method('listAgentFunctions')->willReturn([]);
		return $loop;
	}

	private function agent(): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject(['prompt' => 'P', 'answerCache' => ['enabled' => true]]);
		return $agent;
	}

	public function testAHitIsServedWithoutAModelCallAndSaysSo(): void {
		$calls = 0;
		$cache = $this->createMock(AnswerCacheService::class);
		$cache->expects($this->once())->method('lookup')
			->with($this->callback(static fn (ResponseTurnRun $run): bool => count($run->messageHistory) === 2 && $run->progress->driver->provider === 'fireworks'))
			->willReturn(['answer' => 'From the cache.', 'entryId' => 'entry-1', 'layer' => 'exact']);
		$cache->expects($this->never())->method('store');
		$handler = new ResponseGenerationHandler($this->factory($calls), $this->loop(), new NullLogger(), null, null, $cache);

		$this->assertSame('From the cache.', $handler->generateResponse(userMessage: 'Q', context: ['text' => '', 'sources' => []], messageHistory: [], agent: $this->agent()));
		$this->assertSame(0, $calls);
		$this->assertSame(['layer' => 'exact', 'entryId' => 'entry-1'], $handler->lastCache);
		$this->assertSame(0, $handler->lastUsage['promptTokens']);
		$this->assertSame(0, $handler->lastUsage['completionTokens']);
	}

	public function testASimilarHitRecordsItsScore(): void {
		$calls = 0;
		$cache = $this->createMock(AnswerCacheService::class);
		$cache->method('lookup')->willReturn(['answer' => 'From a similar question.', 'entryId' => 'entry-2', 'layer' => 'similar', 'similarity' => 0.97]);
		$handler = new ResponseGenerationHandler($this->factory($calls), $this->loop(), new NullLogger(), null, null, $cache);

		$this->assertSame('From a similar question.', $handler->generateResponse(userMessage: 'Q', context: ['text' => '', 'sources' => []], messageHistory: [], agent: $this->agent()));
		$this->assertSame(0, $calls);
		$this->assertSame(['layer' => 'similar', 'entryId' => 'entry-2', 'similarity' => 0.97], $handler->lastCache);
	}

	public function testAMissCallsTheModelAndStoresItsAnswer(): void {
		$calls = 0;
		$cache = $this->createMock(AnswerCacheService::class);
		$cache->method('lookup')->willReturn(null);
		$cache->expects($this->once())->method('store')->with($this->isInstanceOf(ResponseTurnRun::class), 'From the model.');
		$handler = new ResponseGenerationHandler($this->factory($calls), $this->loop(), new NullLogger(), null, null, $cache);

		$this->assertSame('From the model.', $handler->generateResponse(userMessage: 'Q', context: ['text' => '', 'sources' => []], messageHistory: [], agent: $this->agent()));
		$this->assertSame(1, $calls);
		$this->assertSame([], $handler->lastCache);
		$this->assertSame(40, $handler->lastUsage['promptTokens']);
	}

	public function testWithoutACacheEveryTurnCallsTheModel(): void {
		$calls = 0;
		$handler = new ResponseGenerationHandler($this->factory($calls), $this->loop(), new NullLogger());

		$handler->generateResponse(userMessage: 'Q', context: ['text' => '', 'sources' => []], messageHistory: [], agent: $this->agent());
		$this->assertSame(1, $calls);
		$this->assertSame([], $handler->lastCache);
	}

	public function testAskingTheModelAgainReachesTheRunAsABypass(): void {
		$calls = 0;
		$seen  = [];
		$cache = $this->createMock(AnswerCacheService::class);
		$cache->method('lookup')->willReturnCallback(
			static function (ResponseTurnRun $run) use (&$seen): ?array {
				$seen[] = $run->answerCacheBypass;
				return null;
			}
		);
		$handler = new ResponseGenerationHandler($this->factory($calls), $this->loop(), new NullLogger(), null, null, $cache);

		$handler->generateResponse(userMessage: 'Q', context: ['text' => '', 'sources' => []], messageHistory: [], agent: $this->agent(), answerCacheBypass: true);
		$handler->generateResponse(userMessage: 'Q', context: ['text' => '', 'sources' => []], messageHistory: [], agent: $this->agent());

		$this->assertSame([true, false], $seen);
		$this->assertSame(2, $calls);
	}
}
