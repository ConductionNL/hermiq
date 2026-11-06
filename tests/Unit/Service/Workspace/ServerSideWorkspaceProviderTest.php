<?php

/**
 * ServerSideWorkspaceProvider (hermiq-runner-git-capability, task 1).
 *
 * Real git, a real local "forge" repository and a real temporary base
 * directory; only the Nextcloud config and clock are doubles.
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Run-keyed workspaces, their budget and their lifetime.
 */
final class ServerSideWorkspaceProviderTest extends TestCase {

	private string $base;

	private string $forge;

	private int $now = 1000000;

	/**
	 * @var array<string, int>
	 */
	private array $ints = [];

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-ws-' . bin2hex(random_bytes(4));
		$this->forge = $this->base . '/forge/example-app';
		mkdir($this->forge, 0700, true);
		self::git($this->forge, 'init', '-q', '-b', 'development');
		file_put_contents($this->forge . '/README.md', "hello\n");
		mkdir($this->forge . '/lib');
		file_put_contents($this->forge . '/lib/A.php', "<?php\n");
		self::git($this->forge, 'add', '.');
		self::git($this->forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'init');
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	public function testOpenReturnsAnOpaqueIdRepositoryRefAndHeadButNoPath(): void {
		$result = $this->provider()->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);

		self::assertSame(['workspaceId', 'repository', 'ref', 'headSha', 'fileCount'], array_keys($result));
		self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result['workspaceId']);
		self::assertSame(self::git($this->forge, 'rev-parse', 'HEAD'), $result['headSha']);
		self::assertSame(2, $result['fileCount']);
		self::assertStringNotContainsString($this->base, (string)json_encode($result));
		self::assertStringNotContainsString('/', $result['workspaceId']);
	}//end testOpenReturnsAnOpaqueIdRepositoryRefAndHeadButNoPath()

	public function testTwoRunsGetTwoWorkspacesAndTheIdIsNotTheRunId(): void {
		$provider = $this->provider();
		$a = $provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
		$b = $provider->open(runKey: 'run-b', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);

		self::assertNotSame($a['workspaceId'], $b['workspaceId']);
		self::assertNotSame($provider->root(runKey: 'run-a'), $provider->root(runKey: 'run-b'));
		self::assertStringNotContainsString('run-a', $a['workspaceId']);
	}//end testTwoRunsGetTwoWorkspacesAndTheIdIsNotTheRunId()

	public function testASecondOpenInTheSameRunReusesTheWorkspace(): void {
		$provider = $this->provider();
		$provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
		file_put_contents($provider->root(runKey: 'run-a') . '/scratch.txt', 'kept');
		$provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);

		self::assertFileExists($provider->root(runKey: 'run-a') . '/scratch.txt');
	}//end testASecondOpenInTheSameRunReusesTheWorkspace()

	public function testATreeOverTheFileBudgetIsRefusedAndNothingStays(): void {
		$this->ints['workspace_max_files'] = 1;
		$provider = $this->provider();

		try {
			$provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
			self::fail('An over-budget clone was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_QUOTA, $e->getErrorCode());
		}

		$this->assertAbsent(provider: $provider, runKey: 'run-a');
	}//end testATreeOverTheFileBudgetIsRefusedAndNothingStays()

	public function testAWriteOverTheSizeBudgetIsRefused(): void {
		$provider = $this->provider();
		$provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
		$provider->reserve(runKey: 'run-a', addBytes: 10, addFiles: 1);

		try {
			$provider->reserve(runKey: 'run-a', addBytes: ServerSideWorkspaceProvider::DEFAULT_MAX_BYTES, addFiles: 1);
			self::fail('An over-budget write was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_QUOTA, $e->getErrorCode());
		}
	}//end testAWriteOverTheSizeBudgetIsRefused()

	public function testAToolBeforeOpenIsRefusedAsAbsent(): void {
		$this->assertAbsent(provider: $this->provider(), runKey: 'run-a');
		self::assertDirectoryDoesNotExist($this->base . '/workspaces');
	}//end testAToolBeforeOpenIsRefusedAsAbsent()

	public function testAWorkspaceIdleBeyondTheRetentionWindowIsReaped(): void {
		$provider = $this->provider();
		$provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);

		$this->now += ServerSideWorkspaceProvider::DEFAULT_RETENTION_SECONDS - 1;
		self::assertSame(0, $provider->reap());

		$this->now += 2;
		self::assertSame(1, $provider->reap());
		$this->assertAbsent(provider: $provider, runKey: 'run-a');
	}//end testAWorkspaceIdleBeyondTheRetentionWindowIsReaped()

	public function testAnEmptyRunKeyIsRefused(): void {
		try {
			$this->provider()->root(runKey: '');
			self::fail('An empty run key was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::TOKEN_INVALID, $e->getErrorCode());
		}
	}//end testAnEmptyRunKeyIsRefused()

	public function testAFailedCloneLeavesNothingAndSaysNoPath(): void {
		$provider = $this->provider();
		try {
			$provider->open(runKey: 'run-a', cloneUrl: 'file://' . $this->base . '/missing', repository: 'example-org/missing', ref: 'development', depth: 5);
			self::fail('A failed clone was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::GIT_FAILED, $e->getErrorCode());
			self::assertStringNotContainsString($this->base, $e->getMessage());
		}

		$this->assertAbsent(provider: $provider, runKey: 'run-a');
	}//end testAFailedCloneLeavesNothingAndSaysNoPath()

	/**
	 * The provider under test.
	 */
	private function provider(): ServerSideWorkspaceProvider {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => ($this->ints[$key] ?? $default));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: new GitRunner(), time: $time, baseDir: $this->base . '/workspaces');
	}//end provider()

	/**
	 * Assert a run has no workspace.
	 */
	private function assertAbsent(ServerSideWorkspaceProvider $provider, string $runKey): void {
		try {
			$provider->root(runKey: $runKey);
			self::fail('A workspace is still reachable.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_ABSENT, $e->getErrorCode());
		}
	}//end assertAbsent()

	/**
	 * Run git in a directory and return trimmed stdout.
	 */
	private static function git(string $dir, string ...$args): string {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		exec($command . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
		return trim(implode("\n", $out));
	}//end git()
}//end class
