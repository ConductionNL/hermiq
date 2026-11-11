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
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Aligns and compares the tool steps of two runs.
 *
 * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
 */
class RunComparator
{

    /**
     * Compare two runs' step timelines and summaries.
     *
     * Each aligned row is marked `same`, `only-left`, `only-right` or
     * `different-outcome` (the same tool in both, with another outcome).
     *
     * @param ComparedRun $left  The left run.
     * @param ComparedRun $right The right run.
     *
     * @return array{steps: list<array{mark: string, left: ?array, right: ?array}>, differences: int, summaryChanged: bool}
     *
     * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
     */
    public function compareRuns(ComparedRun $left, ComparedRun $right): array
    {
        $rows = $this->alignedRows(left: $this->toolSteps(steps: $left->steps), right: $this->toolSteps(steps: $right->steps));

        return [
            'steps'          => $rows,
            'differences'    => count(array_filter($rows, static fn (array $row): bool => $row['mark'] !== 'same')),
            'summaryChanged' => ($left->summary !== $right->summary),
        ];

    }//end compareRuns()

    /**
     * The aligned rows of two tool-step lists, each with its mark.
     *
     * @param list<array<string, mixed>> $left  The left run's tool steps.
     * @param list<array<string, mixed>> $right The right run's tool steps.
     *
     * @return list<array{mark: string, left: ?array, right: ?array}>
     *
     * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
     */
    private function alignedRows(array $left, array $right): array
    {
        $rows = [];
        foreach ($this->align(left: $left, right: $right) as [$leftIndex, $rightIndex]) {
            $leftStep  = $this->stepAt(steps: $left, index: $leftIndex);
            $rightStep = $this->stepAt(steps: $right, index: $rightIndex);
            $rows[]    = ['mark' => $this->mark(leftStep: $leftStep, rightStep: $rightStep), 'left' => $leftStep, 'right' => $rightStep];
        }

        return $rows;

    }//end alignedRows()

    /**
     * The step at an aligned index, or null for a gap.
     *
     * @param list<array<string, mixed>> $steps The tool steps.
     * @param int|null                   $index The aligned index, or null.
     *
     * @return array<string, mixed>|null
     */
    private function stepAt(array $steps, ?int $index): ?array
    {
        if ($index === null) {
            return null;
        }

        return $steps[$index];

    }//end stepAt()

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
     * @spec openspec/specs/run-replay-and-dry-run/spec.md#requirement-steps-are-aligned-so-an-extra-step-shows-as-one-difference-req-rcmp-002
     */
    public function toReplayDiff(array $originalSteps, array $replaySteps, string $originalSummary, string $replaySummary): array
    {
        $compared = $this->compareRuns(
            left: new ComparedRun(steps: $originalSteps, summary: $originalSummary),
            right: new ComparedRun(steps: $replaySteps, summary: $replaySummary)
        );

        $toolCalls = [];
        foreach ($this->replayPairs(rows: $compared['steps']) as $seq => $pair) {
            $toolCalls[] = [
                'seq'      => $seq,
                'original' => $pair['original'],
                'replay'   => $pair['replay'],
                'match'    => $pair['match'],
            ];
        }

        return [
            'toolSequenceMatches' => (in_array(false, array_column($toolCalls, 'match'), true) === false),
            'toolCalls'           => $toolCalls,
            'outputChanged'       => $compared['summaryChanged'],
        ];

    }//end toReplayDiff()

    /**
     * Pair aligned rows the way the replay screen shows them.
     *
     * A step only in the original directly followed by a step only in the replay is
     * one replaced call, so the two share a row.
     *
     * @param list<array{mark: string, left: ?array, right: ?array}> $rows The aligned rows.
     *
     * @return list<array{original: ?string, replay: ?string, match: bool, open: bool}> The replay rows.
     */
    private function replayPairs(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $last = (count($pairs) - 1);
            if ($row['mark'] === 'only-right' && $last >= 0 && $pairs[$last]['open'] === true) {
                $pairs[$last]['replay'] = $row['right']['name'];
                $pairs[$last]['match']  = false;
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

        return $pairs;

    }//end replayPairs()

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
        $rows  = count($left);
        $cols  = count($right);
        $lcs   = $this->lcsTable(left: $left, right: $right);
        $pairs = [];
        $row   = 0;
        $col   = 0;
        while ($row < $rows && $col < $cols) {
            if ($left[$row]['name'] === $right[$col]['name']) {
                $pairs[] = [$row++, $col++];
                continue;
            }

            if ($lcs[$row + 1][$col] >= $lcs[$row][$col + 1]) {
                $pairs[] = [$row++, null];
                continue;
            }

            $pairs[] = [null, $col++];
        }

        for (; $row < $rows; $row++) {
            $pairs[] = [$row, null];
        }

        for (; $col < $cols; $col++) {
            $pairs[] = [null, $col];
        }

        return $pairs;

    }//end align()

    /**
     * The longest-common-subsequence lengths of every suffix pair, by name.
     *
     * @param list<array{name: string}> $left  The left steps.
     * @param list<array{name: string}> $right The right steps.
     *
     * @return array<int, array<int, int>> The table; [row][col] is the LCS of the suffixes.
     */
    private function lcsTable(array $left, array $right): array
    {
        $rows = count($left);
        $cols = count($right);
        $lcs  = array_fill(0, ($rows + 1), array_fill(0, ($cols + 1), 0));
        for ($row = ($rows - 1); $row >= 0; $row--) {
            for ($col = ($cols - 1); $col >= 0; $col--) {
                if ($left[$row]['name'] === $right[$col]['name']) {
                    $lcs[$row][$col] = ($lcs[$row + 1][$col + 1] + 1);
                    continue;
                }

                $lcs[$row][$col] = max($lcs[$row + 1][$col], $lcs[$row][$col + 1]);
            }
        }

        return $lcs;

    }//end lcsTable()

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
