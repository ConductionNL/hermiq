<?php

/**
 * Unit tests for RunTraceCollector (run-trace-observability).
 *
 * Covers the ordered, in-memory step recorder: one recorded step per
 * start/end pair, sequence numbers reflecting completion order, and the
 * defensive contract that an unknown/stale end token never throws and never
 * corrupts already-recorded steps.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\RunTraceCollector;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the run-trace-observability step recorder.
 *
 * @spec openspec/changes/run-trace-observability/tasks.md#task-1-runtracecollector-ordered-in-memory-step-recorder
 */
class RunTraceCollectorTest extends TestCase
{

    /**
     * A single start/end pair yields one fully-populated step.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function testSingleStepIsRecordedWithComputedFields(): void
    {
        $collector = new RunTraceCollector();

        $token = $collector->startStep(type: 'tool', name: 'openregister.searchObjects');
        usleep(1000);
        $collector->endStep(token: $token, outcome: 'ok');

        $steps = $collector->toArray();
        $this->assertCount(1, $steps);

        $step = $steps[0];
        $this->assertSame(0, $step['seq']);
        $this->assertSame('tool', $step['type']);
        $this->assertSame('openregister.searchObjects', $step['name']);
        $this->assertSame('ok', $step['outcome']);
        $this->assertNotEmpty($step['startedAt']);
        $this->assertNotEmpty($step['endedAt']);
        // ISO-8601 with an offset, per design.md's response shape.
        $this->assertMatchesRegularExpression(
            '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$~',
            $step['startedAt']
        );
        $this->assertGreaterThanOrEqual(0, $step['durationMs']);

    }//end testSingleStepIsRecordedWithComputedFields()

    /**
     * Three sequential (non-overlapping) steps are returned in the order they
     * were started, with seq 0..2.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function testSequentialStepsPreserveOrderAndSeq(): void
    {
        $collector = new RunTraceCollector();

        $a = $collector->startStep(type: 'context', name: 'Context retrieval');
        $collector->endStep(token: $a, outcome: 'ok');

        $b = $collector->startStep(type: 'history', name: 'History build');
        $collector->endStep(token: $b, outcome: 'ok');

        $c = $collector->startStep(type: 'llm', name: 'LLM generation');
        $collector->endStep(token: $c, outcome: 'ok');

        $steps = $collector->toArray();
        $this->assertCount(3, $steps);
        $this->assertSame(['context', 'history', 'llm'], array_column($steps, 'type'));
        $this->assertSame([0, 1, 2], array_column($steps, 'seq'));

    }//end testSequentialStepsPreserveOrderAndSeq()

    /**
     * A step that is started but ends only AFTER a nested step both starts and
     * ends completes AFTER the nested step in the timeline — the ordering an
     * `llm` step wrapping a `tool` call relies on (design.md's documented
     * `context, history, tool, llm, delivery` example order).
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function testNestedStepCompletesBeforeItsEnclosingStep(): void
    {
        $collector = new RunTraceCollector();

        $llmToken  = $collector->startStep(type: 'llm', name: 'LLM generation');
        $toolToken = $collector->startStep(type: 'tool', name: 'openregister.searchObjects');
        $collector->endStep(token: $toolToken, outcome: 'ok');
        $collector->endStep(token: $llmToken, outcome: 'ok');

        $steps = $collector->toArray();
        $this->assertSame(['tool', 'llm'], array_column($steps, 'type'));
        $this->assertSame([0, 1], array_column($steps, 'seq'));

    }//end testNestedStepCompletesBeforeItsEnclosingStep()

    /**
     * An unknown/stale end token is silently ignored — never throws, never
     * corrupts already-recorded steps.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function testUnknownEndTokenIsIgnoredDefensively(): void
    {
        $collector = new RunTraceCollector();

        $token = $collector->startStep(type: 'tool', name: 'a.tool');
        $collector->endStep(token: $token, outcome: 'ok');

        // Ending the SAME token again (already consumed) and an entirely
        // fabricated token must both be no-ops.
        $collector->endStep(token: $token, outcome: 'error');
        $collector->endStep(token: 9999, outcome: 'error');

        $steps = $collector->toArray();
        $this->assertCount(1, $steps, 'A stale/unknown token must never add or mutate a step.');
        $this->assertSame('ok', $steps[0]['outcome'], 'The original recorded outcome must be untouched.');

    }//end testUnknownEndTokenIsIgnoredDefensively()

    /**
     * A fresh collector with no steps returns an empty array (never null,
     * never a fatal error) — the shape Engine::processMessage() falls back to
     * when no collector is supplied at all.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-1-1
     */
    public function testEmptyCollectorReturnsEmptyArray(): void
    {
        $collector = new RunTraceCollector();
        $this->assertSame([], $collector->toArray());

    }//end testEmptyCollectorReturnsEmptyArray()
}//end class
