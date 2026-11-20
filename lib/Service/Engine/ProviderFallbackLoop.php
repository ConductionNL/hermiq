<?php

/**
 * Moves a turn to the next hop of the model policy's fallback chain.
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Engine
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Closure;
use Exception;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderCallFailedException;
use OCA\Hermiq\Service\Llm\ProviderCooldown;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;

/**
 * The fallback loop of one turn (design D2-D4).
 *
 * Only a temporary failure (rate limit, server error, timeout, connection,
 * unavailable) moves the turn, and only while no answer text was shown and no
 * tool ran. Each hop is resolved through the same driver path as the first
 * provider, so the model policy, residency and redaction gates run again; a hop
 * they refuse is recorded as skipped and never called.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
 */
final class ProviderFallbackLoop {

	/**
	 * The answer when every hop failed, cooled or was skipped.
	 */
	public const EXHAUSTED = 'No provider in the fallback chain is available right now.';

	/**
	 * Why a turn did not move after a tool call.
	 */
	public const TOOL_RAN = 'Not retried: a tool already ran in this turn';

	/**
	 * Why a turn did not move after answer text was shown.
	 */
	public const ANSWER_SHOWN = 'Not retried: part of the answer was already shown';

	/**
	 * The refusals that skip a hop, by the step they name.
	 */
	private const REFUSALS = [
		ModelPolicyViolationException::class => ModelPolicyViolationException::STEP,
		ResidencyViolationException::class => ResidencyViolationException::STEP,
		RedactionRequiredException::class => RedactionRequiredException::STEP,
	];

	/**
	 * Constructor.
	 *
	 * @param array<int, array{provider: string, model: string}> $hops     The fallback chain, in order.
	 * @param ProviderCooldown|null                              $cooldown Skips a provider cooling down for the organisation; null keeps none.
	 * @param Closure|null                                       $clock    Returns the time in seconds; null uses microtime.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	public function __construct(
		private readonly array $hops,
		private readonly ?ProviderCooldown $cooldown = null,
		private readonly ?Closure $clock = null,
	) {
	}//end __construct()

	/**
	 * Answer the turn from the next hop that can, after the first provider failed.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param Exception       $failure   What the first provider threw.
	 * @param float           $startedAt When the first call started, in seconds.
	 * @param Closure         $driverFor fn (string $provider, string $model): ChatDriver, through the gates.
	 * @param Closure         $answer    fn (ChatDriver $driver): string, the provider call for that driver.
	 *
	 * @return string The answer of the hop that answered.
	 *
	 * @throws Exception The first failure when it may not move the turn, or when there are no hops.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	public function recover(ResponseTurnRun $run, Exception $failure, float $startedAt, Closure $driverFor, Closure $answer): string {
		$first = $run->progress->driver;
		$this->recordFailure(run: $run, driver: $first, failure: $failure, startedAt: $startedAt);
		$this->assertMayMove(run: $run, failure: $failure);

		$why = self::failedAs(kind: ProviderCallFailedException::fromThrowable(failure: $failure)->kind);
		return $this->walkHops(run: $run, first: $first, why: $why, cause: $failure, driverFor: $driverFor, answer: $answer);
	}//end recover()

	/**
	 * Whether the first provider is cooling down and a hop can answer instead.
	 *
	 * @param ResponseTurnRun $run The turn, with its first driver resolved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	public function firstIsCooling(ResponseTurnRun $run): bool {
		if ($this->hops === [] || $this->cooldown === null) {
			return false;
		}

		return $this->cooldown->isCooling(organisation: (string)$run->progress->organisation, provider: $run->progress->driver->provider);
	}//end firstIsCooling()

	/**
	 * Answer from the chain without calling a first provider that is cooling down.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param Closure         $driverFor Resolves a hop's driver through the gates.
	 * @param Closure         $answer    Calls the provider.
	 *
	 * @return string The answer of the hop that answered.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	public function skipCoolingFirst(ResponseTurnRun $run, Closure $driverFor, Closure $answer): string {
		$first = $run->progress->driver;
		$run->trace?->recordProviderAttempt(
			provider: $first->provider,
			model: $first->model,
			outcome: 'cooling',
			reason: 'cooling',
			durationMs: 0,
			received: false
		);

		return $this->walkHops(run: $run, first: $first, why: 'was cooling down', cause: null, driverFor: $driverFor, answer: $answer);
	}//end skipCoolingFirst()

	/**
	 * Try each hop in order until one answers.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param ChatDriver      $first     The first provider, not tried again.
	 * @param string          $why       What happened to it, for the step line.
	 * @param Exception|null  $cause     The failure to report when nothing answers.
	 * @param Closure         $driverFor Resolves a hop's driver.
	 * @param Closure         $answer    Calls the provider.
	 *
	 * @return string
	 *
	 * @throws ProviderUnavailableException When no hop answered.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function walkHops(ResponseTurnRun $run, ChatDriver $first, string $why, ?Exception $cause, Closure $driverFor, Closure $answer): string {
		$tried = [self::key(provider: $first->provider, model: $first->model) => true];
		foreach ($this->hops as $hop) {
			$key = self::key(provider: $hop['provider'], model: $hop['model']);
			if (isset($tried[$key]) === true) {
				continue;
			}

			$tried[$key] = true;
			$result = $this->tryHop(run: $run, hop: $hop, driverFor: $driverFor, answer: $answer);
			if (is_string($result) === true) {
				$this->noteAnswered(run: $run, first: $first, why: $why);
				return $result;
			}

			$cause = ($result ?? $cause);
		}

		throw new ProviderUnavailableException(message: self::EXHAUSTED, code: 503, previous: $cause);
	}//end walkHops()

	/**
	 * Whether the first failure may move the turn; it is rethrown when not.
	 *
	 * @param ResponseTurnRun $run     The turn.
	 * @param Exception       $failure The first failure.
	 *
	 * @return void
	 *
	 * @throws Exception The failure, unchanged, when the turn stays where it is.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
	 */
	private function assertMayMove(ResponseTurnRun $run, Exception $failure): void {
		if ($this->hops === [] || self::isTemporary(failure: $failure) === false) {
			throw $failure;
		}

		$stopped = self::whyNotMoved(run: $run);
		if ($stopped !== null) {
			$run->trace?->recordFallbackNote(note: $stopped, outcome: 'stopped');
			throw $failure;
		}
	}//end assertMayMove()

