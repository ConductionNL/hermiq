<?php

/**
 * The in-workspace write tools: approval first, the path guard on every write,
 * and commits authored as the run owner whatever the repository says.
 * Real git repositories in a temp dir; the ApprovalService is a double of the
 * real class (its payload is validated against the real Approval schema in
 * tests/Unit/Service/ApprovalServiceRunScopedTest.php).
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
use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\Workspace\ForgeLocator;
use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceEditor;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\Hermiq\Service\Workspace\WorkspaceToolset;
use OCA\Hermiq\Service\Workspace\WorkspaceWriteAuthoriser;
use OCA\Hermiq\Service\Workspace\WorkspaceWrites;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
 */
final class WorkspaceToolsetWriteTest extends TestCase {

	private string $base;

	private WorkspaceRunScope $scope;

	private ServerSideWorkspaceProvider $provider;

	/**
	 * Pre-authorisations by run id: status and decider.
	 *
	 * @var array<string, array{uuid: string, status: string, decidedBy: string}>
	 */
	private array $decisions = [];

	/**
	 * Requests made, by run id.
	 *
	 * @var array<int, string>
	 */
	private array $requested = [];

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-wsw-' . bin2hex(random_bytes(4));
		$forge = $this->base . '/forge/example-org/example-app.git';
		mkdir($forge . '/lib', 0700, true);
		file_put_contents($forge . '/lib/A.php', "<?php\n");
		file_put_contents($forge . '/README.md', "hello\n");
		self::git($forge, 'init', '-q', '-b', 'development');
		self::git($forge, 'add', '.');
		self::git($forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'first commit');
		$this->scope = new WorkspaceRunScope();
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	public function testTheCommitToolTakesNoIdentityArgument(): void {
		foreach (WorkspaceToolDescriptors::ALL as $descriptor) {
			if ($descriptor['id'] !== WorkspaceToolDescriptors::COMMIT) {
				continue;
			}

			$properties = array_keys((array)$descriptor['inputSchema']['properties']);
			self::assertSame([], array_values(array_intersect($properties, ['author', 'committer', 'name', 'email', 'identity'])));
			return;
		}

		self::fail('The commit tool is not in the catalogue.');
	}//end testTheCommitToolTakesNoIdentityArgument()

	public function testAnUnapprovedWriteIsRefusedBeforeItHappensAndAsksOnce(): void {
		$toolset = $this->opened(runId: 'run-a', owner: 'alice');

		$first = $toolset->invoke(toolId: WorkspaceToolDescriptors::WRITE_FILE, arguments: ['path' => 'lib/B.php', 'content' => "<?php\n"]);
		$second = $toolset->invoke(toolId: WorkspaceToolDescriptors::COMMIT, arguments: ['message' => 'x']);

		self::assertSame(WorkspaceException::APPROVAL_REQUIRED, $first['error']['code'] ?? null);
		self::assertSame(WorkspaceException::APPROVAL_REQUIRED, $second['error']['code'] ?? null);
		self::assertFileDoesNotExist($this->provider->root(runKey: 'run-a') . '/lib/B.php');
		self::assertSame(['run-a'], $this->requested, 'One request per run, not one per write.');
		self::assertSame('first commit', $this->lastSubject(runKey: 'run-a'));
	}//end testAnUnapprovedWriteIsRefusedBeforeItHappensAndAsksOnce()

	public function testAnApprovedRunEditsAndCommitsAsTheOwnerWhateverTheRepositorySays(): void {
		$toolset = $this->opened(runId: 'run-a', owner: 'alice');
		$this->approve(runId: 'run-a');
		$root = $this->provider->root(runKey: 'run-a');
		// A repository-local identity: what a hostile checkout would carry.
		self::git($root, 'config', 'user.name', 'Mallory');
		self::git($root, 'config', 'user.email', 'mallory@example.invalid');

		self::assertSame('created', $toolset->invoke(toolId: WorkspaceToolDescriptors::WRITE_FILE, arguments: ['path' => 'lib/B.php', 'content' => "<?php\n"])['action'] ?? null);
		$patch = "--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-hello\n+hello world\n";
		self::assertSame(['README.md'], $toolset->invoke(toolId: WorkspaceToolDescriptors::APPLY_PATCH, arguments: ['patch' => $patch])['files'] ?? null);
		self::assertSame('feature/b', $toolset->invoke(toolId: WorkspaceToolDescriptors::CREATE_BRANCH, arguments: ['branch' => 'feature/b'])['branch'] ?? null);
		$commit = $toolset->invoke(toolId: WorkspaceToolDescriptors::COMMIT, arguments: ['message' => 'feat: add B']);

		self::assertSame('alice', $commit['author'] ?? null, (string)json_encode($commit));
		self::assertSame('feature/b', $commit['branch']);
		self::assertSame(2, $commit['filesChanged']);
		exec('git -C ' . escapeshellarg($root) . ' log -1 --format=%an%x1f%ae%x1f%cn%x1f%ce', $out);
		self::assertSame("Alice Owner\x1falice@example.org\x1fAlice Owner\x1falice@example.org", $out[0]);
		self::assertStringNotContainsString($this->base, (string)json_encode($commit));
	}//end testAnApprovedRunEditsAndCommitsAsTheOwnerWhateverTheRepositorySays()

