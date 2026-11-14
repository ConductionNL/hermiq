<?php

/**
 * Hermiq ResearchCaps.
 *
 * The four limits of one research run (design D4): tool calls, fetched sources,
 * minutes and tokens. Defaults are set per instance and may only be LOWERED per
 * agent; a `quick` run gets half of each. When gathering reaches one, the run
 * writes its report from what it has and says which limit stopped it in the
 * report's first line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

/**
 * The limits of one research run, and the sentence that names the one it reached.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */
final class ResearchCaps {

	/**
	 * The instance defaults (design D4).
	 *
	 * @var array{toolCalls: int, sources: int, minutes: int, tokens: int}
	 */
	public const DEFAULTS = ['toolCalls' => 40, 'sources' => 20, 'minutes' => 20, 'tokens' => 200000];

	/**
	 * Constructor.
	 *
	 * @param int $toolCalls At most this many tool calls.
	 * @param int $sources   At most this many fetched sources.
	 * @param int $minutes   At most this many minutes.
	 * @param int $tokens    At most this many tokens.
	 */
	public function __construct(
		public readonly int $toolCalls,
		public readonly int $sources,
		public readonly int $minutes,
		public readonly int $tokens,
	) {
	}//end __construct()

	/**
	 * The caps an instance sets, the defaults where it sets none or nothing usable.
	 *
	 * @param array<string, mixed> $settings The instance's research settings.
	 *
	 * @return self The caps.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public static function fromSettings(array $settings): self {
		$caps = [];
		foreach (self::DEFAULTS as $key => $default) {
			$caps[$key] = self::positive(value: ($settings[$key] ?? null), fallback: $default);
		}

		return new self(toolCalls: $caps['toolCalls'], sources: $caps['sources'], minutes: $caps['minutes'], tokens: $caps['tokens']);
	}//end fromSettings()

	/**
	 * These caps lowered by an agent's own limits. An agent can only lower a cap, never raise it.
	 *
	 * @param array<string, mixed> $agentLimits The agent's limits.
	 *
	 * @return self The caps.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function loweredBy(array $agentLimits): self {
		$current = $this->toArray();
		foreach ($current as $key => $value) {
			$current[$key] = min($value, self::positive(value: ($agentLimits[$key] ?? null), fallback: $value));
		}

		return new self(toolCalls: $current['toolCalls'], sources: $current['sources'], minutes: $current['minutes'], tokens: $current['tokens']);
	}//end loweredBy()

	/**
	 * The caps for a run of this depth: `quick` halves each (at least 1), `thorough` keeps them.
	 *
	 * @param string $depth `quick` or `thorough`; anything else reads as thorough.
	 *
	 * @return self The caps.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function forDepth(string $depth): self {
		if ($depth !== 'quick') {
			return $this;
		}

		$half = array_map(static fn (int $value): int => max(1, intdiv($value, 2)), $this->toArray());

		return new self(toolCalls: $half['toolCalls'], sources: $half['sources'], minutes: $half['minutes'], tokens: $half['tokens']);
	}//end forDepth()

	/**
	 * The first line of a report that stopped at a cap, or null while every cap holds.
	 *
	 * Sources are checked first: the run reads its last allowed page and then stops.
	 *
	 * @param int $toolCalls      Tool calls made.
	 * @param int $sources        Sources in the ledger.
	 * @param int $elapsedSeconds Seconds since the run started.
	 * @param int $tokens         Tokens used.
	 *
	 * @return string|null The sentence.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function stopReason(int $toolCalls, int $sources, int $elapsedSeconds, int $tokens): ?string {
		$reached = match (true) {
			$sources >= $this->sources => 'the source limit of ' . $this->sources,
			$toolCalls >= $this->toolCalls => 'the tool call limit of ' . $this->toolCalls,
			$elapsedSeconds >= ($this->minutes * 60) => 'the time limit of ' . $this->minutes . ' minutes',
			$tokens >= $this->tokens => 'the token limit of ' . number_format($this->tokens, 0, '.', ','),
			default => null,
		};
		if ($reached === null) {
			return null;
		}

		return 'This report stopped early: ' . $reached . ' was reached.';
	}//end stopReason()

	/**
	 * The caps as stored on the research handoff.
	 *
	 * @return array{toolCalls: int, sources: int, minutes: int, tokens: int}
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
	 */
	public function toArray(): array {
		return ['toolCalls' => $this->toolCalls, 'sources' => $this->sources, 'minutes' => $this->minutes, 'tokens' => $this->tokens];
	}//end toArray()

	/**
	 * A positive whole number, or the fallback.
	 *
	 * @param mixed $value    The value.
	 * @param int   $fallback The fallback.
	 *
	 * @return int The number.
	 */
	private static function positive(mixed $value, int $fallback): int {
		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true && (int)$value > 0) {
			return (int)$value;
		}

		return $fallback;
	}//end positive()
}//end class
