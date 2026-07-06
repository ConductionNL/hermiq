<?php

/**
 * Hermiq parity-harness structural comparator.
 *
 * Pure comparison logic for the agent-engine parity harness (task 7.1): the
 * harness runs the same `(agent, prompt)` pair through the old OpenRegister
 * chat path and the new in-app Hermiq Engine path, and this class decides —
 * structurally, never semantically — whether the two observations match.
 *
 * Parity bar (Ruben, 2026-07-06, proposal.md "Decisions"): structural-only.
 * Asserted: tool-call sequence (which tools, which argument keys, in what
 * order), persistence shape (message roles, sources shape), gate behavior
 * (kill-switch refusal envelope), and usage/timings key shapes. Deliberately
 * NOT asserted: response text (and LLM-authored tool-argument VALUES, which
 * are the same class of non-deterministic text) — those are rendered as
 * INFO entries for human review, and can never flip a check to FAIL.
 *
 * This class is pure (no IO, no HTTP, no clock) so its behavior is provable
 * by plain PHPUnit without faking an LLM run — see
 * tests/Unit/Parity/ParityReportTest.php.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Parity
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
 * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Parity;

/**
 * Structural (never semantic) comparison of two engine-path observations.
 *
 * Check results are plain arrays `{name, pass, detail}`; info entries are
 * `{label, text}` and are report-only by construction — renderReport() and
 * allPass() take them as separate arguments, so an info entry cannot
 * participate in the pass/fail outcome.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
 */
class StructuralComparator
{

