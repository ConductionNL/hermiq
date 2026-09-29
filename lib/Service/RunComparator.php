<?php

/**
 * Hermiq RunComparator.
 *
 * Lines up the tool steps of two runs (observability-compare-two-runs). The
 * steps are aligned by name with a longest common subsequence, so one extra
 * step in one run shows as one difference instead of shifting every later step
 * out of place, which is what a comparison by position did. The replay diff in
 * ScheduleService uses the same alignment through toReplayDiff().
 *
 * Pure: no I/O, no state.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Aligns and compares the tool steps of two runs.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */
class RunComparator
{

    /**
     * Compare two runs' step timelines and summaries.
     *
     * Each aligned row is marked `same`, `only-left`, `only-right` or
     * `different-outcome` (the same tool in both, with another outcome).
     *
     * @param array<int, mixed> $leftSteps    The left run's step timeline.
     * @param array<int, mixed> $rightSteps   The right run's step timeline.
     * @param string            $leftSummary  The left run's redacted summary.
     * @param string            $rightSummary The right run's redacted summary.
     *
     * @return array{steps: list<array{mark: string, left: ?array, right: ?array}>, differences: int, summaryChanged: bool}
     *
     * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
     */
    public function compare(array $leftSteps, array $rightSteps, string $leftSummary, string $rightSummary): array
    {
        $left  = $this->toolSteps(steps: $leftSteps);
        $right = $this->toolSteps(steps: $rightSteps);

        $rows        = [];
        $differences = 0;
        foreach ($this->align(left: $left, right: $right) as [$i, $j]) {
            $leftStep  = null;
            $rightStep = null;
            if ($i !== null) {
                $leftStep = $left[$i];
            }

            if ($j !== null) {
                $rightStep = $right[$j];
            }

            $mark = $this->mark(leftStep: $leftStep, rightStep: $rightStep);
            if ($mark !== 'same') {
                $differences++;
            }

            $rows[] = ['mark' => $mark, 'left' => $leftStep, 'right' => $rightStep];
        }

        return [
            'steps'          => $rows,
            'differences'    => $differences,
            'summaryChanged' => ($leftSummary !== $rightSummary),
        ];

    }//end compare()

    /**
     * The replay diff's shape, from the same alignment.
     *
     * Kept field for field for the replay screen: `toolCalls` rows of
     * {seq, original, replay, match}. A step only in the original directly
     * followed by a step only in the replay is one replaced call, shown on one row.
     *
     * @param array<int, mixed> $originalSteps   The original run's step timeline.
     * @param array<int, mixed> $replaySteps     The replay's step timeline.
     * @param string            $originalSummary The original run's redacted summary.
     * @param string            $replaySummary   The replay's redacted summary.
     *
     * @return array{toolSequenceMatches: bool, toolCalls: list<array>, outputChanged: bool}
     *
     * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
     */
    public function toReplayDiff(array $originalSteps, array $replaySteps, string $originalSummary, string $replaySummary): array
    {
        $compared = $this->compare(
            leftSteps: $originalSteps,
            rightSteps: $replaySteps,
            leftSummary: $originalSummary,
            rightSummary: $replaySummary
        );

        $pairs = [];
        foreach ($compared['steps'] as $row) {
            $last = (count($pairs) - 1);
            if ($row['mark'] === 'only-right' && $last >= 0
                && $pairs[$last]['replay'] === null && $pairs[$last]['open'] === true
            ) {
                $pairs[$last]['replay'] = $row['right']['name'];
                $pairs[$last]['open']   = false;
                continue;
            }

            $pairs[] = [
                'original' => ($row['left']['name'] ?? null),
                'replay'   => ($row['right']['name'] ?? null),
                'match'    => ($row['left'] !== null && $row['right'] !== null && $row['left']['name'] === $row['right']['name']),
                'open'     => ($row['mark'] === 'only-left'),
            ];
        }

        $toolCalls = [];
        $allMatch  = true;
        foreach ($pairs as $seq => $pair) {
            if ($pair['match'] === false) {
                $allMatch = false;
            }

            $toolCalls[] = [
                'seq'      => $seq,
                'original' => $pair['original'],
                'replay'   => $pair['replay'],
                'match'    => $pair['match'],
            ];
        }

        return [
            'toolSequenceMatches' => $allMatch,
            'toolCalls'           => $toolCalls,
            'outputChanged'       => $compared['summaryChanged'],
        ];

    }//end toReplayDiff()

    /**
     * The tool steps of a timeline, reduced to what is compared.
     *
     * @param array<int, mixed> $steps The step timeline.
     *
     * @return list<array{name: string, outcome: ?string, durationMs: ?int}> The tool steps, in order.
     */
    private function toolSteps(array $steps): array
    {
        $tools = [];
        foreach ($steps as $step) {
            if (is_array($step) === false || ($step['type'] ?? null) !== 'tool') {
                continue;
            }

            $outcome = null;
            if (is_string($step['outcome'] ?? null) === true) {
                $outcome = $step['outcome'];
            }

            $duration = null;
            if (is_numeric($step['durationMs'] ?? null) === true) {
                $duration = (int) $step['durationMs'];
            }

            $tools[] = ['name' => (string) ($step['name'] ?? ''), 'outcome' => $outcome, 'durationMs' => $duration];
        }

        return $tools;

    }//end toolSteps()

    /**
     * Align two step lists by name with a longest common subsequence.
     *
     * @param list<array{name: string}> $left  The left steps.
     * @param list<array{name: string}> $right The right steps.
     *
     * @return list<array{0: ?int, 1: ?int}> Index pairs; null where a side has no step.
     */
    private function align(array $left, array $right): array
    {
        $rows = count($left);
        $cols = count($right);
        $lcs  = array_fill(0, ($rows + 1), array_fill(0, ($cols + 1), 0));
        for ($i = ($rows - 1); $i >= 0; $i--) {
            for ($j = ($cols - 1); $j >= 0; $j--) {
                if ($left[$i]['name'] === $right[$j]['name']) {
                    $lcs[$i][$j] = ($lcs[$i + 1][$j + 1] + 1);
                    continue;
                }

                $lcs[$i][$j] = max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $pairs = [];
        $i     = 0;
        $j     = 0;
        while ($i < $rows && $j < $cols) {
            if ($left[$i]['name'] === $right[$j]['name']) {
                $pairs[] = [$i, $j];
                $i++;
                $j++;
                continue;
            }

            if ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $pairs[] = [$i, null];
                $i++;
                continue;
            }

            $pairs[] = [null, $j];
            $j++;
        }

        for (; $i < $rows; $i++) {
            $pairs[] = [$i, null];
        }

        for (; $j < $cols; $j++) {
            $pairs[] = [null, $j];
        }

        return $pairs;

    }//end align()

    /**
     * The mark for one aligned row.
     *
     * @param array{name: string, outcome: ?string}|null $leftStep  The left step, if any.
     * @param array{name: string, outcome: ?string}|null $rightStep The right step, if any.
     *
     * @return string One of same, only-left, only-right, different-outcome.
     */
    private function mark(?array $leftStep, ?array $rightStep): string
    {
        if ($rightStep === null) {
            return 'only-left';
        }

        if ($leftStep === null) {
            return 'only-right';
        }

        if ($leftStep['outcome'] !== $rightStep['outcome']) {
            return 'different-outcome';
        }

        return 'same';

    }//end mark()
}//end class
