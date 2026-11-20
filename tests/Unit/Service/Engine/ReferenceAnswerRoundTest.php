<?php

/**
 * Tests for ReferenceAnswerRound: the reference models of an ensemble turn.
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

use Closure;
use Exception;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Engine\ReferenceAnswerRound;
use OCA\Hermiq\Service\Engine\ResponseTurnRun;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Each reference answers without tools, a failing or refused one is left out,
 * and the budget stops the round.
 */
#[CoversClass(ReferenceAnswerRound::class)]
#[UsesClass(ResponseTurnRun::class)]
#[UsesClass(RunTraceCollector::class)]
#[UsesClass(ChatDriver::class)]
#[UsesClass(ProviderCallFailedException::class)]
#[UsesClass(ResidencyViolationException::class)]
final class ReferenceAnswerRoundTest extends TestCase {

	/**
	 * The providers that were called, in order.
	 *
	 * @var array<int, string>
	 */
	private array $called = [];

	/**
	 * Two reference models.
	 */
	private const TWO = [
		['provider' => 'anthropic', 'model' => 'claude-opus-4-8'],
		['provider' => 'ollama', 'model' => 'qwen2.5'],
	];

	/**
	 * An ensemble turn of agent-1 in org-1.
	 *
	 * @return ResponseTurnRun
	 */
	private static function turn(): ResponseTurnRun {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$run = new ResponseTurnRun(userMessage: 'Welke regels gelden voor een terrasvergunning?', context: ['text' => '', 'sources' => []]);
		$run->agent = $agent;
		$run->progress->organisation = 'org-1';
		$run->trace = new RunTraceCollector();
		return $run;
	}//end turn()

	/**
	 * A round whose drivers refuse or fail as told.
	 *
	 * @param array<string, Exception> $refusals Thrown when resolving that provider.
	 * @param array<string, Exception> $failures Thrown when calling that provider.
	 * @param BudgetService|null       $budget   The budget.
	 *
	 * @return ReferenceAnswerRound
	 */
	private function round(array $refusals = [], array $failures = [], ?BudgetService $budget = null): ReferenceAnswerRound {
		$driverFor = static function (string $provider, string $model) use ($refusals): ChatDriver {
			if (isset($refusals[$provider]) === true) {
				throw $refusals[$provider];
			}

			return new ChatDriver(provider: $provider, chat: null, model: $model);
		};
		$answer = function (ChatDriver $driver) use ($failures): array {
			$this->called[] = $driver->provider;
			if (isset($failures[$driver->provider]) === true) {
				throw $failures[$driver->provider];
			}

			return ['text' => 'Draft by ' . $driver->provider, 'usage' => ['promptTokens' => 100, 'completionTokens' => 20]];
		};

		return new ReferenceAnswerRound(driverFor: Closure::fromCallable($driverFor), answer: Closure::fromCallable($answer), budget: $budget);
	}//end round()

	/**
	 * The names of the run's steps.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return array<int, string>
	 */
	private static function steps(ResponseTurnRun $run): array {
		return array_map(static fn (array $step): string => $step['name'], $run->trace->toArray());
	}//end steps()

	/**
	 * Only an enabled ensemble names references: complete pairs, at most three.
	 *
	 * @return void
	 */
	public function testOnlyAnEnabledEnsembleNamesReferences(): void {
		$four = [...self::TWO, ['provider' => 'openai', 'model' => 'gpt-4o'], ['provider' => 'fireworks', 'model' => 'llama']];

		self::assertSame([], ReferenceAnswerRound::referencesOf(agentData: []));
		self::assertSame([], ReferenceAnswerRound::referencesOf(agentData: ['ensemble' => ['enabled' => false, 'referenceModels' => self::TWO]]));
		self::assertSame([], ReferenceAnswerRound::referencesOf(agentData: ['ensemble' => ['enabled' => true, 'referenceModels' => 'x']]));
		self::assertSame(
			[['provider' => 'ollama', 'model' => 'qwen2.5']],
			ReferenceAnswerRound::referencesOf(agentData: ['ensemble' => ['enabled' => true, 'referenceModels' => [['provider' => 'ollama', 'model' => ' qwen2.5 '], ['provider' => 'openai'], 'x']]])
		);
		self::assertCount(3, ReferenceAnswerRound::referencesOf(agentData: ['ensemble' => ['enabled' => true, 'referenceModels' => $four]]));
	}//end testOnlyAnEnabledEnsembleNamesReferences()

