<?php

/**
 * WorkspaceSnapshot and the provider's restore (tools-agent-workspace-and-installed-tools, task 6).
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-runs-of-a-published-agent-start-from-its-pinned-build-req-build-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceSnapshot;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Archiving a workspace into a build and starting a workspace from one.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceSnapshot
 * @covers \OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider
 * @uses \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 * @uses \OCA\Hermiq\Service\Workspace\GitRunner
 * @uses \OCA\Hermiq\Service\Workspace\GitCommand
 * @uses \OCA\Hermiq\Service\Workspace\GitProcess
 * @uses \OCA\Hermiq\Service\Workspace\CheckoutRequest
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class WorkspaceSnapshotTest extends TestCase {

	private const OWNER = 'session:agent-1:conv-owner:alice';

	private const FIRST_USER = 'session:agent-1:conv-1:bob';

	private const SECOND_USER = 'session:agent-1:conv-2:carol';

	private string $base;

	private string $forge;

	private ServerSideWorkspaceProvider $provider;

	private WorkspaceSnapshot $snapshot;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-snap-' . bin2hex(random_bytes(4));
		$this->forge = $this->base . '/forge/example-app';
		mkdir($this->forge, 0700, true);
		self::git($this->forge, 'init', '-q', '-b', 'development');
		file_put_contents($this->forge . '/README.md', "hello\n");
		self::git($this->forge, 'add', '.');
		self::git($this->forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'init');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$this->provider = new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: new GitRunner(), time: $time, baseDir: $this->base . '/workspaces');
		$this->snapshot = new WorkspaceSnapshot(workspaces: $this->provider);
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	private function openOwnerWorkspaceWithTemplates(): string {
		$this->provider->open(runKey: self::OWNER, cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
		$root = $this->provider->root(runKey: self::OWNER);
		mkdir($root . '/sjablonen');
		file_put_contents($root . '/sjablonen/kwartaal.md', "# Kwartaal\n");
		file_put_contents($root . '/sjablonen/bijlage.csv', "a,b\n");
		return $root;
	}//end openOwnerWorkspaceWithTemplates()

	public function testTheArchiveListsEveryFileOutsideGitWithItsSize(): void {
		$this->openOwnerWorkspaceWithTemplates();

		$archive = $this->snapshot->archive(workspaceKey: self::OWNER);

		self::assertSame(
			[
				['path' => 'README.md', 'size' => 6],
				['path' => 'sjablonen/bijlage.csv', 'size' => 4],
				['path' => 'sjablonen/kwartaal.md', 'size' => 11],
			],
			$archive['files']
		);
		self::assertSame('example-org/example-app', $archive['repository']);
		self::assertSame('development', $archive['ref']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $archive['contentHash']);
		self::assertFileExists($this->snapshot->archivePath(contentHash: $archive['contentHash']));
	}//end testTheArchiveListsEveryFileOutsideGitWithItsSize()

	public function testTheContentHashFollowsTheFilesAndNothingElse(): void {
		$root = $this->openOwnerWorkspaceWithTemplates();
		$first = $this->snapshot->archive(workspaceKey: self::OWNER)['contentHash'];

		touch($root . '/sjablonen/kwartaal.md', 12345);
		self::assertSame($first, $this->snapshot->archive(workspaceKey: self::OWNER)['contentHash'], 'A changed time is not a changed file.');

		file_put_contents($root . '/sjablonen/kwartaal.md', "# Kwartaal 4\n");
		self::assertNotSame($first, $this->snapshot->archive(workspaceKey: self::OWNER)['contentHash']);
	}//end testTheContentHashFollowsTheFilesAndNothingElse()

	public function testTwoUsersStartFromTheSameFilesAndTheirChangesStayTheirOwn(): void {
		$this->openOwnerWorkspaceWithTemplates();
		$hash = $this->snapshot->archive(workspaceKey: self::OWNER)['contentHash'];
		$origin = ['repository' => 'example-org/example-app', 'ref' => 'development', 'buildId' => 'build-3'];

		$this->snapshot->restore(workspaceKey: self::FIRST_USER, contentHash: $hash, origin: $origin);
		$this->snapshot->restore(workspaceKey: self::SECOND_USER, contentHash: $hash, origin: $origin);

		$first = $this->provider->root(runKey: self::FIRST_USER);
		$second = $this->provider->root(runKey: self::SECOND_USER);
		self::assertSame("# Kwartaal\n", file_get_contents($first . '/sjablonen/kwartaal.md'));
		self::assertSame("# Kwartaal\n", file_get_contents($second . '/sjablonen/kwartaal.md'));
		self::assertSame('build-3', $this->provider->buildOf(runKey: self::FIRST_USER));
		self::assertSame(['repository' => 'example-org/example-app', 'ref' => 'development'], $this->provider->describe(runKey: self::FIRST_USER));

		file_put_contents($first . '/sjablonen/kwartaal.md', "changed by a run\n");
		self::assertSame("# Kwartaal\n", file_get_contents($second . '/sjablonen/kwartaal.md'));

		$this->provider->discard(runKey: self::SECOND_USER);
		$this->snapshot->restore(workspaceKey: self::SECOND_USER, contentHash: $hash, origin: $origin);
		self::assertSame("# Kwartaal\n", file_get_contents($this->provider->root(runKey: self::SECOND_USER) . '/sjablonen/kwartaal.md'), 'A run change never reaches the build.');
	}//end testTwoUsersStartFromTheSameFilesAndTheirChangesStayTheirOwn()

	public function testARestoredWorkspaceIsStillAGitCheckout(): void {
		$this->openOwnerWorkspaceWithTemplates();
		$hash = $this->snapshot->archive(workspaceKey: self::OWNER)['contentHash'];

		$this->snapshot->restore(workspaceKey: self::FIRST_USER, contentHash: $hash, origin: ['buildId' => 'b']);

		self::assertSame('init', self::git($this->provider->root(runKey: self::FIRST_USER), 'log', '-1', '--format=%s'));
	}//end testARestoredWorkspaceIsStillAGitCheckout()

	public function testAnOpenWorkspaceIsNeverOverwritten(): void {
		$this->openOwnerWorkspaceWithTemplates();
		$hash = $this->snapshot->archive(workspaceKey: self::OWNER)['contentHash'];

		$this->expectException(WorkspaceException::class);
		$this->expectExceptionMessage('This workspace is already open.');
		$this->snapshot->restore(workspaceKey: self::OWNER, contentHash: $hash, origin: []);
	}//end testAnOpenWorkspaceIsNeverOverwritten()

	public function testAnArchiveNamingAParentDirectoryIsRefusedWhole(): void {
		$hash = str_repeat('a', 64);
		mkdir($this->provider->buildStore(), 0700, true);
		$zip = new ZipArchive();
		$zip->open($this->snapshot->archivePath(contentHash: $hash), ZipArchive::CREATE);
		$zip->addFromString('ok.txt', 'fine');
		$zip->addFromString('../escape.txt', 'no');
		$zip->close();

		try {
			$this->snapshot->restore(workspaceKey: self::FIRST_USER, contentHash: $hash, origin: []);
			self::fail('An unsafe archive was restored.');
		} catch (WorkspaceException $e) {
			self::assertSame('The build archive names a path outside the workspace.', $e->getMessage());
		}

		self::assertFalse($this->provider->exists(runKey: self::FIRST_USER));
		self::assertFileDoesNotExist($this->base . '/workspaces/escape.txt');
	}//end testAnArchiveNamingAParentDirectoryIsRefusedWhole()

	public function testAHashThatIsNotASha256NamesNoFile(): void {
		$this->expectException(WorkspaceException::class);
		$this->snapshot->archivePath(contentHash: '../../etc/passwd');
	}//end testAHashThatIsNotASha256NamesNoFile()

	public function testAMissingArchiveOpensNoWorkspace(): void {
		try {
			$this->snapshot->restore(workspaceKey: self::FIRST_USER, contentHash: str_repeat('b', 64), origin: []);
			self::fail('A missing archive opened a workspace.');
		} catch (WorkspaceException $e) {
			self::assertSame('The build archive could not be read.', $e->getMessage());
		}

		self::assertFalse($this->provider->exists(runKey: self::FIRST_USER));
	}//end testAMissingArchiveOpensNoWorkspace()

	public function testArchivingWithoutAWorkspaceIsRefused(): void {
		$this->expectException(WorkspaceException::class);
		$this->snapshot->archive(workspaceKey: self::OWNER);
	}//end testArchivingWithoutAWorkspaceIsRefused()

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
