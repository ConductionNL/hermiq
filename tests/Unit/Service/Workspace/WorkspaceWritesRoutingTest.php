<?php

/**
 * Characterisation of WorkspaceWrites for the delete_file and
 * checkout_branch tools and for a workspace id that is not the run's: the
 * routing to the editor, the error envelope, and the one audit record per
 * call. Real git in a temp dir; the provider, the approval store and the
 * audit trail are doubles.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\WorkspaceAuditor;
use OCA\Hermiq\Service\Workspace\WorkspaceEditor;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspacePusher;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\Hermiq\Service\Workspace\WorkspaceWriteAuthoriser;
use OCA\Hermiq\Service\Workspace\WorkspaceWrites;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Routing and envelope of WorkspaceWrites.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceWrites
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceRunScope
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceWrite
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceWriteAuthoriser
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceEditor
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspacePathGuard
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceAuditor
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceException
 * @uses   \OCA\Hermiq\Service\Workspace\GitRunner
 * @uses   \OCA\Hermiq\Service\Workspace\GitCommand
 * @uses   \OCA\Hermiq\Service\Workspace\GitProcess
 * @uses   \OCA\Hermiq\Service\RedactionService
 */
final class WorkspaceWritesRoutingTest extends TestCase {

	private string $root;

	/**
	 * Audit outcomes per tool, in order.
	 *
	 * @var list<string>
	 */
	private array $audited = [];

	private WorkspaceWrites $writes;

	/**
	 * A one-commit repository on main, an approved run and the write half over it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/hermiq-wsr-' . bin2hex(random_bytes(4));
		mkdir($this->root, 0700, true);
		file_put_contents($this->root . '/README.md', "hello\n");
		foreach ([['init', '-q', '-b', 'main'], ['add', '.'], ['-c', 'user.name=S', '-c', 'user.email=s@example.invalid', 'commit', '-q', '-m', 's'], ['branch', 'other']] as $args) {
			shell_exec('git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $args)));
		}

		$provider = $this->createMock(WorkspaceProvider::class);
		$provider->method('workspaceId')->with('run-1')->willReturn('ws-1');
		$provider->method('root')->willReturn($this->root);
		$provider->method('describe')->willReturn(['repository' => 'example-org/example-app', 'ref' => 'main']);

		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('runPreAuthorisation')->willReturn(['uuid' => 'appr-1', 'status' => 'approved', 'decidedBy' => 'carol']);

		$trail = $this->createMock(AuditTrailMapper::class);
		$trail->method('recordObjectEvent')->willReturnCallback(function (ObjectAuditEvent $event): AuditTrail {
			$this->audited[] = $event->context['tool'] . ' ' . $event->context['outcome'];
			return new AuditTrail();
		});
		$objects = $this->createMock(ObjectService::class);

		$scope = new WorkspaceRunScope();
		$scope->enter(runId: 'run-1', agentId: 'agent-1', userId: 'alice');
		$guard = new WorkspacePathGuard();
		$git = new GitRunner();

		$this->writes = new WorkspaceWrites(
			scope: $scope,
			provider: $provider,
			guard: $guard,
			editor: new WorkspaceEditor(provider: $provider, guard: $guard, git: $git, userManager: $this->createMock(IUserManager::class)),
			authoriser: new WorkspaceWriteAuthoriser(approvals: $approvals),
			pusher: $this->createMock(WorkspacePusher::class),
			auditor: new WorkspaceAuditor(
				objects: $objects,
				auditTrail: $trail,
				redaction: new RedactionService($this->createMock(IConfig::class)),
				logger: new NullLogger()
			)
		);
	}//end setUp()

	/**
	 * Remove the repository.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
	}//end tearDown()

	/**
	 * delete_file reaches the editor and is audited once as ok.
	 *
	 * @return void
	 */
	public function testDeleteFileIsRoutedAndAudited(): void {
		self::assertSame(
			['path' => 'README.md', 'action' => 'deleted', 'bytes' => 0],
			$this->writes->invoke(toolId: WorkspaceToolDescriptors::DELETE_FILE, arguments: ['workspaceId' => 'ws-1', 'path' => 'README.md'])
		);
		self::assertFileDoesNotExist($this->root . '/README.md');
		self::assertSame([WorkspaceToolDescriptors::DELETE_FILE . ' ok'], $this->audited);
	}//end testDeleteFileIsRoutedAndAudited()

	/**
	 * checkout_branch reaches the editor; a refusal comes back as an error
	 * envelope and is audited with its code.
	 *
	 * @return void
	 */
	public function testCheckoutBranchIsRoutedAndItsRefusalEnveloped(): void {
		self::assertSame(['branch' => 'other'], $this->writes->invoke(toolId: WorkspaceToolDescriptors::CHECKOUT_BRANCH, arguments: ['branch' => 'other']));
		self::assertSame(
			['error' => [
				'code' => 'git_failed',
				'message' => 'That branch does not exist in the workspace, or uncommitted changes are in the way.',
			]],
			$this->writes->invoke(toolId: WorkspaceToolDescriptors::CHECKOUT_BRANCH, arguments: ['branch' => 'missing'])
		);
		self::assertSame(
			[WorkspaceToolDescriptors::CHECKOUT_BRANCH . ' ok', WorkspaceToolDescriptors::CHECKOUT_BRANCH . ' git_failed'],
			$this->audited
		);
	}//end testCheckoutBranchIsRoutedAndItsRefusalEnveloped()

	/**
	 * Another run's workspace id is refused before approval, writing and auditing.
	 *
	 * @return void
	 */
	public function testAnotherRunsWorkspaceIsRefusedBeforeAnything(): void {
		self::assertSame(
			['error' => ['code' => 'workspace_mismatch', 'message' => 'That workspace does not belong to this run.']],
			$this->writes->invoke(toolId: WorkspaceToolDescriptors::DELETE_FILE, arguments: ['workspaceId' => 'ws-2', 'path' => 'README.md'])
		);
		self::assertFileExists($this->root . '/README.md');
		self::assertSame([], $this->audited);
	}//end testAnotherRunsWorkspaceIsRefusedBeforeAnything()
}//end class
