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
 * @spec openspec/changes/run-trace-observability/tasks.md#task-1-runtracecollector-ordered-in-memory-step-recorder
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
 * @spec openspec/changes/run-trace-observability/tasks.md#task-1-runtracecollector-ordered-in-memory-step-recorder
 */
class RunTraceCollector
{

    /**
     * Steps not yet ended, keyed by their start token.
     *
     * @var array<int, array{type: string, name: string, startedAt: DateTimeImmutable, startedAtMicro: float}>
     */
    private array $pending = [];

    /**
     * Completed steps, in COMPLETION (endStep call) order — see class docblock.
     *
     * @var array<int, array{seq: int, type: string, name: string, startedAt: string,
     *     endedAt: string, durationMs: int, outcome: string}>
     */
    private array $steps = [];

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
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function startStep(string $type, string $name): int
    {
        $token = $this->nextToken++;

        $this->pending[$token] = [
            'type'           => $type,
            'name'           => $name,
            'startedAt'      => new DateTimeImmutable('now', new DateTimeZone('UTC')),
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
     * @param int                  $token   The token returned by the matching `startStep()`.
     * @param string               $outcome The step outcome (`ok`|`error`, or a fixed label
     *                                      like `approved` for a reconstructed gate-wait step,
     *                                      or `would-have-called` for a dry-run-neutralised
     *                                      tool call — run-replay-and-dry-run).
     * @param array<string, mixed> $extra   Additional fields to merge onto the recorded step
     *                                      (run-replay-and-dry-run: `['arguments' => ...]` on a
     *                                      `would-have-called` step, empty for every other
     *                                      caller — zero behavior change). Applied BEFORE the
     *                                      fixed fields below, so `$extra` can never clobber
     *                                      `seq`/`type`/`name`/`startedAt`/`endedAt`/`durationMs`/
     *                                      `outcome` even if it tried to.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp
     */
    public function endStep(int $token, string $outcome, array $extra=[]): void
    {
        if (isset($this->pending[$token]) === false) {
            return;
        }

        $entry = $this->pending[$token];
        unset($this->pending[$token]);

        $endedAt    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $durationMs = (int) round((microtime(true) - $entry['startedAtMicro']) * 1000);

        $this->steps[] = array_merge(
            $extra,
            [
                'seq'        => $this->nextSeq++,
                'type'       => $entry['type'],
                'name'       => $entry['name'],
                'startedAt'  => $entry['startedAt']->format('c'),
                'endedAt'    => $endedAt->format('c'),
                'durationMs' => $durationMs,
                'outcome'    => $outcome,
            ]
        );

    }//end endStep()

    /**
     * Return every recorded step, in completion order (see class docblock).
     *
     * @return array<int, array{seq: int, type: string, name: string, startedAt: string,
     *     endedAt: string, durationMs: int, outcome: string}>
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function toArray(): array
    {
        return $this->steps;

    }//end toArray()
}//end class
