<?php

/**
 * Unit tests for the `#noapproval` grant waiver at the dispatch chokepoint
 * (agent-capability-reach).
 *
 * 🔴 A waiver is the one feature in the governance stack whose whole purpose is
 * to make the system do LESS checking, so the tests that matter most are the
 * ones asserting the checks it must NOT remove. Nearly every assertion here is
 * therefore about the un-waived case still refusing, and about the facade never
 * being reached — an implementation that let a waiver leak one step earlier in
 * `__call()` would still return a plausible envelope while having skipped a
 * constraint, an organisation's `deny`, or the owner check.
 *
 * The pairs are deliberately minimal: waived and un-waived differ by ONE
 * character of grant text, so a failure names the waiver rather than some
 * unrelated drift in the fixture.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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
 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;

/**
 * The waiver suppresses the human confirmation and nothing else.
 *
 * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
 */
class FacadeToolInvokerWaiverTest extends TestCase
{

    /**
     * The waived flow id.
     *
     * @var string
     */
    private const FLOW_A = '00000000-0000-0000-0000-00000000000a';

    /**
     * A flow granted but NOT waived.
     *
     * @var string
     */
    private const FLOW_B = '00000000-0000-0000-0000-00000000000b';

    /**
     * Map the LLPhant-safe name back to the dotted id.
     *
     * @return array<string,string>
     */
    private function idMap(): array
    {
        return ['openregister_runFlow' => 'openregister.runFlow'];
    }//end idMap()

    /**
     * Every tool in these tests is `confirm`-classified by org policy — that is
     * the human-in-the-loop a waiver is allowed to suppress.
     *
     * @return array<string,string>
     */
    private function confirmPolicy(): array
    {
        return ['openregister.runFlow' => 'confirm'];
    }//end confirmPolicy()

    /**
     * Parse a grant list exactly as `ToolLoop` does, so these tests exercise the
     * REAL parser rather than a hand-built map that could drift from it.
     *
     * @param array<int,string> $grants Raw grant entries.
     *
     * @return array{0:array<string,array<int,array>>, 1:array<string,array<int,array>>}
     *         `[argumentConstraints, waivedConstraintSets]`.
     */
    private function parse(array $grants): array
    {
        $resolver = new ToolGrantResolver();

        return [
            $resolver->argumentConstraints(grants: $grants),
            $resolver->waivedConstraintSets(grants: $grants),
        ];
    }//end parse()

    /**
     * A facade that records whether it was ever reached.
     *
     * @param bool $invoked Set to true when `invokeTool()` runs (by reference).
     *
     * @return ToolRegistryFacade
     */
    private function facadeRecording(bool &$invoked): ToolRegistryFacade
    {
        $facade = $this->createMock(ToolRegistryFacade::class);
        $facade->method('invokeTool')->willReturnCallback(
            static function () use (&$invoked) {
                $invoked = true;

                return ['result' => ['runUuid' => 'run-1'], 'isError' => false];
            }
        );

        return $facade;
    }//end facadeRecording()

    /**
     * 🔴 The positive case: a granted, conforming, waived invocation dispatches
     * without ever asking the approval service for anything.
     *
     * The `expects($this->never())` on the approval service is the load-bearing
     * assertion. Asserting only on the returned envelope would pass on an
     * implementation that created a pending approval and then dispatched anyway
     * — which would leave a trail of phantom approvals nobody ever answers.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waived-granted-conforming-invocation-runs-without-an-approval
     */
    public function testAWaivedGrantedConformingInvocationRunsWithoutAnApproval(): void
    {
        $invoked = false;
        $facade  = $this->facadeRecording($invoked);

        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects($this->never())->method('ensurePendingApprovalForToolCall');

        [$constraints, $waived] = $this->parse(
            ['openregister.runFlow?flowId='.self::FLOW_A.ToolGrantResolver::WAIVER_FRAGMENT]
        );

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            approvalService: $approvals,
            agentId: 'agent-1',
            mcpIdByName: $this->idMap(),
            toolPolicy: $this->confirmPolicy(),
            argumentConstraints: $constraints,
            ownerUid: 'alice',
            waivedConstraintSets: $waived
        );

