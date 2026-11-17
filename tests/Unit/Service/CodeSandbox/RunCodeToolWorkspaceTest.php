<?php

/**
 * The runCode tool on the agent's workspace: copy in, write back, and when it runs without one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxClient;
use OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings;
use OCA\Hermiq\Service\CodeSandbox\RunCodeTool;
use OCA\Hermiq\Service\CodeSandbox\WorkspaceTransfer;
use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\CodeSandbox\RunCodeTool
 * @uses   \OCA\Hermiq\Mcp\CodeSandboxToolDescriptors
 * @uses   \OCA\Hermiq\Service\CodeSandbox\CodeSandboxSettings
 * @uses   \OCA\Hermiq\Service\CodeSandbox\WorkspaceTransfer
 * @uses   \OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspacePathGuard
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceRunScope
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceException
 * @uses   \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 * @uses   \OCA\Hermiq\Service\Workspace\GitRunner
 * @uses   \OCA\Hermiq\Service\Workspace\GitCommand
 * @uses   \OCA\Hermiq\Service\Workspace\GitProcess
 * @uses   \OCA\Hermiq\Service\Workspace\CheckoutRequest
 */
final class RunCodeToolWorkspaceTest extends TestCase {

	private const RUN = 'run-1';

	private string $base;

	private string $root;

	private CodeSandboxClient&MockObject $client;

	private IRootFolder&MockObject $rootFolder;

	private WorkspaceRunScope $scope;

