<?php

/**
 * Hermiq CoarseStepTimeline.
 *
 * Builds the coarse context/history/llm step timeline of a scheduled run from
 * the agent turn's `timings` bucket — the only step source on the default
 * OpenRegister ChatService path — and tells whether a step list holds any tool
 * step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

use DateTimeImmutable;

/**
 * The coarse step timeline of a run without tool-loop instrumentation.
 *
 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
 */
final class CoarseStepTimeline {

	/**
	 * The legs of an agent turn, last leg first: they are chained backward from
	 * the moment the turn returned.
	 *
	 * @var array<int, array{type: string, name: string, key: string}>
	 */
	private const LEGS_LAST_FIRST = [
		['type' => 'llm', 'name' => 'LLM generation', 'key' => 'llm'],
		['type' => 'history', 'name' => 'History build', 'key' => 'history'],
		['type' => 'context', 'name' => 'Context retrieval', 'key' => 'context'],
	];

	/**
	 * Build coarse context/history/llm steps from the agent-turn call's already-
	 * returned `timings` bucket (a formatted-seconds string per leg, e.g. `"0.18s"`).
	 *
	 * Each leg's real DURATION is known but not its absolute wall-clock start/end,
	 * so the legs are chained backward from `$anchorEnd` (the moment the
	 * agent-turn call returned) and returned in call order (context, history,
	 * llm) — a contiguous, honest synthetic timeline. A leg that is absent or does
	 * not parse as `<number>s` is skipped entirely rather than fabricated.
	 *
	 * @param array<string, mixed> $timings The `timings` bucket, or malformed/absent.
	 * @param DateTimeImmutable $anchorEnd The moment the agent-turn call returned.
	 *
	 * @return array<int, array<string, mixed>> The coarse steps, oldest leg first, `seq` 0..n-1.
	 *
	 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
	 */
	public function fromTimings(array $timings, DateTimeImmutable $anchorEnd): array {
		$backwardSteps = $this->chainBackward(timedLegs: $this->timedLegs(timings: $timings), anchorEnd: $anchorEnd);

		return $this->numbered(steps: array_reverse($backwardSteps));
	}//end fromTimings()

	/**
	 * Whether a step list holds at least one `tool` step.
	 *
	 * @param array<int, array<string, mixed>> $steps The run's steps.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-07-12-run-trace-observability/tasks.md#task-3-scheduleservice-captures-steps-and-includes-them-in-the-run-audit-write
	 */
	public function includesToolCall(array $steps): bool {
		foreach ($steps as $step) {
			if (($step['type'] ?? null) === 'tool') {
				return true;
			}
		}

		return false;
	}//end includesToolCall()

	/**
	 * The legs whose timing parses, last leg first, each with its `durationMs`.
	 *
	 * @param array<string, mixed> $timings The `timings` bucket.
	 *
	 * @return array<int, array<string, mixed>> The timed legs.
	 */
	private function timedLegs(array $timings): array {
		$timed = [];
		foreach (self::LEGS_LAST_FIRST as $leg) {
			$durationMs = $this->parseTimingSeconds(value: ($timings[$leg['key']] ?? null));
			if ($durationMs !== null) {
				$timed[] = $leg + ['durationMs' => $durationMs];
			}
		}

		return $timed;
	}//end timedLegs()

	/**
	 * Chain the timed legs backward from the anchor, each ending where the
	 * next-later leg started (millisecond precision).
	 *
	 * @param array<int, array<string, mixed>> $timedLegs The timed legs, last leg first.
	 * @param DateTimeImmutable $anchorEnd The moment the agent-turn call returned.
	 *
	 * @return array<int, array<string, mixed>> The steps, last leg first.
	 */
	private function chainBackward(array $timedLegs, DateTimeImmutable $anchorEnd): array {
		$cursor = $anchorEnd;
		$steps = [];
		foreach ($timedLegs as $leg) {
			$startedAt = $cursor->modify('-' . $leg['durationMs'] . ' milliseconds');
			$steps[] = $this->legStep(timedLeg: $leg + ['startedAt' => $startedAt->format('c'), 'endedAt' => $cursor->format('c')]);
			$cursor = $startedAt;
		}

		return $steps;
	}//end chainBackward()

	/**
	 * The step record of one timed, placed leg.
	 *
	 * @param array<string, mixed> $timedLeg The leg with its `durationMs`, `startedAt` and `endedAt`.
	 *
	 * @return array<string, mixed> The step.
	 */
	private function legStep(array $timedLeg): array {
		return [
			'type' => $timedLeg['type'],
			'name' => $timedLeg['name'],
			'startedAt' => $timedLeg['startedAt'],
			'endedAt' => $timedLeg['endedAt'],
			'durationMs' => $timedLeg['durationMs'],
			'outcome' => 'ok',
		];
	}//end legStep()

	/**
	 * Number the steps 0..n-1 in the given order.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps.
	 *
	 * @return array<int, array<string, mixed>> The steps with their `seq`.
	 */
	private function numbered(array $steps): array {
		$seq = 0;
		return array_map(
			static function (array $step) use (&$seq): array {
				$step['seq'] = $seq++;
				return $step;
			},
			$steps
		);
	}//end numbered()

	/**
	 * Parse a `timings` leg value (`"0.18s"`) into a millisecond duration.
	 *
	 * @param mixed $value The raw timing value.
	 *
	 * @return int|null The duration in milliseconds, or null when absent/malformed
	 *                  (never fabricated).
	 */
	private function parseTimingSeconds(mixed $value): ?int {
		if (is_string($value) === false || preg_match('~^(\d+(?:\.\d+)?)s$~', $value, $matches) !== 1) {
			return null;
		}

		return (int)round(((float)$matches[1]) * 1000);
	}//end parseTimingSeconds()
}//end class