	/**
	 * Resolve and call one hop.
	 *
	 * @param ResponseTurnRun                       $run       The turn.
	 * @param array{provider: string, model: string} $hop       The hop.
	 * @param Closure                               $driverFor Resolves the hop's driver through the gates.
	 * @param Closure                               $answer    Calls the provider.
	 *
	 * @return string|Exception|null The answer, the failure to report last, or null for a skipped hop.
	 *
	 * @throws Exception A failure that may not move the turn further.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function tryHop(ResponseTurnRun $run, array $hop, Closure $driverFor, Closure $answer): string|Exception|null {
		if ($this->cooldown?->isCooling(organisation: (string)$run->progress->organisation, provider: $hop['provider']) === true) {
			$run->trace?->recordProviderAttempt(
				provider: $hop['provider'],
				model: $hop['model'],
				outcome: 'cooling',
				reason: 'cooling',
				durationMs: 0,
				received: false
			);
			return null;
		}

		$driver = $this->resolveHop(run: $run, hop: $hop, driverFor: $driverFor);
		if ($driver === null) {
			return null;
		}

		$startedAt = $this->now();
		try {
			$text = $answer($driver);
		} catch (Exception $failure) {
			$this->recordFailure(run: $run, driver: $driver, failure: $failure, startedAt: $startedAt);
			$this->assertMayMove(run: $run, failure: $failure);
			return $failure;
		}

		$run->trace?->recordProviderAttempt(
			provider: $driver->provider,
			model: $driver->model,
			outcome: 'answered',
			reason: '',
			durationMs: $this->elapsedMs(startedAt: $startedAt),
			received: true
		);

		return $text;
	}//end tryHop()

	/**
	 * The hop's driver, or null when a gate refuses it (recorded as skipped).
	 *
	 * @param ResponseTurnRun                        $run       The turn.
	 * @param array{provider: string, model: string} $hop       The hop.
	 * @param Closure                                $driverFor Resolves the driver.
	 *
	 * @return ChatDriver|null
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function resolveHop(ResponseTurnRun $run, array $hop, Closure $driverFor): ?ChatDriver {
		try {
			return $driverFor($hop['provider'], $hop['model']);
		} catch (ModelPolicyViolationException | ResidencyViolationException | RedactionRequiredException | ProviderUnavailableException $refusal) {
			$run->trace?->recordProviderAttempt(
				provider: $hop['provider'],
				model: $hop['model'],
				outcome: 'skipped',
				reason: (self::REFUSALS[$refusal::class] ?? ProviderCallFailedException::UNAVAILABLE),
				durationMs: 0,
				received: false
			);
			return null;
		}
	}//end resolveHop()

	/**
	 * Record a failed attempt, and start the provider's cooldown for a rate limit or server error.
	 *
	 * @param ResponseTurnRun $run       The turn.
	 * @param ChatDriver      $driver    The driver that failed.
	 * @param Exception       $failure   Its failure.
	 * @param float           $startedAt When the call started.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	private function recordFailure(ResponseTurnRun $run, ChatDriver $driver, Exception $failure, float $startedAt): void {
		$typed = ProviderCallFailedException::fromThrowable(failure: $failure);
		$run->trace?->recordProviderAttempt(
			provider: $driver->provider,
			model: $driver->model,
			outcome: 'failed',
			reason: $typed->kind,
			durationMs: $this->elapsedMs(startedAt: $startedAt),
			received: true
		);
		$this->cooldown?->startFor(organisation: (string)$run->progress->organisation, provider: $driver->provider, failure: $typed);
	}//end recordFailure()

	/**
	 * Put "Anthropic was rate limited. Answered by ollama qwen2.5." on the run's steps.
	 *
	 * @param ResponseTurnRun $run   The turn.
	 * @param ChatDriver      $first The provider that did not answer first.
	 * @param string          $why   What happened to it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private function noteAnswered(ResponseTurnRun $run, ChatDriver $first, string $why): void {
		$driver = $run->progress->driver;
		$run->trace?->recordFallbackNote(
			note: sprintf('%s %s. Answered by %s %s.', self::label(provider: $first->provider), $why, $driver->provider, $driver->model),
			outcome: 'answered'
		);
	}//end noteAnswered()

	/**
	 * Why the turn may not move: a tool ran or answer text was shown.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string|null The reason, or null when it may move.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#scenario-a-failure-after-a-tool-call-is-not-retried-elsewhere
	 */
	private static function whyNotMoved(ResponseTurnRun $run): ?string {
		if ($run->channel?->toolCalled() === true || $run->trace?->hasToolStep() === true) {
			return self::TOOL_RAN;
		}

		if ($run->channel?->answerShown() === true) {
			return self::ANSWER_SHOWN;
		}

		return null;
	}//end whyNotMoved()

