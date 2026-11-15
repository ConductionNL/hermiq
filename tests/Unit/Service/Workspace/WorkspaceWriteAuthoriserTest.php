<?php

/**
 * WorkspaceWriteAuthoriser: a governed workspace write needs this run's approved pre-authorisation.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-an-unapproved-write-is-refused-before-it-happens
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceWriteAuthoriser;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of the write gate.
 */
final class WorkspaceWriteAuthoriserTest extends TestCase {

	private const RUN = ['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice'];

	private const WORKSPACE = ['repository' => 'example-org/example-app', 'ref' => 'development'];

	/** @var array<int, array<string, string>> */
	private array $requested = [];

	/**
	 * The gate over an approval double that answers with the given decision.
	 *
	 * @param array<string, string>|null $decision The run's pre-authorisation, or null.
	 *
	 * @return WorkspaceWriteAuthoriser
	 */
	private function gate(?array $decision): WorkspaceWriteAuthoriser {
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('runPreAuthorisation')->willReturnCallback(
			static function (string $agentId, string $runId) use ($decision): ?array {
				return ($agentId === 'agent-1' && $runId === 'run-1') ? $decision : null;
			}
		);
		$approvals->method('requestRunPreAuthorisation')->willReturnCallback(
			function (string $agentId, string $runId, string $toolId, string $repository, string $ref): array {
				$this->requested[] = ['agentId' => $agentId, 'runId' => $runId, 'toolId' => $toolId, 'repository' => $repository, 'ref' => $ref];
				return ['uuid' => 'appr-new', 'status' => 'pending', 'decidedBy' => ''];
			}
		);

		return new WorkspaceWriteAuthoriser(approvals: $approvals);
	}//end gate()

	/**
	 * Assert the gate refuses with a code and message.
	 *
	 * @param WorkspaceWriteAuthoriser $gate    The gate.
	 * @param array<string, string>    $run     The run.
	 * @param string                   $code    The expected code.
	 * @param string                   $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(WorkspaceWriteAuthoriser $gate, array $run, string $code, string $message): void {
		try {
			$gate->assertAuthorised(run: $run, toolId: 'hermiq.workspaceWriteFile', workspace: self::WORKSPACE);
			self::fail('Expected ' . $code);
		} catch (WorkspaceException $e) {
			self::assertSame($code, $e->getErrorCode());
			self::assertSame($message, $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * An approved decision with a decider authorises, and says who decided.
	 *
	 * @return void
	 */
	public function testAnApprovedDecisionAuthorises(): void {
		$result = $this->gate(decision: ['uuid' => 'appr-1', 'status' => 'approved', 'decidedBy' => 'carol'])
			->assertAuthorised(run: self::RUN, toolId: 'hermiq.workspaceWriteFile', workspace: self::WORKSPACE);

		self::assertSame(['approvalId' => 'appr-1', 'decidedBy' => 'carol'], $result);
		self::assertSame([], $this->requested);
	}//end testAnApprovedDecisionAuthorises()

	/**
	 * A run without an agent cannot write and asks for nothing.
	 *
	 * @return void
	 */
	public function testARunWithoutAnAgentIsRefused(): void {
		$this->assertRefused(
			gate: $this->gate(decision: ['uuid' => 'appr-1', 'status' => 'approved', 'decidedBy' => 'carol']),
			run: ['runId' => 'run-1', 'agentId' => '', 'userId' => 'alice'],
			code: WorkspaceException::APPROVAL_REQUIRED,
			message: 'This run has no agent to route an approval to, so it cannot write.'
		);
		self::assertSame([], $this->requested);
	}//end testARunWithoutAnAgentIsRefused()

	/**
	 * A denied decision refuses for the rest of the run.
	 *
	 * @return void
	 */
	public function testADeniedDecisionRefuses(): void {
		$this->assertRefused(
			gate: $this->gate(decision: ['uuid' => 'appr-1', 'status' => 'denied', 'decidedBy' => 'carol']),
			run: self::RUN,
			code: WorkspaceException::APPROVAL_DENIED,
			message: 'A reviewer refused changes to this repository in this run. Do not try again in this run.'
		);
		self::assertSame([], $this->requested);
	}//end testADeniedDecisionRefuses()

	/**
	 * A pending decision, or an approval nobody signed, refuses with that request, asking for no other.
	 *
	 * @return void
	 */
	public function testAPendingOrUnsignedDecisionRefusesWithThatRequest(): void {
		foreach ([['uuid' => 'appr-1', 'status' => 'pending', 'decidedBy' => ''], ['uuid' => 'appr-2', 'status' => 'approved', 'decidedBy' => '']] as $decision) {
			$this->assertRefused(
				gate: $this->gate(decision: $decision),
				run: self::RUN,
				code: WorkspaceException::APPROVAL_REQUIRED,
				message: 'A person has to approve changes to example-org/example-app in this run first. The approval request is '
					. $decision['uuid'] . '. Nothing was written.'
			);
		}

		self::assertSame([], $this->requested);
	}//end testAPendingOrUnsignedDecisionRefusesWithThatRequest()

	/**
	 * Without any decision one request is raised for this run, tool and checkout, and the write is refused.
	 *
	 * @return void
	 */
	public function testWithoutADecisionOneRequestIsRaised(): void {
		$this->assertRefused(
			gate: $this->gate(decision: null),
			run: self::RUN,
			code: WorkspaceException::APPROVAL_REQUIRED,
			message: 'A person has to approve changes to example-org/example-app in this run first. The approval request is appr-new. Nothing was written.'
		);
		self::assertSame(
			[['agentId' => 'agent-1', 'runId' => 'run-1', 'toolId' => 'hermiq.workspaceWriteFile', 'repository' => 'example-org/example-app', 'ref' => 'development']],
			$this->requested
		);
	}//end testWithoutADecisionOneRequestIsRaised()
}//end class