	/**
	 * Both references answer, in order; the run keeps both answers and says "Asked 2 models".
	 *
	 * @return void
	 */
	public function testTwoReferencesAnswerAndAreKeptOnTheRun(): void {
		$run = self::turn();

		$result = $this->round()->collect(run: $run, references: self::TWO);

		self::assertSame(['anthropic', 'ollama'], $this->called);
		self::assertSame(
			[
				['provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'text' => 'Draft by anthropic'],
				['provider' => 'ollama', 'model' => 'qwen2.5', 'text' => 'Draft by ollama'],
			],
			$result['answers']
		);
		self::assertSame($result['answers'], $run->trace->referenceAnswers());
		self::assertCount(2, $result['usages']);
		self::assertSame(['Asked 2 models'], self::steps(run: $run));
		self::assertSame(['answered', 'answered'], array_column($run->trace->providerAttempts(), 'outcome'));
		self::assertSame(
			[['provider' => 'anthropic', 'model' => 'claude-opus-4-8'], ['provider' => 'ollama', 'model' => 'qwen2.5']],
			$run->trace->receivedBy()
		);
	}//end testTwoReferencesAnswerAndAreKeptOnTheRun()

	/**
	 * A reference that answers 503 is left out with its reason; the turn does not fail.
	 *
	 * @return void
	 */
	public function testAFailingReferenceIsLeftOutWithItsReason(): void {
		$run = self::turn();

		$result = $this->round(failures: ['ollama' => ProviderCallFailedException::fromHttpStatus(status: 503, message: 'Unavailable')])
			->collect(run: $run, references: self::TWO);

		self::assertSame(['anthropic'], array_column($result['answers'], 'provider'));
		self::assertCount(1, $result['usages']);
		$failed = $run->trace->providerAttempts()[1];
		self::assertSame(['ollama', 'failed', ProviderCallFailedException::SERVER, true], [$failed['provider'], $failed['outcome'], $failed['reason'], $failed['received']]);
		self::assertSame(['Asked 2 models'], self::steps(run: $run));
	}//end testAFailingReferenceIsLeftOutWithItsReason()

	/**
	 * A reference outside the feature's residency is skipped with step residency, never called.
	 *
	 * @return void
	 */
	public function testAReferenceOutsideTheResidencyIsSkippedWithoutACall(): void {
		$run = self::turn();

		$result = $this->round(refusals: ['anthropic' => new ResidencyViolationException('advice', 'on-premise', 'outside-eu', 'anthropic')])
			->collect(run: $run, references: self::TWO);

		self::assertSame(['ollama'], $this->called);
		self::assertSame(['ollama'], array_column($result['answers'], 'provider'));
		$skipped = $run->trace->providerAttempts()[0];
		self::assertSame(['anthropic', 'skipped', 'residency', false], [$skipped['provider'], $skipped['outcome'], $skipped['reason'], $skipped['received']]);
		self::assertSame(['Asked 1 model'], self::steps(run: $run));
	}//end testAReferenceOutsideTheResidencyIsSkippedWithoutACall()

	/**
	 * When the budget reaches its cap after the first call, no second reference is called.
	 *
	 * @return void
	 */
	public function testTheBudgetStopsTheRoundAndSaysSo(): void {
		$run = self::turn();
		$budget = $this->createMock(originalClassName: BudgetService::class);
		$asked = [];
		$budget->method('isBlocked')->willReturnCallback(
			function (string $organisation, ?string $agentId = null) use (&$asked): bool {
				$asked[] = $organisation . ':' . $agentId;
				return count($this->called) >= 1;
			}
		);

		$result = $this->round(budget: $budget)->collect(run: $run, references: self::TWO);

		self::assertSame(['anthropic'], $this->called);
		self::assertSame(['org-1:agent-1', 'org-1:agent-1'], $asked);
		self::assertCount(1, $result['usages']);
		self::assertSame(['Asked 1 model', 'Budget reached: answered from 1 model'], self::steps(run: $run));
	}//end testTheBudgetStopsTheRoundAndSaysSo()

	/**
	 * With no answer at all the turn is answered by the agent's own model alone, and says so.
	 *
	 * @return void
	 */
	public function testNoAnswerLeavesTheAgentsOwnModel(): void {
		$run = self::turn();
		$down = ProviderCallFailedException::fromHttpStatus(status: 500, message: 'Server error');

		$result = $this->round(failures: ['anthropic' => $down, 'ollama' => $down])->collect(run: $run, references: self::TWO);

		self::assertSame([], $result['answers']);
		self::assertSame(
			['Asked 2 models', "No reference model answered: answered by the agent's own model alone"],
			self::steps(run: $run)
		);
	}//end testNoAnswerLeavesTheAgentsOwnModel()
}//end class