	/**
	 * Whether a failure is temporary: only those move a turn.
	 *
	 * @param Exception $failure The failure.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-refusal-or-a-configuration-error-never-triggers-a-fallback-req-mfail-003
	 */
	private static function isTemporary(Exception $failure): bool {
		if (isset(self::REFUSALS[$failure::class]) === true) {
			return false;
		}

		return in_array(ProviderCallFailedException::fromThrowable(failure: $failure)->kind, ProviderCallFailedException::TEMPORARY, true);
	}//end isTemporary()

	/**
	 * A provider's name as people write it.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private static function label(string $provider): string {
		$names = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'ollama' => 'Ollama', 'fireworks' => 'Fireworks'];
		return ($names[$provider] ?? ucfirst($provider));
	}//end label()

	/**
	 * What happened to the provider, in words.
	 *
	 * @param string $kind The failure kind.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private static function failedAs(string $kind): string {
		$phrases = [
			ProviderCallFailedException::RATE_LIMIT => 'was rate limited',
			ProviderCallFailedException::SERVER => 'had a server error',
			ProviderCallFailedException::TIMEOUT => 'timed out',
			ProviderCallFailedException::CONNECTION => 'could not be reached',
		];
		return ($phrases[$kind] ?? 'was unavailable');
	}//end failedAs()

	/**
	 * The identity of a hop.
	 *
	 * @param string $provider The provider.
	 * @param string $model    The model.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	private static function key(string $provider, string $model): string {
		return $provider.'/'.$model;
	}//end key()

	/**
	 * The time in seconds.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	private function now(): float {
		if ($this->clock !== null) {
			return (float)($this->clock)();
		}

		return microtime(true);
	}//end now()

	/**
	 * Milliseconds since a moment.
	 *
	 * @param float $startedAt The moment, in seconds.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	private function elapsedMs(float $startedAt): int {
		return max(0, (int)round(($this->now() - $startedAt) * 1000));
	}//end elapsedMs()
}//end class
