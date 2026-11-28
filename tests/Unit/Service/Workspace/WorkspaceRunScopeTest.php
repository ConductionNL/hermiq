<?php

/**
 * WorkspaceRunScope: the request-scoped holder of the verified run binding.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-one-run-cannot-address-another-runs-workspace
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use PHPUnit\Framework\TestCase;

/**
 * Entering, reading and leaving the binding; outside a run every read is refused.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceRunScope
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class WorkspaceRunScopeTest extends TestCase {

	/**
	 * The binding a run entered is returned as entered.
	 *
	 * @return void
	 */
	public function testTheEnteredBindingIsReturned(): void {
		$scope = new WorkspaceRunScope();
		$scope->enter(runId: 'run-1', agentId: 'agent-1', userId: 'alice');

		self::assertSame(
			['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice', 'conversationId' => '', 'workspaceKey' => 'run-1'],
			$scope->current()
		);
	}//end testTheEnteredBindingIsReturned()

	/**
	 * A second enter replaces the first binding; it never merges.
	 *
	 * @return void
	 */
	public function testASecondEnterReplacesTheBinding(): void {
		$scope = new WorkspaceRunScope();
		$scope->enter(runId: 'run-1', agentId: 'agent-1', userId: 'alice');
		$scope->enter(runId: 'run-2', agentId: 'agent-2', userId: 'bob');

		self::assertSame(
			['runId' => 'run-2', 'agentId' => 'agent-2', 'userId' => 'bob', 'conversationId' => '', 'workspaceKey' => 'run-2'],
			$scope->current()
		);
	}//end testASecondEnterReplacesTheBinding()

	/**
	 * Before any run is entered, the binding is refused.
	 *
	 * @return void
	 */
	public function testOutsideARunTheBindingIsRefused(): void {
		$this->assertRefused(scope: new WorkspaceRunScope());
	}//end testOutsideARunTheBindingIsRefused()

	/**
	 * After leaving, the run's binding is gone.
	 *
	 * @return void
	 */
	public function testLeavingDropsTheBinding(): void {
		$scope = new WorkspaceRunScope();
		$scope->enter(runId: 'run-1', agentId: 'agent-1', userId: 'alice');
		$scope->leave();

		$this->assertRefused(scope: $scope);
	}//end testLeavingDropsTheBinding()

	/**
	 * A binding with an empty run id is no run at all.
	 *
	 * @return void
	 */
	public function testAnEmptyRunIdIsRefused(): void {
		$scope = new WorkspaceRunScope();
		$scope->enter(runId: '', agentId: 'agent-1', userId: 'alice');

		$this->assertRefused(scope: $scope);
	}//end testAnEmptyRunIdIsRefused()

	/**
	 * Assert the scope refuses to give a binding.
	 *
	 * @param WorkspaceRunScope $scope The scope.
	 *
	 * @return void
	 */
	private function assertRefused(WorkspaceRunScope $scope): void {
		try {
			$scope->current();
			self::fail('Expected the binding to be refused.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::RUN_SCOPE_INVALID, $e->getErrorCode());
			self::assertSame('Workspace tools are only available inside a governed run.', $e->getMessage());
		}
	}//end assertRefused()
}//end class
