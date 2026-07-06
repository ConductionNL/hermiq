<?php

/**
 * Unit tests for the parity harness's StructuralComparator (task 7.1).
 *
 * Covers ONLY the harness's own comparison logic — never a faked LLM run:
 * tool-sequence comparison (ids/order/argument key sets asserted, argument
 * values logged), key-set equality, source count/shape comparison, scalar and
 * sequence comparison, and report rendering — in particular that text diffs
 * are INFO-only and can never flip the structural verdict. The live dual-path
 * run itself is documented in tests/parity/README.md and deliberately not
 * simulated here (tasks.md Quality reminders: "the parity harness must
 * exercise real LLM calls ... not stubbed responses").
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Parity
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

namespace OCA\Hermiq\Tests\Unit\Parity;

use OCA\Hermiq\Tests\Parity\StructuralComparator;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../parity/lib/StructuralComparator.php';

/**
 * Tests for the structural comparison + report rendering of the parity harness.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-7-1
 */
class ParityReportTest extends TestCase
{

    /**
     * Comparator under test.
     *
     * @var StructuralComparator
     */
    private StructuralComparator $comparator;

    /**
     * Set up a fresh comparator.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->comparator = new StructuralComparator();
    }//end setUp()

    // -------------------------------------------------------------------
    // Tool-call sequence.
    // -------------------------------------------------------------------

    /**
     * Identical tool ids + argument key sets pass even when the LLM-authored
     * argument VALUES differ — value diffs are logged in the detail, not
     * asserted (the structural bar of the 2026-07-06 decision).
     *
     * @return void
     */
    public function testToolSequencePassesWhenOnlyArgumentValuesDiffer(): void
    {
        $old = [
            ['toolId' => 'openregister.search_objects', 'arguments' => ['query' => 'invoices', 'limit' => 5]],
        ];
        $new = [
            ['toolId' => 'openregister.search_objects', 'arguments' => ['query' => 'the invoices', 'limit' => 10]],
        ];

        $result = $this->comparator->compareToolSequence('tool-call-sequence', $old, $new);

        $this->assertTrue($result['pass']);
        $this->assertStringContainsString('argument values differ', $result['detail']);
        $this->assertStringContainsString('logged, not asserted', $result['detail']);
    }//end testToolSequencePassesWhenOnlyArgumentValuesDiffer()

    /**
     * A different tool id at any position fails the check.
     *
     * @return void
     */
    public function testToolSequenceFailsOnDifferentToolId(): void
    {
        $old = [['toolId' => 'openregister.search_objects', 'arguments' => ['query' => 'x']]];
        $new = [['toolId' => 'openregister.get_object', 'arguments' => ['query' => 'x']]];

        $result = $this->comparator->compareToolSequence('tool-call-sequence', $old, $new);

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('tool id differs at position 0', $result['detail']);
    }//end testToolSequenceFailsOnDifferentToolId()

    /**
     * The same tools in a different ORDER fail — sequence order is part of
     * the structural bar ("in what order").
     *
     * @return void
     */
    public function testToolSequenceFailsOnDifferentOrder(): void
    {
        $callA = ['toolId' => 'a.first', 'arguments' => []];
        $callB = ['toolId' => 'b.second', 'arguments' => []];

        $result = $this->comparator->compareToolSequence('tool-call-sequence', [$callA, $callB], [$callB, $callA]);

        $this->assertFalse($result['pass']);
    }//end testToolSequenceFailsOnDifferentOrder()

    /**
     * A different argument KEY set fails even for the same tool id.
     *
     * @return void
     */
    public function testToolSequenceFailsOnDifferentArgumentKeys(): void
    {
        $old = [['toolId' => 'a.tool', 'arguments' => ['query' => 'x']]];
        $new = [['toolId' => 'a.tool', 'arguments' => ['query' => 'x', 'register' => 'hermiq']]];

        $result = $this->comparator->compareToolSequence('tool-call-sequence', $old, $new);

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('argument key set differs', $result['detail']);
    }//end testToolSequenceFailsOnDifferentArgumentKeys()

    /**
     * A different call COUNT fails and both sequences are described.
     *
     * @return void
     */
    public function testToolSequenceFailsOnDifferentCallCount(): void
    {
        $old = [['toolId' => 'a.tool', 'arguments' => []]];
        $new = [];

        $result = $this->comparator->compareToolSequence('tool-call-sequence', $old, $new);

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('call count differs: old=1', $result['detail']);
        $this->assertStringContainsString('new=0', $result['detail']);
    }//end testToolSequenceFailsOnDifferentCallCount()

