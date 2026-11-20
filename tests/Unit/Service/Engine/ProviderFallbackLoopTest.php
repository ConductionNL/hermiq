<?php

/**
 * Unit tests for ProviderFallbackLoop (models-several-models-per-turn).
 *
 * Each branch of the fallback loop with stubbed drivers: a temporary failure
 * moves the turn, a refused hop is skipped without a call, a tool or shown text
 * keeps the turn where it is, a refusal or configuration error never moves it,
 * a cooling provider is skipped, and every attempt lands on the run record.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-several-models-per-turn/tasks.md#task-3-the-fallback-loop-with-the-same-gates-per-hop
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use Closure;
use Exception;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\Engine\ProviderFallbackLoop;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderCooldown;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use PHPUnit\Framework\TestCase;

class ProviderFallbackLoopTest extends TestCase {

	/**
	 * The providers that were called, in order.
	 *
	 * @var array<int, string>
	 */
	private array $called = [];

	/**
	 * A turn whose first provider is anthropic, in organisation org-1.
	 *
	 * @param StreamYieldChannel|null $channel The turn's channel.
	 *
	 * @return ResponseTurnRun
	 */
	private function turn(?StreamYieldChannel $channel = null): ResponseTurnRun {
		$run = new ResponseTurnRun(userMessage: 'Hello', context: ['text' => '', 'sources' => []]);
		$run->progress->organisation = 'org-1';
		$run->progress->driver = new ChatDriver(provider: 'anthropic', chat: null, model: 'claude-opus-4-8');
		$run->trace = new RunTraceCollector();
		$run->channel = $channel;
		return $run;
	}//end turn()

	/**
	 * Resolves a hop to a driver, or throws what `$refusals` names for that provider.
	 *
	 * @param array<string, Exception> $refusals Refusal per provider.
	 *
	 * @return Closure
	 */
	private static function driverFor(array $refusals = []): Closure {
		return static function (string $provider, string $model) use ($refusals): ChatDriver {
			if (isset($refusals[$provider]) === true) {
				throw $refusals[$provider];
			}

			return new ChatDriver(provider: $provider, chat: null, model: $model);
		};
	}//end driverFor()

	/**
	 * Calls a driver: answers "from <provider>", or throws what `$failures` names.
	 *
	 * @param ResponseTurnRun          $run      The turn (its driver is set, as the handler does).
	 * @param array<string, Exception> $failures Failure per provider.
	 *
	 * @return Closure
	 */
	private function answer(ResponseTurnRun $run, array $failures = []): Closure {
		return function (ChatDriver $driver) use ($run, $failures): string {
			$this->called[] = $driver->provider;
			$run->progress->driver = $driver;
			if (isset($failures[$driver->provider]) === true) {
				throw $failures[$driver->provider];
			}

			return 'from '.$driver->provider;
		};
	}//end answer()

	/**
	 * A 429 from the first provider moves the turn to the next hop in the same turn.
	 *
	 * @return void
	 */
	public function testARateLimitMovesTheTurnToTheNextHop(): void {
		$run  = $this->turn();
		$loop = new ProviderFallbackLoop(hops: [['provider' => 'ollama', 'model' => 'qwen2.5']]);

		$text = $loop->recover(
			run: $run,
			failure: ProviderCallFailedException::fromHttpStatus(status: 429, message: 'Too many requests'),
			startedAt: microtime(true),
			driverFor: self::driverFor(),
			answer: $this->answer(run: $run)
		);

		$this->assertSame('from ollama', $text);
		$this->assertSame(['ollama'], $this->called);
		$this->assertSame(
			[['anthropic', 'failed', 'rate_limit', true], ['ollama', 'answered', '', true]],
			array_map(static fn (array $a): array => [$a['provider'], $a['outcome'], $a['reason'], $a['received']], $run->trace->providerAttempts())
		);
		$this->assertSame('Anthropic was rate limited. Answered by ollama qwen2.5.', $run->trace->toArray()[0]['name']);
		$this->assertSame(
			[['provider' => 'anthropic', 'model' => 'claude-opus-4-8'], ['provider' => 'ollama', 'model' => 'qwen2.5']],
			$run->trace->receivedBy()
		);
	}//end testARateLimitMovesTheTurnToTheNextHop()

	/**
	 * A hop the residency gate refuses is skipped without a call, and the next one answers.
	 *
	 * @return void
	 */
	public function testAHopThatBreaksResidencyIsSkippedWithoutACall(): void {
		$run  = $this->turn();
		$loop = new ProviderFallbackLoop(
			hops: [['provider' => 'openai', 'model' => 'gpt-4o-mini'], ['provider' => 'fireworks', 'model' => 'llama']]
		);

		$text = $loop->recover(
			run: $run,
			failure: ProviderCallFailedException::fromHttpStatus(status: 503, message: 'Unavailable'),
			startedAt: microtime(true),
			driverFor: self::driverFor(refusals: ['openai' => new ResidencyViolationException('summary', 'eu', 'outside-eu', 'openai')]),
			answer: $this->answer(run: $run)
		);

		$this->assertSame('from fireworks', $text);
		$this->assertSame(['fireworks'], $this->called);
		$attempts = $run->trace->providerAttempts();
		$this->assertSame(['openai', 'skipped', 'residency', false], [$attempts[1]['provider'], $attempts[1]['outcome'], $attempts[1]['reason'], $attempts[1]['received']]);
		$this->assertNotContains(['provider' => 'openai', 'model' => 'gpt-4o-mini'], $run->trace->receivedBy());
	}//end testAHopThatBreaksResidencyIsSkippedWithoutACall()

	/**
	 * After a tool ran, the failure ends the turn as it does today and no hop is called.
	 *
	 * @return void
	 */
	public function testAFailureAfterAToolCallIsNotRetriedElsewhere(): void {
		$channel = new StreamYieldChannel();
		$channel->emitToolCall(payload: ['toolId' => 'hermiq.createCalendarEvent', 'arguments' => []]);
		$run     = $this->turn(channel: $channel);
		$failure = ProviderCallFailedException::fromHttpStatus(status: 500, message: 'Server error');
		$loop    = new ProviderFallbackLoop(hops: [['provider' => 'ollama', 'model' => 'qwen2.5']]);

		try {
			$loop->recover(run: $run, failure: $failure, startedAt: microtime(true), driverFor: self::driverFor(), answer: $this->answer(run: $run));
			$this->fail('The failure must end the turn.');
		} catch (ProviderCallFailedException $thrown) {
			$this->assertSame($failure, $thrown);
		}

		$this->assertSame([], $this->called);
		$this->assertSame(ProviderFallbackLoop::TOOL_RAN, $run->trace->toArray()[0]['name']);
	}//end testAFailureAfterAToolCallIsNotRetriedElsewhere()

	/**
	 * Answer text already shown keeps the turn on its provider too.
	 *
	 * @return void
	 */
	public function testShownAnswerTextKeepsTheTurnWhereItIs(): void {
		$channel = new StreamYieldChannel();
		$channel->emitToken(delta: 'Half an ans');
		$run  = $this->turn(channel: $channel);
		$loop = new ProviderFallbackLoop(hops: [['provider' => 'ollama', 'model' => 'qwen2.5']]);

		$this->expectException(ProviderCallFailedException::class);
		try {
			$loop->recover(
				run: $run,
				failure: ProviderCallFailedException::fromHttpStatus(status: 502, message: 'Bad gateway'),
				startedAt: microtime(true),
				driverFor: self::driverFor(),
				answer: $this->answer(run: $run)
			);
		} finally {
			$this->assertSame([], $this->called);
			$this->assertSame(ProviderFallbackLoop::ANSWER_SHOWN, $run->trace->toArray()[0]['name']);
		}
	}//end testShownAnswerTextKeepsTheTurnWhereItIs()

	/**
	 * A 401 (revoked key) and a model-policy refusal end the turn; no other provider is called.
	 *
	 * @return void
	 */
	public function testAnAuthFailureOrARefusalNeverMovesTheTurn(): void {
		$loop = new ProviderFallbackLoop(hops: [['provider' => 'ollama', 'model' => 'qwen2.5']]);
		foreach ([ProviderCallFailedException::fromHttpStatus(status: 401, message: 'Invalid key'), new ModelPolicyViolationException('Refused', 422)] as $failure) {
			$run = $this->turn();
			try {
				$loop->recover(run: $run, failure: $failure, startedAt: microtime(true), driverFor: self::driverFor(), answer: $this->answer(run: $run));
				$this->fail('The failure must end the turn.');
			} catch (Exception $thrown) {
				$this->assertSame($failure, $thrown);
			}
		}

		$this->assertSame([], $this->called);
	}//end testAnAuthFailureOrARefusalNeverMovesTheTurn()

	/**
	 * Without a chain the first failure is thrown unchanged, as before this change.
	 *
	 * @return void
	 */
	public function testWithoutAChainTheFailureIsUnchanged(): void {
		$run     = $this->turn();
		$failure = ProviderCallFailedException::fromHttpStatus(status: 429, message: 'Too many requests');

		try {
			(new ProviderFallbackLoop(hops: []))->recover(run: $run, failure: $failure, startedAt: microtime(true), driverFor: self::driverFor(), answer: $this->answer(run: $run));
			$this->fail('The failure must end the turn.');
		} catch (Exception $thrown) {
			$this->assertSame($failure, $thrown);
		}
	}//end testWithoutAChainTheFailureIsUnchanged()

	/**
	 * When every hop fails or is refused, the turn fails with the exhausted message; the first provider is not retried.
	 *
	 * @return void
	 */
	public function testAnExhaustedChainSaysSo(): void {
		$run  = $this->turn();
		$last = ProviderCallFailedException::fromHttpStatus(status: 503, message: 'Down too');
		$loop = new ProviderFallbackLoop(
			hops: [
				['provider' => 'anthropic', 'model' => 'claude-opus-4-8'],
				['provider' => 'openai', 'model' => 'gpt-4o-mini'],
				['provider' => 'ollama', 'model' => 'qwen2.5'],
			]
		);

		try {
			$loop->recover(
				run: $run,
				failure: ProviderCallFailedException::fromHttpStatus(status: 429, message: 'Too many'),
				startedAt: microtime(true),
				driverFor: self::driverFor(refusals: ['openai' => new ProviderUnavailableException('No key', 503)]),
				answer: $this->answer(run: $run, failures: ['ollama' => $last])
			);
			$this->fail('The turn must fail.');
		} catch (ProviderUnavailableException $thrown) {
			$this->assertSame(ProviderFallbackLoop::EXHAUSTED, $thrown->getMessage());
			$this->assertSame($last, $thrown->getPrevious());
		}

		$this->assertSame(['ollama'], $this->called);
		$this->assertSame(
			['failed', 'skipped', 'failed'],
			array_column($run->trace->providerAttempts(), 'outcome')
		);
	}//end testAnExhaustedChainSaysSo()

	/**
	 * A cooling first provider is recorded as cooling and the next hop answers without calling it.
	 *
	 * @return void
	 */
	public function testACoolingFirstProviderIsSkipped(): void {
		$cooldown = $this->createMock(ProviderCooldown::class);
		$cooldown->method('isCooling')->willReturnCallback(static fn (string $organisation, string $provider): bool => $organisation === 'org-1' && $provider === 'anthropic');
		$run  = $this->turn();
		$loop = new ProviderFallbackLoop(hops: [['provider' => 'ollama', 'model' => 'qwen2.5']], cooldown: $cooldown);

		$this->assertTrue($loop->firstIsCooling(run: $run));
		$text = $loop->skipCoolingFirst(run: $run, driverFor: self::driverFor(), answer: $this->answer(run: $run));

		$this->assertSame('from ollama', $text);
		$this->assertSame(['ollama'], $this->called);
		$this->assertSame(['cooling', 'answered'], array_column($run->trace->providerAttempts(), 'outcome'));
		$this->assertSame('Anthropic was cooling down. Answered by ollama qwen2.5.', $run->trace->toArray()[0]['name']);
	}//end testACoolingFirstProviderIsSkipped()

	/**
	 * A rate-limited provider starts its cooldown for the organisation.
	 *
	 * @return void
	 */
	public function testAFailureStartsTheCooldown(): void {
		$cooldown = $this->createMock(ProviderCooldown::class);
		$cooldown->expects($this->once())->method('startFor')->with(
			'org-1',
			'anthropic',
			$this->callback(static fn (ProviderCallFailedException $failure): bool => $failure->kind === 'rate_limit' && $failure->retryAfterSeconds === 30)
		)->willReturn(30);
		$run = $this->turn();

		(new ProviderFallbackLoop(hops: [['provider' => 'ollama', 'model' => 'qwen2.5']], cooldown: $cooldown))->recover(
			run: $run,
			failure: ProviderCallFailedException::fromHttpStatus(status: 429, message: 'Too many', retryAfter: '30'),
			startedAt: microtime(true),
			driverFor: self::driverFor(),
			answer: $this->answer(run: $run)
		);

		$this->assertSame(['ollama'], $this->called);
	}//end testAFailureStartsTheCooldown()
}//end class
