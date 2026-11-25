<?php

/**
 * Hermiq Run Trace Collector.
 *
 * A lightweight, in-memory, per-run ordered-step recorder — no persistence of its
 * own. Threaded through `Engine::processMessage()` → `ToolLoop`/
 * `FacadeToolInvoker`, mirroring exactly how `StreamYieldChannel` is already
 * optionally threaded through the same call chain (run-trace-observability).
 *
 * Sequence numbers (`seq`) are assigned at `endStep()` time — i.e. in
 * COMPLETION order, not start order. For non-overlapping (sequential) steps
 * this is identical to start order. For a step that wraps a nested call (the
 * `llm` step wraps the tool-calling loop), this yields the intuitive reading
 * order: a tool call that starts AND finishes inside the enclosing LLM call
 * appears BEFORE that enclosing step in the timeline, because it completes
 * first — exactly the ordering `run-trace-observability`'s design.md API
 * example documents (`context, history, tool, llm, delivery`).
 *
 * Never persists anything itself; `ScheduleService` reads `toArray()` once the
 * run finishes and folds it into the single per-run `AuditTrail` write
 * (`run-audit-log`) — no new logging system, no parallel telemetry pipeline.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-1-runtracecollector-ordered-in-memory-step-recorder
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * RunTraceCollector
 *
 * Request-scoped step timeline recorder used during one agent turn. Pure-PHP
 * value object — no DI, no I/O.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-1-runtracecollector-ordered-in-memory-step-recorder
 */
class RunTraceCollector {

	/**
	 * Steps not yet ended, keyed by their start token.
	 *
	 * @var array<int, array{type: string, name: string, startedAt: DateTimeImmutable, startedAtMicro: float}>
	 */
	private array $pending = [];

	/**
	 * Completed steps, in COMPLETION (endStep call) order — see class docblock.
	 *
	 * The shape is unsealed (`...<string, mixed>`): endStep() merges caller-supplied
	 * `$extra` keys (e.g. tool call metadata) alongside the fixed timeline keys.
	 *
	 * @var array<int, array{seq: int, type: string, name: string, startedAt: string,
	 *     endedAt: string, durationMs: int, outcome: string, ...<string, mixed>}>
	 */
	private array $steps = [];

	/**
	 * What this run used, and where it ran: the feature, the provider, the model,
	 * and the residency and location as they stood when the run happened.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $providerDisclosure = null;

	/**
	 * The question this run asked a person (oversight-ask-and-hold), or null.
	 *
	 * @var array{text: string, options: list<string>, allowOther: bool}|null
	 */
	private ?array $question = null;

	/**
	 * The attempts of the turn at a provider, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	private array $providerAttempts = [];

	/**
	 * The answers of an ensemble turn's reference models, in order.
	 *
	 * @var array<int, array{provider: string, model: string, text: string}>
	 */
	private array $referenceAnswers = [];

	/**
	 * Monotonic token counter for `startStep()`.
	 *
	 * @var integer
	 */
	private int $nextToken = 0;

	/**
	 * Monotonic sequence counter, incremented on each `endStep()` completion.
	 *
	 * @var integer
	 */
	private int $nextSeq = 0;

