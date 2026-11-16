<?php

/**
 * Hermiq ReferenceAnswerRound.
 *
 * The first half of an ensemble turn (models-several-models-per-turn D7, D8):
 * each of an agent's reference models is asked the assembled prompt, one after
 * the other and without any tool, so the agent's own model can merge their
 * answers afterwards. Each reference goes through the same driver path as the
 * agent's own model, so the model policy, residency and redaction gates refuse
 * it before it is called. The budget's hard cap is checked before each call;
 * once it blocks, no further reference model is called. A reference that is
 * refused or fails is recorded and left out; the turn does not fail for it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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

namespace OCA\Hermiq\Service\Engine;

use Closure;
use Exception;
use OCA\Hermiq\Service\AiFeature\DataUseViolationException;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;

/**
 * Ask an ensemble's reference models, within the gates and the budget.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
 */
final class ReferenceAnswerRound {

	/**
	 * The most reference models a turn asks, as the agent setting allows.
	 */
	public const MAX_REFERENCES = 3;

	/**
	 * The reason a reference that answered is recorded with.
	 */
	public const REFERENCE = 'reference';

	/**
	 * The refusals that skip a reference, by the step they name.
	 */
	private const REFUSALS = [
		ModelPolicyViolationException::class => ModelPolicyViolationException::STEP,
		ResidencyViolationException::class => ResidencyViolationException::STEP,
		RedactionRequiredException::class => RedactionRequiredException::STEP,
		DataUseViolationException::class => DataUseViolationException::STEP,
	];

	/**
	 * Constructor.
	 *
	 * @param Closure            $driverFor fn (string $provider, string $model): ChatDriver, through the gates.
	 * @param Closure            $answer    fn (ChatDriver $driver): array{text: string, usage: array<string, mixed>}, a call without tools.
	 * @param BudgetService|null $budget    The budget's hard cap, checked before each call; null checks none.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	public function __construct(
		private readonly Closure $driverFor,
		private readonly Closure $answer,
		private readonly ?BudgetService $budget = null,
	) {
	}//end __construct()

	/**
	 * The reference models an agent names for an ensemble turn, or none.
	 *
	 * @param array<string, mixed> $agentData The agent's data.
	 *
	 * @return array<int, array{provider: string, model: string}> The references, at most three.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-agent-owner-can-switch-on-ensemble-answers-req-mens-001
	 */
	public static function referencesOf(array $agentData): array {
		$ensemble = ($agentData['ensemble'] ?? null);
		if (is_array($ensemble) === false || ($ensemble['enabled'] ?? false) !== true || is_array($ensemble['referenceModels'] ?? null) === false) {
			return [];
		}

		$references = [];
		foreach ($ensemble['referenceModels'] as $entry) {
			$provider = self::textOf(entry: $entry, key: 'provider');
			$model = self::textOf(entry: $entry, key: 'model');
			if ($provider !== '' && $model !== '') {
				$references[] = ['provider' => $provider, 'model' => $model];
			}
		}

		return array_slice($references, 0, self::MAX_REFERENCES);
	}//end referencesOf()

	/**
	 * One text field of a reference entry, trimmed; '' when absent or not text.
	 *
	 * @param mixed  $entry The entry.
	 * @param string $key   The field.
	 *
	 * @return string
	 */
	private static function textOf(mixed $entry, string $key): string {
		if (is_array($entry) === false || is_string($entry[$key] ?? null) === false) {
			return '';
		}

		return trim($entry[$key]);
	}//end textOf()

	/**
	 * Ask each reference model in order.
	 *
	 * @param ResponseTurnRun                                    $run        The turn, for its organisation, agent and trace.
	 * @param array<int, array{provider: string, model: string}> $references The reference models.
	 *
	 * @return array{
	 *     answers: array<int, array{provider: string, model: string, text: string}>,
	 *     usages: array<int, array{provider: string, model: string, usage: array<string, mixed>}>
	 * } What arrived, and what each call cost.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
	 */
	public function collect(ResponseTurnRun $run, array $references): array {
		$answers = [];
		$usages = [];
		$asked = 0;
		$budgetReached = false;
		foreach ($references as $reference) {
			if ($this->budgetBlocks(run: $run) === true) {
				$budgetReached = true;
				break;
			}

			$driver = $this->resolve(run: $run, reference: $reference);
			if ($driver === null) {
				continue;
			}

			$asked++;
			$reply = $this->ask(run: $run, driver: $driver);
			if ($reply === null) {
				continue;
			}

			$usages[] = ['provider' => $driver->provider, 'model' => $driver->model, 'usage' => $reply['usage']];
			if (trim($reply['text']) !== '') {
				$answers[] = ['provider' => $driver->provider, 'model' => $driver->model, 'text' => $reply['text']];
			}
		}//end foreach

		$this->noteRound(run: $run, asked: $asked, answered: count($answers), budgetReached: $budgetReached);

		return ['answers' => $answers, 'usages' => $usages];
	}//end collect()