	public function testAPreAuthorisationCoversItsRunAndNoOther(): void {
		$this->opened(runId: 'run-a', owner: 'alice');
		$this->approve(runId: 'run-a');
		$toolset = $this->opened(runId: 'run-b', owner: 'alice');

		$result = $toolset->invoke(toolId: WorkspaceToolDescriptors::WRITE_FILE, arguments: ['path' => 'lib/B.php', 'content' => "<?php\n"]);

		self::assertSame(WorkspaceException::APPROVAL_REQUIRED, $result['error']['code'] ?? null);
		self::assertSame(['run-b'], $this->requested);
	}//end testAPreAuthorisationCoversItsRunAndNoOther()

	public function testADeniedRunStaysDenied(): void {
		$toolset = $this->opened(runId: 'run-a', owner: 'alice');
		$this->decisions['run-a'] = ['uuid' => 'appr-run-a', 'status' => 'denied', 'decidedBy' => 'carol'];

		$result = $toolset->invoke(toolId: WorkspaceToolDescriptors::DELETE_FILE, arguments: ['path' => 'README.md']);

		self::assertSame(WorkspaceException::APPROVAL_DENIED, $result['error']['code'] ?? null);
		self::assertSame([], $this->requested);
		self::assertFileExists($this->provider->root(runKey: 'run-a') . '/README.md');
	}//end testADeniedRunStaysDenied()

	public function testAnUnownedRunCannotCommitButStillReads(): void {
		$toolset = $this->opened(runId: 'run-a', owner: 'ghost');
		$this->approve(runId: 'run-a');
		$toolset->invoke(toolId: WorkspaceToolDescriptors::WRITE_FILE, arguments: ['path' => 'lib/B.php', 'content' => "<?php\n"]);

		$commit = $toolset->invoke(toolId: WorkspaceToolDescriptors::COMMIT, arguments: ['message' => 'x']);

		self::assertSame(WorkspaceException::OWNER_UNRESOLVABLE, $commit['error']['code'] ?? null);
		self::assertSame('first commit', $this->lastSubject(runKey: 'run-a'));
		self::assertArrayNotHasKey('error', $toolset->invoke(toolId: WorkspaceToolDescriptors::STATUS, arguments: []));
	}//end testAnUnownedRunCannotCommitButStillReads()

	public function testEveryWriteGoesThroughTheGuard(): void {
		$toolset = $this->opened(runId: 'run-a', owner: 'alice');
		$this->approve(runId: 'run-a');
		$root = $this->provider->root(runKey: 'run-a');

		$hook = $toolset->invoke(toolId: WorkspaceToolDescriptors::WRITE_FILE, arguments: ['path' => '.git/hooks/pre-commit', 'content' => "#!/bin/sh\n"]);
		$outside = $toolset->invoke(toolId: WorkspaceToolDescriptors::DELETE_FILE, arguments: ['path' => '../meta.json']);
		$patch = "--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-hello\n+bye\n--- a/.git/config\n+++ b/.git/config\n@@ -1 +1 @@\n-x\n+y\n";
		$mixed = $toolset->invoke(toolId: WorkspaceToolDescriptors::APPLY_PATCH, arguments: ['patch' => $patch]);
		$commitPath = $toolset->invoke(toolId: WorkspaceToolDescriptors::COMMIT, arguments: ['message' => 'x', 'paths' => ['.git/config']]);

		self::assertSame(WorkspaceException::PATH_FORBIDDEN, $hook['error']['code'] ?? null);
		self::assertSame(WorkspaceException::PATH_OUTSIDE_WORKSPACE, $outside['error']['code'] ?? null);
		self::assertSame(WorkspaceException::PATH_FORBIDDEN, $mixed['error']['code'] ?? null);
		self::assertSame(WorkspaceException::PATH_FORBIDDEN, $commitPath['error']['code'] ?? null);
		self::assertFileDoesNotExist($root . '/.git/hooks/pre-commit');
		self::assertSame("hello\n", file_get_contents($root . '/README.md'), 'No hunk of a refused patch is applied.');
	}//end testEveryWriteGoesThroughTheGuard()