	/**
	 * Begin timing one step.
	 *
	 * @param string $type A step type (`context`|`history`|`llm`|`tool`|`delivery`).
	 * @param string $name A human-readable step name (a fixed label or a
	 *                     `{appId}.{toolName}` registry id — never free user/tool
	 *                     text, so no redaction is needed on this field).
	 *
	 * @return int An opaque token to pass to `endStep()`.
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function startStep(string $type, string $name): int {
		$token = $this->nextToken++;

		$this->pending[$token] = [
			'type' => $type,
			'name' => $name,
			'startedAt' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
			'startedAtMicro' => microtime(true),
		];

		return $token;
	}//end startStep()

	/**
	 * End a previously started step and record it.
	 *
	 * Defensive by design: an unknown/already-ended token is silently ignored —
	 * a caller bug (double-end, stale token) must never throw and must never
	 * corrupt already-recorded steps.
	 *
	 * @param int $token The token returned by the matching `startStep()`.
	 * @param string $outcome The step outcome (`ok`|`error`, or a fixed label
	 *                        like `approved` for a reconstructed gate-wait step,
	 *                        or `would-have-called` for a dry-run-neutralised
	 *                        tool call — run-replay-and-dry-run).
	 * @param array<string, mixed> $extra Additional fields to merge onto the recorded step
	 *                                    (run-replay-and-dry-run: `['arguments' => ...]` on a
	 *                                    `would-have-called` step, empty for every other
	 *                                    caller — zero behavior change). Applied BEFORE the
	 *                                    fixed fields below, so `$extra` can never clobber
	 *                                    `seq`/`type`/`name`/`startedAt`/`endedAt`/`durationMs`/
	 *                                    `outcome` even if it tried to.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
	 */
	public function endStep(int $token, string $outcome, array $extra = []): void {
		if (isset($this->pending[$token]) === false) {
			return;
		}

		$entry = $this->pending[$token];
		unset($this->pending[$token]);

		$endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$durationMs = (int)round((microtime(true) - $entry['startedAtMicro']) * 1000);

		$this->steps[] = array_merge(
			$extra,
			[
				'seq' => $this->nextSeq++,
				'type' => $entry['type'],
				'name' => $entry['name'],
				'startedAt' => $entry['startedAt']->format('c'),
				'endedAt' => $endedAt->format('c'),
				'durationMs' => $durationMs,
				'outcome' => $outcome,
			]
		);

	}//end endStep()

	/**
	 * Record that the turn was stopped, as one step named by the reason
	 * (agents-switch-off-and-stop).
	 *
	 * @param string $reason The stop reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function recordStop(string $reason): void {
		$this->endStep(token: $this->startStep(type: 'guard', name: $reason), outcome: 'stopped');
	}//end recordStop()

	/**
	 * Record the stop only when it happened.
	 *
	 * @param bool   $stopped Whether the turn stopped.
	 * @param string $reason  The stop reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function recordStopWhen(bool $stopped, string $reason): void {
		if ($stopped === true) {
			$this->recordStop(reason: $reason);
		}
	}//end recordStopWhen()

	/**
	 * Record which AI feature this run belongs to, which provider and model it
	 * actually used, and where that provider ran at the time.
	 *
	 * The residency is copied onto the run, never referenced, so relabelling a
	 * provider next year cannot rewrite what this run says. "Which model saw this
	 * case, and where" then becomes a read rather than an investigation.
	 *
	 * @param array<string, mixed> $disclosure As resolved: feature, provider, model, residency, location, dataUse, termsReference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-feature-governance/spec.md#requirement-every-run-must-record-the-feature-the-provider-and-the-residency-in-force
	 */
	public function recordProviderDisclosure(array $disclosure): void {
		$this->providerDisclosure = [
			'feature' => (string)($disclosure['feature'] ?? ''),
			'provider' => (string)($disclosure['provider'] ?? ''),
			'model' => (string)($disclosure['model'] ?? ''),
			'residency' => (string)($disclosure['residency'] ?? ''),
			'location' => (string)($disclosure['location'] ?? ''),
			'dataUse' => (string)($disclosure['dataUse'] ?? ''),
			'termsReference' => (string)($disclosure['termsReference'] ?? ''),
		];

	}//end recordProviderDisclosure()

	/**
	 * What this run recorded about the provider it used, or null when nothing was
	 * recorded (a run that never reached a provider).
	 *
	 * @return array<string, mixed>|null
	 *         The disclosure (with `receivedBy` once the turn has attempts), or null.
	 *
	 * @spec openspec/specs/ai-feature-governance/spec.md#requirement-every-run-must-record-the-feature-the-provider-and-the-residency-in-force
	 */
	public function providerDisclosure(): ?array {
		// Models-several-models-per-turn D6: once a turn has attempts, the
		// disclosure also names every provider that was sent the prompt.
		if ($this->providerDisclosure === null || $this->providerAttempts === []) {
			return $this->providerDisclosure;
		}

		return array_merge($this->providerDisclosure, ['receivedBy' => $this->receivedBy()]);
	}//end providerDisclosure()