	/**
	 * Whether the budget's hard cap blocks another call for this organisation and agent.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return bool
	 */
	private function budgetBlocks(ResponseTurnRun $run): bool {
		if ($this->budget === null) {
			return false;
		}

		$agentId = null;
		if ($run->agent !== null) {
			$agentId = (string)$run->agent->getUuid();
		}

		return $this->budget->isBlocked(organisation: (string)$run->progress->organisation, agentId: $agentId);
	}//end budgetBlocks()

	/**
	 * The reference's driver, or null when a gate refuses it (recorded as skipped, never called).
	 *
	 * @param ResponseTurnRun                        $run       The turn.
	 * @param array{provider: string, model: string} $reference The reference model.
	 *
	 * @return ChatDriver|null
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-each-reference-model-passes-the-same-checks-as-the-agents-model-req-mens-003
	 */
	private function resolve(ResponseTurnRun $run, array $reference): ?ChatDriver {
		try {
			return ($this->driverFor)($reference['provider'], $reference['model']);
		} catch (ModelPolicyViolationException | ResidencyViolationException | RedactionRequiredException
			| DataUseViolationException | ProviderUnavailableException $refusal
		) {
			$run->trace?->recordProviderAttempt(
				provider: $reference['provider'],
				model: $reference['model'],
				outcome: 'skipped',
				reason: (self::REFUSALS[$refusal::class] ?? ProviderCallFailedException::UNAVAILABLE),
				durationMs: 0,
				received: false
			);
			return null;
		}
	}//end resolve()

	/**
	 * Call one reference model; a failure is recorded with its kind and left out.
	 *
	 * @param ResponseTurnRun $run    The turn.
	 * @param ChatDriver      $driver The reference's driver.
	 *
	 * @return array{text: string, usage: array<string, mixed>}|null The reply, or null when the call failed.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	private function ask(ResponseTurnRun $run, ChatDriver $driver): ?array {
		$startedAt = microtime(true);
		try {
			$reply = ($this->answer)($driver);
		} catch (Exception $failure) {
			$run->trace?->recordProviderAttempt(
				provider: $driver->provider,
				model: $driver->model,
				outcome: 'failed',
				reason: ProviderCallFailedException::fromThrowable(failure: $failure)->kind,
				durationMs: self::elapsedMs(startedAt: $startedAt),
				received: true
			);
			return null;
		}

		$run->trace?->recordProviderAttempt(
			provider: $driver->provider,
			model: $driver->model,
			outcome: 'answered',
			reason: self::REFERENCE,
			durationMs: self::elapsedMs(startedAt: $startedAt),
			received: true
		);
		$run->trace?->recordReferenceAnswer(provider: $driver->provider, model: $driver->model, text: (string)$reply['text']);

		return ['text' => (string)$reply['text'], 'usage' => (array)$reply['usage']];
	}//end ask()

	/**
	 * Put what the round did on the run's steps, before the answer.
	 *
	 * @param ResponseTurnRun $run           The turn.
	 * @param int             $asked         How many reference models were sent the prompt.
	 * @param int             $answered      How many answered.
	 * @param bool            $budgetReached Whether the budget stopped the round.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
	 */
	private function noteRound(ResponseTurnRun $run, int $asked, int $answered, bool $budgetReached): void {
		if ($asked > 0) {
			$run->trace?->recordEnsembleNote(note: sprintf('Asked %d %s', $asked, self::models(count: $asked)), outcome: 'ok');
		}

		if ($budgetReached === true) {
			$run->trace?->recordEnsembleNote(
				note: sprintf('Budget reached: answered from %d %s', $answered, self::models(count: $answered)),
				outcome: 'stopped'
			);
			return;
		}

		if ($answered === 0) {
			$run->trace?->recordEnsembleNote(note: 'No reference model answered: answered by the agent\'s own model alone', outcome: 'skipped');
		}
	}//end noteRound()

	/**
	 * "model" or "models".
	 *
	 * @param int $count How many.
	 *
	 * @return string
	 */
	private static function models(int $count): string {
		if ($count === 1) {
			return 'model';
		}

		return 'models';
	}//end models()

	/**
	 * Milliseconds since a moment.
	 *
	 * @param float $startedAt The moment, in seconds.
	 *
	 * @return int
	 */
	private static function elapsedMs(float $startedAt): int {
		return max(0, (int)round((microtime(true) - $startedAt) * 1000));
	}//end elapsedMs()
}//end class