        $decoded = json_decode($invoker->openregister_runFlow(flowId: self::FLOW_A), true);

        $this->assertTrue($invoked, 'The waived call must actually reach the facade.');
        $this->assertSame('run-1', $decoded['runUuid']);
    }//end testAWaivedGrantedConformingInvocationRunsWithoutAnApproval()

    /**
     * 🔴 THE CONTROL for the test above.
     *
     * The identical call, from a grant differing only by the absence of the
     * fragment, MUST still meet a human. Without this, the test above cannot
     * distinguish "the waiver works" from "the confirm gate was never wired in
     * this fixture at all".
     *
     * @return void
     */
    public function testTheSameCallWithoutTheFragmentStillRequiresConfirmation(): void
    {
        $invoked = false;
        $facade  = $this->facadeRecording($invoked);

        $approvals = $this->createMock(ApprovalService::class);
        $approvals->method('findApprovedUnconsumedToolCallApproval')->willReturn(null);
        $approvals->method('findPendingApprovalForToolCall')->willReturn(null);
        $approvals->expects($this->once())->method('ensurePendingApprovalForToolCall')
            ->willReturn($this->approvalStub());

        [$constraints, $waived] = $this->parse(['openregister.runFlow?flowId='.self::FLOW_A]);

        $this->assertSame([], $waived, 'This fixture must carry NO waiver — that is the control.');

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            approvalService: $approvals,
            agentId: 'agent-1',
            mcpIdByName: $this->idMap(),
            toolPolicy: $this->confirmPolicy(),
            argumentConstraints: $constraints,
            ownerUid: 'alice',
            waivedConstraintSets: $waived
        );

        $decoded = json_decode($invoker->openregister_runFlow(flowId: self::FLOW_A), true);

        $this->assertFalse($invoked, 'An unwaived confirm-classified call must NOT reach the facade.');
        $this->assertTrue($decoded['isError']);
    }//end testTheSameCallWithoutTheFragmentStillRequiresConfirmation()

    /**
     * 🔴 A waiver rides on ONE grant entry: a sibling grant for the same tool
     * still meets a human.
     *
     * This is the widening a per-tool waiver flag would have caused. The owner
     * waived flow A; nothing they wrote said anything about flow B.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-the-waiver-suppresses-the-approval-gate-and-nothing-else
     */
    public function testAWaiverOnOneEntryDoesNotCoverASiblingGrant(): void
    {
        $invoked = false;
        $facade  = $this->facadeRecording($invoked);

        $approvals = $this->createMock(ApprovalService::class);
        $approvals->method('findApprovedUnconsumedToolCallApproval')->willReturn(null);
        $approvals->method('findPendingApprovalForToolCall')->willReturn(null);
        $approvals->expects($this->once())->method('ensurePendingApprovalForToolCall')
            ->willReturn($this->approvalStub());

        [$constraints, $waived] = $this->parse(
            [
                'openregister.runFlow?flowId='.self::FLOW_A.ToolGrantResolver::WAIVER_FRAGMENT,
                'openregister.runFlow?flowId='.self::FLOW_B,
            ]
        );

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            approvalService: $approvals,
            agentId: 'agent-1',
            mcpIdByName: $this->idMap(),
            toolPolicy: $this->confirmPolicy(),
            argumentConstraints: $constraints,
            ownerUid: 'alice',
            waivedConstraintSets: $waived
        );

        $decoded = json_decode($invoker->openregister_runFlow(flowId: self::FLOW_B), true);

        $this->assertFalse($invoked, 'Flow B is granted and conforming, but never waived.');
        $this->assertTrue($decoded['isError']);
    }//end testAWaiverOnOneEntryDoesNotCoverASiblingGrant()

    /**
     * 🔴 A waiver must NOT relax an argument constraint.
     *
     * The waived grant pins flow A. Calling flow B is refused BEFORE the waiver
     * is ever consulted, with the pre-existing `grant_constraint_violated`
     * outcome — the same refusal an unwaived grant would produce.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waiver-does-not-relax-an-argument-constraint
     */
    public function testAWaiverDoesNotRelaxAnArgumentConstraint(): void
    {
        $invoked = false;
        $facade  = $this->facadeRecording($invoked);

        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects($this->never())->method('ensurePendingApprovalForToolCall');

        [$constraints, $waived] = $this->parse(
            ['openregister.runFlow?flowId='.self::FLOW_A.ToolGrantResolver::WAIVER_FRAGMENT]
        );

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            approvalService: $approvals,
            agentId: 'agent-1',
            mcpIdByName: $this->idMap(),
            toolPolicy: $this->confirmPolicy(),
            argumentConstraints: $constraints,
            ownerUid: 'alice',
            waivedConstraintSets: $waived
        );

        $decoded = json_decode($invoker->openregister_runFlow(flowId: self::FLOW_B), true);

        $this->assertFalse($invoked, 'A constraint violation must never reach the facade, waived or not.');
        $this->assertSame('grant_constraint_violated', $decoded['error']);
    }//end testAWaiverDoesNotRelaxAnArgumentConstraint()

    /**
     * 🔴 A waiver must NOT override an organisation's `deny`.
     *
     * `deny` is an admin-level refusal and the waiver is an owner-level opt-out;
     * letting the second beat the first would make every org policy advisory.
     * `deny` is checked before anything else in `__call()`, so this asserts the
     * ordering has not been rearranged.
     *
     * @return void
     */
    public function testAWaiverDoesNotOverrideAnOrganisationDeny(): void
    {
        $invoked = false;
        $facade  = $this->facadeRecording($invoked);

        [$constraints, $waived] = $this->parse(
            ['openregister.runFlow?flowId='.self::FLOW_A.ToolGrantResolver::WAIVER_FRAGMENT]
        );

        $invoker = new FacadeToolInvoker(
            facade: $facade,
            agentId: 'agent-1',
            mcpIdByName: $this->idMap(),
            toolPolicy: ['openregister.runFlow' => 'deny'],
            argumentConstraints: $constraints,
            ownerUid: 'alice',
            waivedConstraintSets: $waived
        );

        $decoded = json_decode($invoker->openregister_runFlow(flowId: self::FLOW_A), true);

        $this->assertFalse($invoked, 'A denied tool stays denied however the owner marked their grant.');
        $this->assertTrue($decoded['isError']);
    }//end testAWaiverDoesNotOverrideAnOrganisationDeny()

    /**
     * A waiver naming one tool has no effect on a different tool.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-a-waiver-does-not-make-an-ungranted-tool-runnable
     */
    public function testAWaiverOnOneToolDoesNotCoverAnother(): void
    {
        [, $waived] = $this->parse(['hermiq.readFile'.ToolGrantResolver::WAIVER_FRAGMENT]);

        $this->assertFalse(
            ToolGrantResolver::waives($waived, 'openregister.runFlow', ['flowId' => self::FLOW_A]),
            'A waiver must never generalise from the tool it names to any other.'
        );
        $this->assertTrue(
            ToolGrantResolver::waives($waived, 'hermiq.readFile', []),
            'Positive control: the tool that WAS waived still is.'
        );
    }//end testAWaiverOnOneToolDoesNotCoverAnother()

    /**
     * An `Approval`-shaped stub with a uuid the invoker can read.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity
     */
    private function approvalStub(): \OCA\OpenRegister\Db\ObjectEntity
    {
        $approval = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $approval->method('getUuid')->willReturn('00000000-0000-0000-0000-0000000000ff');

        return $approval;
    }//end approvalStub()
}//end class