    /**
     * Compare two tool-call sequences structurally.
     *
     * A tool call is `{toolId: string, arguments: array}` (the SSE `tool_call`
     * event payload shape both paths emit). PASS requires: same call count,
     * same toolId at every position, and the same sorted argument KEY set at
     * every position. Argument VALUES are LLM-authored (same
     * non-determinism class as response text) so value differences are noted
     * in the detail but do not fail the check.
     *
     * @param string $name     Check name for the report.
     * @param array  $oldCalls Tool calls observed on the old (OpenRegister) path.
     * @param array  $newCalls Tool calls observed on the new (Hermiq Engine) path.
     *
     * @return array{name: string, pass: bool, detail: string} Check result.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function compareToolSequence(string $name, array $oldCalls, array $newCalls): array
    {
        $oldShape = array_map([$this, 'toolCallShape'], array_values($oldCalls));
        $newShape = array_map([$this, 'toolCallShape'], array_values($newCalls));

        if (count($oldShape) !== count($newShape)) {
            return [
                'name'   => $name,
                'pass'   => false,
                'detail' => sprintf(
                    'call count differs: old=%d [%s] new=%d [%s]',
                    count($oldShape),
                    $this->describeToolShapes($oldShape),
                    count($newShape),
                    $this->describeToolShapes($newShape)
                ),
            ];
        }

        $valueNotes = [];
        foreach ($oldShape as $i => $old) {
            $new = $newShape[$i];

            if ($old['toolId'] !== $new['toolId']) {
                return [
                    'name'   => $name,
                    'pass'   => false,
                    'detail' => sprintf(
                        'tool id differs at position %d: old=%s new=%s',
                        $i,
                        $old['toolId'],
                        $new['toolId']
                    ),
                ];
            }

            if ($old['argumentKeys'] !== $new['argumentKeys']) {
                return [
                    'name'   => $name,
                    'pass'   => false,
                    'detail' => sprintf(
                        'argument key set differs at position %d (%s): old=[%s] new=[%s]',
                        $i,
                        $old['toolId'],
                        implode(',', $old['argumentKeys']),
                        implode(',', $new['argumentKeys'])
                    ),
                ];
            }

            if ($old['arguments'] !== $new['arguments']) {
                // Same tool, same key shape, different LLM-authored values:
                // structural PASS, values logged for the human reviewer.
                $valueNotes[] = sprintf(
                    'position %d (%s): argument values differ (logged, not asserted): old=%s new=%s',
                    $i,
                    $old['toolId'],
                    (string) json_encode($old['arguments']),
                    (string) json_encode($new['arguments'])
                );
            }
        }//end foreach

        $detail = sprintf('%d call(s), identical tool ids and argument key sets', count($oldShape));
        if ($valueNotes !== []) {
            $detail .= '; '.implode('; ', $valueNotes);
        }

        return [
            'name'   => $name,
            'pass'   => true,
            'detail' => $detail,
        ];
    }//end compareToolSequence()

    /**
     * Compare the (sorted) top-level key sets of two associative arrays.
     *
     * Used for the `usage` shape, the `timings` shape, the full send-response
     * envelope, and the gate refusal envelope: the DECISION bar is key-shape
     * equality, never value equality (token counts and latencies are
     * legitimately different between two live runs).
     *
     * @param string $name Check name for the report.
     * @param array  $old  Old-path associative array.
     * @param array  $new  New-path associative array.
     *
     * @return array{name: string, pass: bool, detail: string} Check result.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function compareKeySet(string $name, array $old, array $new): array
    {
        $oldKeys = $this->sortedKeys($old);
        $newKeys = $this->sortedKeys($new);

        if ($oldKeys === $newKeys) {
            return [
                'name'   => $name,
                'pass'   => true,
                'detail' => 'identical key set: ['.implode(',', $oldKeys).']',
            ];
        }

        return [
            'name'   => $name,
            'pass'   => false,
            'detail' => sprintf(
                'key sets differ: old=[%s] new=[%s]',
                implode(',', $oldKeys),
                implode(',', $newKeys)
            ),
        ];
    }//end compareKeySet()

    /**
     * Compare two RAG source lists: entry count plus entry key shape.
     *
     * The key shape is the sorted union of keys across all entries on each
     * side (source entries may be heterogeneous, e.g. file vs object
     * sources). Source CONTENT (titles, snippets, scores) is not asserted.
     *
     * @param string $name       Check name for the report.
     * @param array  $oldSources Old-path source entries.
     * @param array  $newSources New-path source entries.
     *
     * @return array{name: string, pass: bool, detail: string} Check result.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function compareSources(string $name, array $oldSources, array $newSources): array
    {
        $oldCount = count($oldSources);
        $newCount = count($newSources);

        if ($oldCount !== $newCount) {
            return [
                'name'   => $name,
                'pass'   => false,
                'detail' => sprintf('source count differs: old=%d new=%d', $oldCount, $newCount),
            ];
        }

        $oldShape = $this->keyUnion($oldSources);
        $newShape = $this->keyUnion($newSources);

        if ($oldShape !== $newShape) {
            return [
                'name'   => $name,
                'pass'   => false,
                'detail' => sprintf(
                    'source entry key shape differs: old=[%s] new=[%s]',
                    implode(',', $oldShape),
                    implode(',', $newShape)
                ),
            ];
        }

        return [
            'name'   => $name,
            'pass'   => true,
            'detail' => sprintf(
                '%d source(s), identical entry key shape [%s]',
                $oldCount,
                implode(',', $oldShape)
            ),
        ];
    }//end compareSources()

    /**
     * Compare two scalar observations with strict equality.
     *
     * Used for the final message role, the terminal SSE event type, the gate
     * refusal HTTP status, and the gate refusal `status` value.
     *
     * @param string $name Check name for the report.
     * @param mixed  $old  Old-path observation.
     * @param mixed  $new  New-path observation.
     *
     * @return array{name: string, pass: bool, detail: string} Check result.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function compareScalar(string $name, mixed $old, mixed $new): array
    {
        if ($old === $new) {
            return [
                'name'   => $name,
                'pass'   => true,
                'detail' => 'identical: '.$this->describeScalar($old),
            ];
        }

        return [
            'name'   => $name,
            'pass'   => false,
            'detail' => sprintf(
                'differs: old=%s new=%s',
                $this->describeScalar($old),
                $this->describeScalar($new)
            ),
        ];
    }//end compareScalar()

    /**
     * Compare two ordered string sequences (e.g. persisted message roles).
     *
     * @param string $name Check name for the report.
     * @param array  $old  Old-path sequence.
     * @param array  $new  New-path sequence.
     *
     * @return array{name: string, pass: bool, detail: string} Check result.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function compareSequence(string $name, array $old, array $new): array
    {
        $oldSeq = array_map(strval(...), array_values($old));
        $newSeq = array_map(strval(...), array_values($new));

        if ($oldSeq === $newSeq) {
            return [
                'name'   => $name,
                'pass'   => true,
                'detail' => 'identical sequence: ['.implode(' -> ', $oldSeq).']',
            ];
        }

        return [
            'name'   => $name,
            'pass'   => false,
            'detail' => sprintf(
                'sequences differ: old=[%s] new=[%s]',
                implode(' -> ', $oldSeq),
                implode(' -> ', $newSeq)
            ),
        ];
    }//end compareSequence()

    /**
     * Build an INFO entry holding a unified line diff of two texts.
     *
     * INFO entries are report-only: they are passed to renderReport()
     * separately from checks and never participate in allPass(). This is the
     * "response text diffs are LOGGED for human review, never asserted" half
     * of the 2026-07-06 parity decision.
     *
     * @param string $label   Info label for the report.
     * @param string $oldText Old-path text.
     * @param string $newText New-path text.
     *
     * @return array{label: string, text: string} Info entry.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function textDiffInfo(string $label, string $oldText, string $newText): array
    {
        return [
            'label' => $label,
            'text'  => $this->unifiedDiff($oldText, $newText),
        ];
    }//end textDiffInfo()

    /**
     * True when every structural check passed. Info entries are not consulted.
     *
     * @param array $checks Check results from the compare*() methods.
     *
     * @return bool Whether all structural checks passed.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function allPass(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['pass'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }//end allPass()

    /**
     * Render the structural report: PASS/FAIL per check, then the INFO block.
     *
     * The INFO block is explicitly titled as human-review material and is
     * rendered after the verdict line so a reader can never mistake a text
     * diff for a failed check.
     *
     * @param array $checks Check results (each `{name, pass, detail}`).
     * @param array $infos  Info entries (each `{label, text}`), never asserted.
     *
     * @return string Rendered plain-text report.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
     */
    public function renderReport(array $checks, array $infos=[]): string
    {
        $lines   = [];
        $lines[] = '== Structural parity checks '.str_repeat('=', 32);

        $passed = 0;
        foreach ($checks as $check) {
            $ok = (($check['pass'] ?? false) === true);
            if ($ok === true) {
                $passed++;
            }

            $lines[] = sprintf(
                '[%s] %s: %s',
                ($ok === true) ? 'PASS' : 'FAIL',
                (string) ($check['name'] ?? '(unnamed)'),
                (string) ($check['detail'] ?? '')
            );
        }

        $total   = count($checks);
        $verdict = ($passed === $total) ? 'PASS' : 'FAIL';
        $lines[] = sprintf('== Result: %s (%d/%d structural checks passed)', $verdict, $passed, $total);

        if ($infos !== []) {
            $lines[] = '== INFO — logged for human review, never asserted '.str_repeat('=', 10);
            foreach ($infos as $info) {
                $lines[] = '-- '.(string) ($info['label'] ?? '(unlabelled)').' --';
                $lines[] = (string) ($info['text'] ?? '');
            }
        }

        return implode("\n", $lines)."\n";
    }//end renderReport()