	private WorkspaceTransfer $transfer;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-runws-' . bin2hex(random_bytes(4));
		$forge = $this->base . '/forge/example-app';
		mkdir($forge, 0700, true);
		self::git($forge, 'init', '-q', '-b', 'development');
		file_put_contents($forge . '/aanvragen.csv', "id,dagen\n1,3\n");
		file_put_contents($forge . '/leesmij.md', "# Leesmij\n");
		self::git($forge, 'add', '.');
		self::git($forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'init');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$provider = new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: new GitRunner(), time: $time, baseDir: $this->base . '/workspaces');
		$provider->open(runKey: self::RUN, cloneUrl: 'file://' . $forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
		$this->root = $provider->root(runKey: self::RUN);
		$this->transfer = new WorkspaceTransfer(workspaces: $provider, guard: new WorkspacePathGuard());
		$this->scope = new WorkspaceRunScope();
		$this->client = $this->createMock(CodeSandboxClient::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	/**
	 * Spec scenario "Code adds a column to a file in the workspace": the
	 * workspace goes in as relative paths in a workspace run, only the changed
	 * file is written back, and a returned path out of the workspace is refused.
	 */
	public function testARunOnTheWorkspaceWritesBackOnlyWhatItChanged(): void {
		$this->scope->enter(runId: self::RUN, agentId: 'agent-1', userId: 'alice');
		$sent = [];
		$this->client->expects($this->once())->method('run')->willReturnCallback(
			static function (string $language, string $code, string $stdin, array $files, array $limits, ?string $uid, bool $workspace) use (&$sent): array {
				$sent = ['files' => $files, 'workspace' => $workspace, 'uid' => $uid];
				return [
					'exitCode' => 0, 'stdout' => '', 'stderr' => '', 'durationMs' => 12, 'timedOut' => false,
					'files' => [
						['name' => 'aanvragen.csv', 'content' => base64_encode("id,dagen,doorlooptijd\n1,3,72\n"), 'encoding' => 'base64'],
						['name' => 'leesmij.md', 'content' => base64_encode("# Leesmij\n"), 'encoding' => 'base64'],
						['name' => '../buiten.txt', 'content' => 'x'],
					],
					'limits' => ['wallSeconds' => 30, 'memoryMb' => 512],
				];
			}
		);

		$answer = $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass']);

		self::assertTrue($sent['workspace']);
		self::assertSame('alice', $sent['uid']);
		self::assertSame(['aanvragen.csv', 'leesmij.md'], array_column($sent['files'], 'name'));
		self::assertSame("id,dagen,doorlooptijd\n1,3,72\n", file_get_contents($this->root . '/aanvragen.csv'));
		self::assertSame([['path' => 'aanvragen.csv', 'action' => 'modified', 'bytes' => 29]], $answer['workspace']['written']);
		self::assertSame(['../buiten.txt'], array_column($answer['workspace']['refused'], 'path'));
		self::assertFileDoesNotExist($this->base . '/workspaces/buiten.txt');
		self::assertSame([], $answer['files'], 'The file contents are in the workspace, not in the answer.');
		self::assertSame('user', $answer['reach'], 'Workspace content lifts the reach as named files do.');
	}//end testARunOnTheWorkspaceWritesBackOnlyWhatItChanged()

	/**
	 * `paths` sends a subset; a workspace over the cap is refused with the limit and nothing runs.
	 */
	public function testPathsNarrowTheInputAndAnOversizedWorkspaceIsRefused(): void {
		$this->scope->enter(runId: self::RUN, agentId: 'agent-1', userId: 'alice');
		$handle = fopen($this->root . '/groot.bin', 'w');
		ftruncate($handle, WorkspaceTransfer::MAX_INPUT_BYTES);
		fclose($handle);

		$refused = $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass']);
		self::assertSame(
			['error' => ['code' => 'workspace_quota', 'message' => 'The workspace is too large to run code on (limit 10 MB). Remove files or run on fewer files.']],
			$refused
		);

		$names = null;
		$this->client->expects($this->once())->method('run')->willReturnCallback(
			static function (string $language, string $code, string $stdin, array $files) use (&$names): array {
				$names = array_column($files, 'name');
				return ['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'durationMs' => 1, 'timedOut' => false, 'files' => [], 'limits' => []];
			}
		);
		$answer = $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass', 'paths' => ['leesmij.md']]);

		self::assertSame(['leesmij.md'], $names);
		self::assertSame(['written' => [], 'refused' => []], $answer['workspace']);
	}//end testPathsNarrowTheInputAndAnOversizedWorkspaceIsRefused()

	/**
	 * Outside a governed run, with `useWorkspace` false, or when files of the
	 * acting user are named, the run goes without the workspace as before.
	 */
	public function testARunWithoutAWorkspaceIsUnchanged(): void {
		$calls = [];
		$this->client->method('run')->willReturnCallback(
			static function (string $language, string $code, string $stdin, array $files, array $limits, ?string $uid, bool $workspace) use (&$calls): array {
				$calls[] = [$files, $workspace];
				return ['exitCode' => 0, 'stdout' => '', 'stderr' => '', 'durationMs' => 1, 'timedOut' => false, 'files' => [], 'limits' => []];
			}
		);

		$outside = $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass']);
		$this->scope->enter(runId: self::RUN, agentId: 'agent-1', userId: 'alice');
		$declined = $this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass', 'useWorkspace' => false]);

		self::assertSame([[[], false], [[], false]], $calls);
		self::assertArrayNotHasKey('workspace', $outside);
		self::assertArrayNotHasKey('workspace', $declined);
		self::assertSame('self', $declined['reach']);
	}//end testARunWithoutAWorkspaceIsUnchanged()

	/**
	 * Asking for the workspace where there is none, or together with named files, is refused before dispatch.
	 */
	public function testAnExplicitWorkspaceRequestThatCannotBeMetIsRefused(): void {
		$this->client->expects($this->never())->method('run');

		self::assertSame(
			['error' => ['code' => 'workspace_absent', 'message' => 'This run has no workspace to run code on.']],
			$this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass', 'useWorkspace' => true])
		);
		self::assertSame(
			['error' => ['code' => 'workspace_absent', 'message' => 'This run has no workspace to run code on.']],
			$this->tool(withWorkspace: false)->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass', 'paths' => ['a.csv']])
		);
		self::assertSame(
			['error' => ['code' => 'invalid_argument', 'message' => 'paths is a list of workspace paths.']],
			$this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass', 'paths' => 'a.csv'])
		);

		$this->scope->enter(runId: self::RUN, agentId: 'agent-1', userId: 'alice');
		self::assertSame(
			['error' => ['code' => 'path_outside_workspace', 'message' => $this->outsideMessage()]],
			$this->tool()->invoke(uid: 'alice', toolId: 'hermiq.runCode', arguments: ['language' => 'python', 'code' => 'pass', 'paths' => ['../../forge/example-app/aanvragen.csv']])
		);
	}//end testAnExplicitWorkspaceRequestThatCannotBeMetIsRefused()

	/**
	 * The message the path guard gives for a path outside the workspace.
	 */
	private function outsideMessage(): string {
		try {
			$this->transfer->pack(workspaceKey: self::RUN, paths: ['../../forge/example-app/aanvragen.csv']);
		} catch (\OCA\Hermiq\Service\Workspace\WorkspaceException $e) {
			return $e->getMessage();
		}

		return '';
	}//end outsideMessage()

	/**
	 * The tool with the feature on, with or without the workspace collaborators.
	 */
	private function tool(bool $withWorkspace=true): RunCodeTool {
		$features = $this->createMock(AiFeatureLookup::class);
		$entity = new ObjectEntity();
		$entity->setObject(['enabled' => true]);
		$features->method('findBySlug')->willReturn($entity);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		return new RunCodeTool(
			features: $features,
			client: $this->client,
			settings: new CodeSandboxSettings(appConfig: $config),
			rootFolder: $this->rootFolder,
			logger: new NullLogger(),
			scope: ($withWorkspace === true ? $this->scope : null),
			transfer: ($withWorkspace === true ? $this->transfer : null)
		);
	}//end tool()

	private static function git(string $dir, string ...$args): void {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		exec($command . ' 2>&1', $out, $code);
		if ($code !== 0) {
			self::fail('git failed: ' . implode("\n", $out));
		}
	}//end git()
}//end class