    /**
     * Two empty sequences (a no-tool chat prompt) pass.
     *
     * @return void
     */
    public function testToolSequencePassesWhenBothEmpty(): void
    {
        $result = $this->comparator->compareToolSequence('tool-call-sequence', [], []);

        $this->assertTrue($result['pass']);
    }//end testToolSequencePassesWhenBothEmpty()

    // -------------------------------------------------------------------
    // Key sets (usage / timings / envelopes).
    // -------------------------------------------------------------------

    /**
     * Key-set equality is order-insensitive and value-blind: the usage shape
     * check must pass for different token counts.
     *
     * @return void
     */
    public function testKeySetPassesRegardlessOfOrderAndValues(): void
    {
        $old = ['promptTokens' => 10, 'completionTokens' => 20, 'latencyMs' => 512];
        $new = ['latencyMs' => 9000, 'completionTokens' => 7, 'promptTokens' => 3];

        $result = $this->comparator->compareKeySet('usage-keys', $old, $new);

        $this->assertTrue($result['pass']);
        $this->assertStringContainsString('completionTokens,latencyMs,promptTokens', $result['detail']);
    }//end testKeySetPassesRegardlessOfOrderAndValues()

    /**
     * A missing key on one side fails and both key sets are reported.
     *
     * @return void
     */
    public function testKeySetFailsOnMissingKey(): void
    {
        $old = ['promptTokens' => 10, 'latencyMs' => 512];
        $new = ['promptTokens' => 10];

        $result = $this->comparator->compareKeySet('usage-keys', $old, $new);

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('old=[latencyMs,promptTokens]', $result['detail']);
        $this->assertStringContainsString('new=[promptTokens]', $result['detail']);
    }//end testKeySetFailsOnMissingKey()

    // -------------------------------------------------------------------
    // RAG sources.
    // -------------------------------------------------------------------

    /**
     * Same count and same entry key shape pass, whatever the contents.
     *
     * @return void
     */
    public function testSourcesPassOnSameCountAndShape(): void
    {
        $old = [
            ['title' => 'Doc A', 'type' => 'file', 'score' => 0.91],
            ['title' => 'Obj B', 'type' => 'object', 'score' => 0.55],
        ];
        $new = [
            ['title' => 'Doc X', 'type' => 'file', 'score' => 0.42],
            ['title' => 'Obj Y', 'type' => 'object', 'score' => 0.13],
        ];

        $result = $this->comparator->compareSources('send-sources', $old, $new);

        $this->assertTrue($result['pass']);
        $this->assertStringContainsString('2 source(s)', $result['detail']);
    }//end testSourcesPassOnSameCountAndShape()

    /**
     * A source-count mismatch fails.
     *
     * @return void
     */
    public function testSourcesFailOnCountMismatch(): void
    {
        $result = $this->comparator->compareSources(
            'send-sources',
            [['title' => 'A']],
            []
        );

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('source count differs: old=1 new=0', $result['detail']);
    }//end testSourcesFailOnCountMismatch()

    /**
     * The same count with a different entry key shape fails.
     *
     * @return void
     */
    public function testSourcesFailOnShapeMismatch(): void
    {
        $result = $this->comparator->compareSources(
            'send-sources',
            [['title' => 'A', 'score' => 0.5]],
            [['title' => 'A', 'relevance' => 0.5]]
        );

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('source entry key shape differs', $result['detail']);
    }//end testSourcesFailOnShapeMismatch()

    // -------------------------------------------------------------------
    // Scalars and sequences.
    // -------------------------------------------------------------------

    /**
     * Scalar comparison (e.g. final message role) is strict equality.
     *
     * @return void
     */
    public function testScalarComparison(): void
    {
        $this->assertTrue($this->comparator->compareScalar('final-message-role', 'assistant', 'assistant')['pass']);
        $this->assertFalse($this->comparator->compareScalar('final-message-role', 'assistant', 'user')['pass']);
        // Strict: '200' !== 200.
        $this->assertFalse($this->comparator->compareScalar('gate-http-status', 200, '200')['pass']);
    }//end testScalarComparison()