    /**
     * Normalize one tool call to its structural shape.
     *
     * @param mixed $call Raw tool-call payload (SSE `tool_call` event data).
     *
     * @return array{toolId: string, argumentKeys: array, arguments: array} Shape.
     */
    private function toolCallShape(mixed $call): array
    {
        $call = is_array($call) ? $call : [];
        $args = $call['arguments'] ?? [];
        if (is_array($args) === false) {
            $args = [];
        }

        return [
            'toolId'       => (string) ($call['toolId'] ?? ''),
            'argumentKeys' => $this->sortedKeys($args),
            'arguments'    => $args,
        ];
    }//end toolCallShape()

    /**
     * Human-readable one-line summary of a normalized tool-call shape list.
     *
     * @param array $shapes Normalized shapes from toolCallShape().
     *
     * @return string Summary such as `a.b(x,y) -> c.d(z)`.
     */
    private function describeToolShapes(array $shapes): string
    {
        $parts = array_map(
            static fn (array $shape): string => $shape['toolId'].'('.implode(',', $shape['argumentKeys']).')',
            $shapes
        );

        return implode(' -> ', $parts);
    }//end describeToolShapes()

    /**
     * Describe a scalar for report output.
     *
     * @param mixed $value The value to describe.
     *
     * @return string JSON-ish rendering of the value.
     */
    private function describeScalar(mixed $value): string
    {
        $encoded = json_encode($value);

        return is_string($encoded) ? $encoded : gettype($value);
    }//end describeScalar()