	/**
	 * Record the question this run asked a person, so the turn's answer can carry it.
	 *
	 * @param array{text: string, options: list<string>, allowOther: bool} $question The question.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
	 */
	public function recordQuestion(array $question): void {
		$this->question = $question;
		$this->endStep(token: $this->startStep(type: 'question', name: $question['text']), outcome: 'asked');
	}//end recordQuestion()

	/**
	 * The question this run asked a person, or null when it asked none.
	 *
	 * @return array{text: string, options: list<string>, allowOther: bool}|null
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
	 */
	public function question(): ?array {
		return $this->question;
	}//end question()

	/**
	 * Record one attempt of the turn at a provider.
	 *
	 * @param string $provider   The provider.
	 * @param string $model      The model.
	 * @param string $outcome    answered, failed, skipped or cooling.
	 * @param string $reason     Why, in words or a step name; empty when it answered.
	 * @param int    $durationMs How long the attempt took.
	 * @param bool   $received   Whether the provider was sent the prompt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	public function recordProviderAttempt(string $provider, string $model, string $outcome, string $reason, int $durationMs, bool $received): void {
		$this->providerAttempts[] = [
			'provider' => $provider,
			'model' => $model,
			'outcome' => $outcome,
			'reason' => $reason,
			'durationMs' => $durationMs,
			'received' => $received,
		];
	}//end recordProviderAttempt()

	/**
	 * The attempts of the turn, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	public function providerAttempts(): array {
		return $this->providerAttempts;
	}//end providerAttempts()

	/**
	 * Every provider and model that was sent the prompt, in order and once each.
	 *
	 * @return array<int, array{provider: string, model: string}>
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-every-attempt-is-on-the-run-record-req-mfail-005
	 */
	public function receivedBy(): array {
		$seen = [];
		foreach ($this->providerAttempts as $attempt) {
			if ($attempt['received'] === true) {
				$seen[$attempt['provider'].'/'.$attempt['model']] = ['provider' => $attempt['provider'], 'model' => $attempt['model']];
			}
		}

		return array_values($seen);
	}//end receivedBy()

	/**
	 * Record a line about the fallback on the run's steps.
	 *
	 * @param string $note    What happened.
	 * @param string $outcome The step outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	public function recordFallbackNote(string $note, string $outcome): void {
		$this->endStep(token: $this->startStep(type: 'fallback', name: $note), outcome: $outcome);
	}//end recordFallbackNote()

	/**
	 * Record a line about an ensemble turn on the run's steps ("Asked 2 models").
	 *
	 * @param string $note    What happened.
	 * @param string $outcome The step outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	public function recordEnsembleNote(string $note, string $outcome): void {
		$this->endStep(token: $this->startStep(type: 'ensemble', name: $note), outcome: $outcome);
	}//end recordEnsembleNote()

	/**
	 * Keep one reference model's answer on the run; it is never shown in the chat.
	 *
	 * @param string $provider The reference's provider.
	 * @param string $model    The reference's model.
	 * @param string $text     Its answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	public function recordReferenceAnswer(string $provider, string $model, string $text): void {
		$this->referenceAnswers[] = ['provider' => $provider, 'model' => $model, 'text' => $text];
	}//end recordReferenceAnswer()

	/**
	 * The reference answers of the turn, in order.
	 *
	 * @return array<int, array{provider: string, model: string, text: string}>
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	public function referenceAnswers(): array {
		return $this->referenceAnswers;
	}//end referenceAnswers()

	/**
	 * Whether a tool step was started in this run.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-temporary-provider-failure-moves-the-turn-to-the-next-allowed-hop-req-mfail-002
	 */
	public function hasToolStep(): bool {
		foreach (array_merge($this->steps, array_values($this->pending)) as $step) {
			if (($step['type'] ?? '') === 'tool') {
				return true;
			}
		}

		return false;
	}//end hasToolStep()

	/**
	 * Return every recorded step, in completion order (see class docblock).
	 *
	 * @return array<int, array{seq: int, type: string, name: string, startedAt: string,
	 *     endedAt: string, durationMs: int, outcome: string, ...<string, mixed>}>
	 *
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 */
	public function toArray(): array {
		return $this->steps;
	}//end toArray()
}//end class