    /**
     * Sequence comparison (persisted role order) is order-sensitive.
     *
     * @return void
     */
    public function testSequenceComparison(): void
    {
        $pass = $this->comparator->compareSequence(
            'persisted-role-sequence',
            ['user', 'assistant'],
            ['user', 'assistant']
        );
        $fail = $this->comparator->compareSequence(
            'persisted-role-sequence',
            ['user', 'assistant'],
            ['assistant', 'user']
        );

        $this->assertTrue($pass['pass']);
        $this->assertStringContainsString('user -> assistant', $pass['detail']);
        $this->assertFalse($fail['pass']);
    }//end testSequenceComparison()

    // -------------------------------------------------------------------
    // Report rendering: text diffs are INFO, never a failure input.
    // -------------------------------------------------------------------

    /**
     * A report whose structural checks all pass stays PASS even when the two
     * response texts are completely different — the diff appears only in the
     * INFO block below the verdict line.
     *
     * @return void
     */
    public function testTextDiffIsInfoOnlyAndNeverFlipsTheVerdict(): void
    {
        $checks = [
            $this->comparator->compareScalar('final-message-role', 'assistant', 'assistant'),
        ];
        $info   = $this->comparator->textDiffInfo(
            'response-text',
            "Paris is the capital of France.",
            "The capital city of France is Paris, of course."
        );

        $this->assertTrue($this->comparator->allPass($checks));

        $report      = $this->comparator->renderReport($checks, [$info]);
        $verdictPos  = strpos($report, '== Result: PASS (1/1 structural checks passed)');
        $infoPos     = strpos($report, 'logged for human review, never asserted');

        $this->assertNotFalse($verdictPos);
        $this->assertNotFalse($infoPos);
        // The INFO block renders below the verdict so a diff cannot read as a check.
        $this->assertGreaterThan($verdictPos, $infoPos);
        $this->assertStringContainsString('-Paris is the capital of France.', $report);
        $this->assertStringContainsString('+The capital city of France is Paris, of course.', $report);
    }//end testTextDiffIsInfoOnlyAndNeverFlipsTheVerdict()

    /**
     * Identical texts render as `(texts identical)` instead of a diff.
     *
     * @return void
     */
    public function testTextDiffOfIdenticalTexts(): void
    {
        $info = $this->comparator->textDiffInfo('response-text', "Same answer.", "Same answer.");

        $this->assertSame('(texts identical)', $info['text']);
    }//end testTextDiffOfIdenticalTexts()

    /**
     * The diff keeps common lines (space prefix) and marks removals/additions.
     *
     * @return void
     */
    public function testTextDiffMarksCommonAndChangedLines(): void
    {
        $info = $this->comparator->textDiffInfo(
            'response-text',
            "line one\nline two\nline three",
            "line one\nline 2\nline three"
        );

        $this->assertStringContainsString(' line one', $info['text']);
        $this->assertStringContainsString('-line two', $info['text']);
        $this->assertStringContainsString('+line 2', $info['text']);
        $this->assertStringContainsString(' line three', $info['text']);
    }//end testTextDiffMarksCommonAndChangedLines()

    /**
     * A failing check renders as [FAIL], flips the verdict line, and
     * allPass() returns false.
     *
     * @return void
     */
    public function testFailingCheckRendersFailVerdict(): void
    {
        $checks = [
            $this->comparator->compareScalar('final-message-role', 'assistant', 'assistant'),
            $this->comparator->compareKeySet('usage-keys', ['a' => 1], ['b' => 2]),
        ];

        $this->assertFalse($this->comparator->allPass($checks));

        $report = $this->comparator->renderReport($checks);

        $this->assertStringContainsString('[PASS] final-message-role', $report);
        $this->assertStringContainsString('[FAIL] usage-keys', $report);
        $this->assertStringContainsString('== Result: FAIL (1/2 structural checks passed)', $report);
    }//end testFailingCheckRendersFailVerdict()

    /**
     * An empty info list renders no INFO block at all.
     *
     * @return void
     */
    public function testReportWithoutInfosHasNoInfoBlock(): void
    {
        $report = $this->comparator->renderReport(
            [$this->comparator->compareScalar('x', 1, 1)]
        );

        $this->assertStringNotContainsString('INFO', $report);
    }//end testReportWithoutInfosHasNoInfoBlock()
}//end class
