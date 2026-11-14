<?php

/**
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-an-agent-can-keep-a-workspace-for-the-whole-conversation-req-wksp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use PHPUnit\Framework\TestCase;

/**
 * Which workspace a run reaches, by the agent's workspace mode.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceRunScope
 * @uses \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 */
final class WorkspaceRunScopeSessionTest extends TestCase {

	private function keyFor(string $runId, string $agentId, string $userId, string $conversationId, string $mode): string {
		$scope = new WorkspaceRunScope();
		$scope->enter(runId: $runId, agentId: $agentId, userId: $userId, conversationId: $conversationId, workspaceMode: $mode);

		return $scope->current()['workspaceKey'];
	}//end keyFor()

	public function testTwoRunsOfOneConversationReachOneWorkspace(): void {
		self::assertSame(
			$this->keyFor(runId: 'run-1', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-1', mode: 'session'),
			$this->keyFor(runId: 'run-2', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-1', mode: 'session')
		);
	}//end testTwoRunsOfOneConversationReachOneWorkspace()

	public function testAnotherConversationUserOrAgentReachesAnother(): void {
		$alice = $this->keyFor(runId: 'run-1', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-1', mode: 'session');

		self::assertNotSame($alice, $this->keyFor(runId: 'run-2', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-2', mode: 'session'));
		self::assertNotSame($alice, $this->keyFor(runId: 'run-2', agentId: 'agent-1', userId: 'bob', conversationId: 'conv-1', mode: 'session'));
		self::assertNotSame($alice, $this->keyFor(runId: 'run-2', agentId: 'agent-2', userId: 'alice', conversationId: 'conv-1', mode: 'session'));
	}//end testAnotherConversationUserOrAgentReachesAnother()

	public function testEveryOtherModeKeysByTheRun(): void {
		foreach (['', 'none', 'run', 'Session'] as $mode) {
			self::assertSame('run-1', $this->keyFor(runId: 'run-1', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-1', mode: $mode), $mode);
		}
	}//end testEveryOtherModeKeysByTheRun()

	public function testASessionRunWithoutAConversationKeysByTheRun(): void {
		self::assertSame('run-1', $this->keyFor(runId: 'run-1', agentId: 'agent-1', userId: 'alice', conversationId: '', mode: 'session'));
	}//end testASessionRunWithoutAConversationKeysByTheRun()

	public function testTheBindingStillCarriesTheRun(): void {
		$scope = new WorkspaceRunScope();
		$scope->enter(runId: 'run-1', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-1', workspaceMode: 'session');

		self::assertSame('run-1', $scope->current()['runId']);
		self::assertSame('conv-1', $scope->current()['conversationId']);
	}//end testTheBindingStillCarriesTheRun()
}//end class
