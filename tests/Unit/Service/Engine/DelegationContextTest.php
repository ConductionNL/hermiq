<?php

/**
 * Unit tests for DelegationContext (sub-agent-delegation).
 *
 * Covers the request-scoped delegation call-stack: depth/ancestry starting at
 * zero/empty with no frame pushed, depth/ancestry accumulating correctly
 * across nested pushes, pop() reverting to the PREVIOUS frame (never the
 * popped one), and fan-out counting per frame.
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
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\DelegationContext;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sub-agent-delegation call-stack.
 *
 * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
 */
class DelegationContextTest extends TestCase
{

    /**
     * No frame pushed: depth is 0, ancestry is empty, current() is null.
     *
     * @return void
     */
    public function testNoFramePushedMeansZeroDepthAndEmptyAncestry(): void
    {
        $context = new DelegationContext();

        $this->assertSame(0, $context->depth());
        $this->assertSame([], $context->ancestorAgentIds());
        $this->assertSame(0, $context->fanOutCount());
        $this->assertNull($context->current());

    }//end testNoFramePushedMeansZeroDepthAndEmptyAncestry()

    /**
     * A top-level push is depth 1 with no ancestors.
     *
     * @return void
     */
    public function testTopLevelPushIsDepthOneWithNoAncestors(): void
    {
        $context = new DelegationContext();

        $frame = $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: null);

        $this->assertSame(1, $frame->depth);
        $this->assertSame([], $frame->ancestorAgentIds);
        $this->assertSame(1, $context->depth());
        $this->assertSame([], $context->ancestorAgentIds());
        $this->assertNull($frame->parentRunId);

    }//end testTopLevelPushIsDepthOneWithNoAncestors()

    /**
     * A nested push (agent A, then agent B) reaches depth 2 inside B's frame,
     * and B's ancestor chain includes A.
     *
     * @return void
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
     */
    public function testNestedPushReachesDepthTwoAndIncludesAncestor(): void
    {
        $context = new DelegationContext();

        $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: null);
        $frameB = $context->push(runId: 'run-b', agentId: 'agent-b', organisation: 'org-x', anchor: null);

        $this->assertSame(2, $context->depth());
        $this->assertSame(['agent-a'], $context->ancestorAgentIds());
        $this->assertSame(2, $frameB->depth);
        $this->assertContains('agent-a', $frameB->ancestorAgentIds);
        $this->assertSame('run-a', $frameB->parentRunId);

    }//end testNestedPushReachesDepthTwoAndIncludesAncestor()

    /**
     * pop() reverts current() to the PREVIOUS frame, never the popped one.
     *
     * @return void
     */
    public function testPopRevertsToThePreviousFrame(): void
    {
        $context = new DelegationContext();

        $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: null);
        $context->push(runId: 'run-b', agentId: 'agent-b', organisation: 'org-x', anchor: null);

        $context->pop();

        $current = $context->current();
        $this->assertNotNull($current);
        $this->assertSame('agent-a', $current->agentId);
        $this->assertSame('run-a', $current->runId);

    }//end testPopRevertsToThePreviousFrame()

    /**
     * Popping the last remaining frame reverts current() to null.
     *
     * @return void
     */
    public function testPoppingTheLastFrameRevertsToNull(): void
    {
        $context = new DelegationContext();

        $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: null);
        $context->pop();

        $this->assertNull($context->current());
        $this->assertSame(0, $context->depth());

    }//end testPoppingTheLastFrameRevertsToNull()

    /**
     * incrementFanOut() called three times on the current frame yields a
     * fanOutCount() of 3, scoped to that frame only.
     *
     * @return void
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded
     */
    public function testIncrementFanOutAccumulatesOnTheCurrentFrame(): void
    {
        $context = new DelegationContext();

        $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: null);
        $context->incrementFanOut();
        $context->incrementFanOut();
        $context->incrementFanOut();

        $this->assertSame(3, $context->fanOutCount());
        $this->assertSame(3, $context->current()?->fanOutCount);

    }//end testIncrementFanOutAccumulatesOnTheCurrentFrame()

    /**
     * A nested frame's fan-out count starts at zero, independent of its
     * parent's fan-out — fan-out is scoped per-turn, not per-tree.
     *
     * @return void
     */
    public function testNestedFrameFanOutIsIndependentOfParent(): void
    {
        $context = new DelegationContext();

        $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: null);
        $context->incrementFanOut();
        $context->incrementFanOut();

        $context->push(runId: 'run-b', agentId: 'agent-b', organisation: 'org-x', anchor: null);

        $this->assertSame(0, $context->fanOutCount());

        $context->pop();

        $this->assertSame(2, $context->fanOutCount());

    }//end testNestedFrameFanOutIsIndependentOfParent()

    /**
     * incrementFanOut()/current() on an empty stack are safe no-ops.
     *
     * @return void
     */
    public function testIncrementFanOutOnEmptyStackIsANoOp(): void
    {
        $context = new DelegationContext();

        $context->incrementFanOut();

        $this->assertSame(0, $context->fanOutCount());
        $this->assertNull($context->current());

    }//end testIncrementFanOutOnEmptyStackIsANoOp()

    /**
     * The anchor passed to push() is carried verbatim onto the frame.
     *
     * @return void
     *
     * @spec openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree
     */
    public function testAnchorIsCarriedVerbatimOntoTheFrame(): void
    {
        $context = new DelegationContext();
        $anchor  = new ObjectEntity();

        $frame = $context->push(runId: 'run-a', agentId: 'agent-a', organisation: 'org-x', anchor: $anchor);

        $this->assertSame($anchor, $frame->anchor);
        $this->assertSame($anchor, $context->current()?->anchor);

    }//end testAnchorIsCarriedVerbatimOntoTheFrame()
}//end class