	public function testAPatchThatRenamesOrLinksIsRejected(): void {
		$toolset = $this->opened(runId: 'run-a', owner: 'alice');
		$this->approve(runId: 'run-a');

		foreach (["diff --git a/x b/y\nrename from x\nrename to y\n", "diff --git a/l b/l\nnew file mode 120000\n--- /dev/null\n+++ b/l\n@@ -0,0 +1 @@\n+/etc/passwd\n"] as $patch) {
			$result = $toolset->invoke(toolId: WorkspaceToolDescriptors::APPLY_PATCH, arguments: ['patch' => $patch]);
			self::assertSame(WorkspaceException::PATCH_REJECTED, $result['error']['code'] ?? null);
		}
	}//end testAPatchThatRenamesOrLinksIsRejected()

	/**
	 * A toolset whose run has opened the example repository.
	 *
	 * @param string $runId The run id.
	 * @param string $owner The run owner.
	 *
	 * @return object The read and write halves, routed by tool id.
	 */
	private function opened(string $runId, string $owner): object {
		$toolset = $this->toolset();
		$this->scope->enter(runId: $runId, agentId: 'agent-1', userId: $owner);
		$opened = $toolset->invoke(toolId: WorkspaceToolDescriptors::OPEN, arguments: ['repository' => 'example-org/example-app', 'ref' => 'development']);
		self::assertArrayNotHasKey('error', $opened, (string)json_encode($opened));
		return $toolset;
	}//end opened()

	/**
	 * Record an approved pre-authorisation for a run.
	 *
	 * @param string $runId The run id.
	 *
	 * @return void
	 */
	private function approve(string $runId): void {
		$this->decisions[$runId] = ['uuid' => 'appr-' . $runId, 'status' => 'approved', 'decidedBy' => 'carol'];
	}//end approve()

	/**
	 * The subject of the workspace's latest commit.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return string
	 */
	private function lastSubject(string $runKey): string {
		exec('git -C ' . escapeshellarg($this->provider->root(runKey: $runKey)) . ' log -1 --format=%s', $out);
		return (string)($out[0] ?? '');
	}//end lastSubject()

	/**
	 * The toolset under test, over real collaborators and a double of the approval service.
	 *
	 * @return object The read and write halves, routed by tool id.
	 */
	private function toolset(): object {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'workspace_forge_base_url' ? 'file://' . $this->base . '/forge' : $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);
		$egress = $this->createMock(WebResearchEgressGuard::class);
		$egress->method('assertSafe')->willReturn(['allowed' => true, 'code' => null, 'message' => null]);
		$settings = $this->createMock(WebResearchSettingsHandler::class);
		$settings->method('getWebResearchSettingsOnly')->willReturn(['fetchAllowlist' => [], 'fetchDenylist' => []]);

		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice Owner');
		$alice->method('getEMailAddress')->willReturn('alice@example.org');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'alice' ? $alice : null));

		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('runPreAuthorisation')->willReturnCallback(fn (string $agentId, string $runId): ?array => ($this->decisions[$runId] ?? null));
		$approvals->method('requestRunPreAuthorisation')->willReturnCallback(
			function (string $agentId, string $runId): array {
				$this->requested[] = $runId;
				$this->decisions[$runId] = ['uuid' => 'appr-' . $runId, 'status' => 'pending', 'decidedBy' => ''];
				return $this->decisions[$runId];
			}
		);

		$git = new GitRunner();
		$guard = new WorkspacePathGuard();
		$this->provider = new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: $git, time: $time, baseDir: $this->base . '/workspaces');

		$reads = new WorkspaceToolset(
			scope: $this->scope,
			provider: $this->provider,
			guard: $guard,
			forge: new ForgeLocator(appConfig: $appConfig, guard: $egress, settings: $settings),
			git: $git
		);
		$writes = new WorkspaceWrites(
			scope: $this->scope,
			provider: $this->provider,
			guard: $guard,
			editor: new WorkspaceEditor(provider: $this->provider, guard: $guard, git: $git, userManager: $users),
			authoriser: new WorkspaceWriteAuthoriser(approvals: $approvals)
		);

		// Routed exactly as HermiqToolProvider routes (HermiqToolProviderWorkspaceRoutingTest).
		return new class($reads, $writes) {
			public function __construct(private WorkspaceToolset $reads, private WorkspaceWrites $writes) {
			}

			public function invoke(string $toolId, array $arguments): array {
				if (in_array($toolId, WorkspaceToolDescriptors::WRITE_IDS, true) === true) {
					return $this->writes->invoke(toolId: $toolId, arguments: $arguments);
				}

				return $this->reads->invoke(toolId: $toolId, arguments: $arguments);
			}
		};
	}//end toolset()

	/**
	 * Run git in a directory.
	 *
	 * @param string $dir     The directory.
	 * @param string ...$args The git arguments.
	 *
	 * @return void
	 */
	private static function git(string $dir, string ...$args): void {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		exec($command . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
	}//end git()
}//end class