    /**
     * Sorted list of an array's own keys, as strings.
     *
     * @param array $data The array whose keys to list.
     *
     * @return array<int, string> Sorted key names.
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_map(strval(...), array_keys($data));
        sort($keys);

        return $keys;
    }//end sortedKeys()

    /**
     * Sorted union of keys across a list of (possibly heterogeneous) entries.
     *
     * @param array $entries List of associative entries.
     *
     * @return array<int, string> Sorted union of entry keys.
     */
    private function keyUnion(array $entries): array
    {
        $union = [];
        foreach ($entries as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            foreach (array_keys($entry) as $key) {
                $union[(string) $key] = true;
            }
        }

        $keys = array_keys($union);
        sort($keys);

        return $keys;
    }//end keyUnion()

    /**
     * Minimal line-based unified diff (LCS) of two texts.
     *
     * Emits ` ` (common), `-` (old only) and `+` (new only) prefixed lines.
     * Response texts are short (one chat turn), so the O(n*m) LCS table is
     * fine here.
     *
     * @param string $oldText Old-path text.
     * @param string $newText New-path text.
     *
     * @return string The diff, or `(texts identical)`.
     */
    private function unifiedDiff(string $oldText, string $newText): string
    {
        if ($oldText === $newText) {
            return '(texts identical)';
        }

        $oldLines = explode("\n", $oldText);
        $newLines = explode("\n", $newText);
        $n        = count($oldLines);
        $m        = count($newLines);

        // LCS length table.
        $lcs = array_fill(0, ($n + 1), array_fill(0, ($m + 1), 0));
        for ($i = ($n - 1); $i >= 0; $i--) {
            for ($j = ($m - 1); $j >= 0; $j--) {
                if ($oldLines[$i] === $newLines[$j]) {
                    $lcs[$i][$j] = ($lcs[($i + 1)][($j + 1)] + 1);
                } else {
                    $lcs[$i][$j] = max($lcs[($i + 1)][$j], $lcs[$i][($j + 1)]);
                }
            }
        }

        // Walk the table to emit the diff.
        $out = [];
        $i   = 0;
        $j   = 0;
        while ($i < $n && $j < $m) {
            if ($oldLines[$i] === $newLines[$j]) {
                $out[] = ' '.$oldLines[$i];
                $i++;
                $j++;
            } else if ($lcs[($i + 1)][$j] >= $lcs[$i][($j + 1)]) {
                $out[] = '-'.$oldLines[$i];
                $i++;
            } else {
                $out[] = '+'.$newLines[$j];
                $j++;
            }
        }

        while ($i < $n) {
            $out[] = '-'.$oldLines[$i];
            $i++;
        }

        while ($j < $m) {
            $out[] = '+'.$newLines[$j];
            $j++;
        }

        return implode("\n", $out);
    }//end unifiedDiff()
}//end class
