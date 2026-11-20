<?php

/**
 * WorkspaceTransfer (tools-agent-workspace-and-installed-tools, task 2).
 *
 * A real workspace (real git, a local "forge" repository, a temporary base
 * directory) and a stubbed sandbox: the test plays the run, taking the packed
 * files and returning what the run would.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\CodeSandbox
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-sandbox-runs-work-on-the-workspace-and-write-back-what-they-change-req-wksp-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Service\CodeSandbox\WorkspaceTransfer;
use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Copy in, copy back, the input cap and confinement of returned paths.
 *
 * @covers \OCA\Hermiq\Service\CodeSandbox\WorkspaceTransfer
 * @uses \OCA\Hermiq\Service\Workspace\ServerSideWorkspaceProvider
 * @uses \OCA\Hermiq\Service\Workspace\WorkspacePathGuard
 * @uses \OCA\Hermiq\Service\Workspace\SessionWorkspaceKey
 * @uses \OCA\Hermiq\Service\Workspace\GitRunner
 * @uses \OCA\Hermiq\Service\Workspace\GitCommand
 * @uses \OCA\Hermiq\Service\Workspace\GitProcess
 * @uses \OCA\Hermiq\Service\Workspace\CheckoutRequest
 * @uses \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class WorkspaceTransferTest extends TestCase {

	private const KEY = 'session:agent-1:conv-1:alice';

	private string $base;

	private string $forge;

	private string $root;

	/**
	 * App config integers by key.
	 *
	 * @var array<string, int>
	 */
	private array $ints = [];

	private ServerSideWorkspaceProvider $provider;

	private WorkspaceTransfer $transfer;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-xfer-' . bin2hex(random_bytes(4));
		$this->forge = $this->base . '/forge/example-app';
		mkdir($this->forge, 0700, true);
		self::git($this->forge, 'init', '-q', '-b', 'development');
		file_put_contents($this->forge . '/data.csv', "a,b\n1,2\n");
		file_put_contents($this->forge . '/notes.md', "# Notes\n");
		self::git($this->forge, 'add', '.');
		self::git($this->forge, '-c', 'user.name=Forge', '-c', 'user.email=forge@example.invalid', 'commit', '-q', '-m', 'init');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => ($key === 'secret' ? 'test-secret' : $default));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => ($this->ints[$key] ?? $default));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);

		$this->provider = new ServerSideWorkspaceProvider(config: $config, appConfig: $appConfig, git: new GitRunner(), time: $time, baseDir: $this->base . '/workspaces');
		$this->provider->open(runKey: self::KEY, cloneUrl: 'file://' . $this->forge, repository: 'example-org/example-app', ref: 'development', depth: 5);
		$this->root = $this->provider->root(runKey: self::KEY);
		$this->transfer = new WorkspaceTransfer(workspaces: $this->provider, guard: new WorkspacePathGuard());
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	public function testEveryFileOutsideGitGoesIn(): void {
		$files = $this->transfer->pack(workspaceKey: self::KEY);

		self::assertSame(
			[
				['name' => 'data.csv', 'content' => "a,b\n1,2\n", 'encoding' => 'utf-8'],
				['name' => 'notes.md', 'content' => "# Notes\n", 'encoding' => 'utf-8'],
			],
			$files
		);
	}//end testEveryFileOutsideGitGoesIn()

	public function testANamedSubsetGoesInAndBinaryTravelsAsBase64(): void {
		file_put_contents($this->root . '/chart.png', "\x89PNG\r\n\x1a\n\x00\xff");

		$files = $this->transfer->pack(workspaceKey: self::KEY, paths: ['chart.png']);

		self::assertSame([['name' => 'chart.png', 'content' => base64_encode("\x89PNG\r\n\x1a\n\x00\xff"), 'encoding' => 'base64']], $files);
	}//end testANamedSubsetGoesInAndBinaryTravelsAsBase64()

	public function testAWorkspaceOverTheInputCapIsRefusedWithTheLimitNamed(): void {
		$handle = fopen($this->root . '/big.bin', 'w');
		ftruncate($handle, WorkspaceTransfer::MAX_INPUT_BYTES);
		fclose($handle);

		try {
			$this->transfer->pack(workspaceKey: self::KEY);
			self::fail('A workspace over the cap was packed.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_QUOTA, $e->getErrorCode());
			self::assertSame('The workspace is too large to run code on (limit 10 MB). Remove files or run on fewer files.', $e->getMessage());
		}

		self::assertCount(1, $this->transfer->pack(workspaceKey: self::KEY, paths: ['notes.md']), 'Naming fewer files stays under the cap.');
	}//end testAWorkspaceOverTheInputCapIsRefusedWithTheLimitNamed()

	public function testANamedPathOutsideTheWorkspaceIsRefused(): void {
		$this->expectException(WorkspaceException::class);
		$this->transfer->pack(workspaceKey: self::KEY, paths: ['../../forge/example-app/data.csv']);
	}//end testANamedPathOutsideTheWorkspaceIsRefused()

	public function testOnlyTheFileTheRunChangedIsWrittenBack(): void {
		$sent = $this->transfer->pack(workspaceKey: self::KEY);
		touch($this->root . '/notes.md', 12345);

		// The stubbed run returns every file, one of them changed.
		$returned = [
			['name' => 'data.csv', 'content' => "a,b\n1,2\n3,4\n"],
			['name' => 'notes.md', 'content' => "# Notes\n"],
		];
		$result = $this->transfer->writeBack(workspaceKey: self::KEY, sent: $sent, returned: $returned);

		self::assertSame([['path' => 'data.csv', 'action' => 'modified', 'bytes' => 12]], $result['written']);
		self::assertSame([], $result['refused']);
		self::assertSame("a,b\n1,2\n3,4\n", file_get_contents($this->root . '/data.csv'));
		self::assertSame(12345, filemtime($this->root . '/notes.md'), 'An unchanged file is not rewritten.');
	}//end testOnlyTheFileTheRunChangedIsWrittenBack()

	public function testANewFileIsCreatedInANewFolder(): void {
		$result = $this->transfer->writeBack(
			workspaceKey: self::KEY,
			sent: [],
			returned: [['name' => 'out/chart.png', 'content' => base64_encode("\x00\x01\x02"), 'encoding' => 'base64']]
		);

		self::assertSame([['path' => 'out/chart.png', 'action' => 'created', 'bytes' => 3]], $result['written']);
		self::assertSame("\x00\x01\x02", file_get_contents($this->root . '/out/chart.png'));
	}//end testANewFileIsCreatedInANewFolder()

	public function testAReturnedPathThatLeavesTheWorkspaceIsRefusedAndTheRestLands(): void {
		$result = $this->transfer->writeBack(
			workspaceKey: self::KEY,
			sent: [],
			returned: [
				['name' => '../escaped.txt', 'content' => 'x'],
				['name' => '/etc/hermiq-escape', 'content' => 'x'],
				['name' => '.git/hooks/post-checkout', 'content' => 'x'],
				['name' => 'result.txt', 'content' => '42'],
			]
		);

		self::assertSame([['path' => 'result.txt', 'action' => 'created', 'bytes' => 2]], $result['written']);
		self::assertSame(['../escaped.txt', '/etc/hermiq-escape', '.git/hooks/post-checkout'], array_column($result['refused'], 'path'));
		self::assertFileDoesNotExist(dirname($this->root) . '/escaped.txt');
		self::assertFileDoesNotExist($this->root . '/.git/hooks/post-checkout');
	}//end testAReturnedPathThatLeavesTheWorkspaceIsRefusedAndTheRestLands()

	public function testASymlinkOutOfTheWorkspaceIsNotFollowed(): void {
		mkdir($this->base . '/elsewhere');
		symlink($this->base . '/elsewhere', $this->root . '/link');

		$result = $this->transfer->writeBack(workspaceKey: self::KEY, sent: [], returned: [['name' => 'link/planted.txt', 'content' => 'x']]);

		self::assertSame([], $result['written']);
		self::assertSame(['link/planted.txt'], array_column($result['refused'], 'path'));
		self::assertFileDoesNotExist($this->base . '/elsewhere/planted.txt');
	}//end testASymlinkOutOfTheWorkspaceIsNotFollowed()

	public function testUnreadableReturnedFilesAreRefused(): void {
		$result = $this->transfer->writeBack(
			workspaceKey: self::KEY,
			sent: [],
			returned: [['name' => 'a.bin', 'content' => '***', 'encoding' => 'base64'], ['content' => 'no name'], 'not a file']
		);

		self::assertSame([], $result['written']);
		self::assertCount(3, $result['refused']);
	}//end testUnreadableReturnedFilesAreRefused()

	public function testChangesOverTheBudgetWriteNothing(): void {
		// Room for two more files, and the run returns three.
		$present = iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)));
		$this->ints['workspace_session_max_files'] = $present + 2;
		$this->transfer->writeBack(workspaceKey: self::KEY, sent: [], returned: [['name' => 'zero.txt', 'content' => '0']]);

		try {
			$this->transfer->writeBack(
				workspaceKey: self::KEY,
				sent: [],
				returned: [['name' => 'one.txt', 'content' => '1'], ['name' => 'two.txt', 'content' => '2'], ['name' => 'three.txt', 'content' => '3']]
			);
			self::fail('Changes over the budget were written.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::WORKSPACE_QUOTA, $e->getErrorCode());
		}

		self::assertFileDoesNotExist($this->root . '/one.txt');
	}//end testChangesOverTheBudgetWriteNothing()

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
