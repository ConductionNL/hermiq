<?php

/**
 * Characterisation of WorkspaceEditor's delete_file, apply_patch,
 * create_branch and checkout_branch: each refusal sentence and code, the
 * budget reservation for a patch, and what lands in the working tree. Real
 * git in a temp dir; the provider is a double that records reservations.
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

use OCA\Hermiq\Service\Workspace\GitRunner;
use OCA\Hermiq\Service\Workspace\WorkspaceEditor;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use OCA\Hermiq\Service\Workspace\WorkspaceProvider;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Delete, patch and branch operations of the workspace editor.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceEditor
 * @uses   \OCA\Hermiq\Service\Workspace\GitRunner
 * @uses   \OCA\Hermiq\Service\Workspace\GitCommand
 * @uses   \OCA\Hermiq\Service\Workspace\GitProcess
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspacePathGuard
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceException
 */
final class WorkspaceEditorBranchPatchTest extends TestCase {

	private const PATCH = "--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-hello\n+hello world\n";

	private string $root;

	/**
	 * Recorded reservations.
	 *
	 * @var list<array{0: string, 1: int, 2: int}>
	 */
	private array $reserved = [];

	private WorkspaceEditor $editor;

	/**
	 * A one-commit repository on main and an editor over it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/hermiq-wsb-' . bin2hex(random_bytes(4));
		mkdir($this->root, 0700, true);
		file_put_contents($this->root . '/README.md', "hello\n");
		self::git($this->root, 'init', '-q', '-b', 'main');
		self::git($this->root, 'add', '.');
		self::git($this->root, '-c', 'user.name=Seed', '-c', 'user.email=seed@example.invalid', 'commit', '-q', '-m', 'seed');

		$provider = $this->createMock(WorkspaceProvider::class);
		$provider->method('reserve')->willReturnCallback(
			function (string $runKey, int $addBytes, int $addFiles): void {
				$this->reserved[] = [$runKey, $addBytes, $addFiles];
			}
		);
		$this->editor = new WorkspaceEditor(
			provider: $provider,
			guard: new WorkspacePathGuard(),
			git: new GitRunner(),
			userManager: $this->createMock(IUserManager::class)
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
	 * Deleting an existing file removes it; a missing one is refused.
	 *
	 * @return void
	 */
	public function testDeleteRemovesAFileAndRefusesAMissingOne(): void {
		self::assertSame(
			['path' => 'README.md', 'action' => 'deleted', 'bytes' => 0],
			$this->editor->deleteFile(root: $this->root, arguments: ['path' => 'README.md'])
		);
		self::assertFileDoesNotExist($this->root . '/README.md');

		$this->assertRefused(
			fn () => $this->editor->deleteFile(root: $this->root, arguments: ['path' => 'README.md']),
			WorkspaceException::INVALID_ARGUMENT,
			'That file does not exist in the workspace.'
		);
	}//end testDeleteRemovesAFileAndRefusesAMissingOne()

	/**
	 * A clean patch is reserved by its size and file count, then applied.
	 *
	 * @return void
	 */
	public function testACleanPatchIsReservedAndApplied(): void {
		$result = $this->editor->applyPatch(runKey: 'r', root: $this->root, arguments: ['patch' => self::PATCH]);

		self::assertSame(['files' => ['README.md'], 'action' => 'patched'], $result);
		self::assertSame([['r', strlen(self::PATCH), 1]], $this->reserved);
		self::assertSame("hello world\n", file_get_contents($this->root . '/README.md'));
	}//end testACleanPatchIsReservedAndApplied()

	/**
	 * An empty patch is refused before anything is reserved.
	 *
	 * @return void
	 */
	public function testAnEmptyPatchIsRefused(): void {
		$this->assertRefused(
			fn () => $this->editor->applyPatch(runKey: 'r', root: $this->root, arguments: []),
			WorkspaceException::PATCH_REJECTED,
			'Send a unified diff of at most one megabyte.'
		);
		self::assertSame([], $this->reserved);
	}//end testAnEmptyPatchIsRefused()

	/**
	 * A patch that does not apply leaves the file untouched.
	 *
	 * @return void
	 */
	public function testAStalePatchLeavesTheTreeUntouched(): void {
		$stale = "--- a/README.md\n+++ b/README.md\n@@ -1 +1 @@\n-goodbye\n+hello world\n";

		$this->assertRefused(
			fn () => $this->editor->applyPatch(runKey: 'r', root: $this->root, arguments: ['patch' => $stale]),
			WorkspaceException::PATCH_REJECTED,
			'The patch does not apply to the workspace as it is. Read the files again and send a fresh diff.'
		);
		self::assertSame("hello\n", file_get_contents($this->root . '/README.md'));
	}//end testAStalePatchLeavesTheTreeUntouched()

	/**
	 * A new branch is created and checked out; creating it again is refused.
	 *
	 * @return void
	 */
	public function testCreateBranchSwitchesAndRefusesADuplicate(): void {
		self::assertSame(['branch' => 'feature-a'], $this->editor->createBranch(root: $this->root, branch: 'feature-a'));
		self::assertSame("feature-a\n", self::out($this->root, 'branch', '--show-current'));

		$this->assertRefused(
			fn () => $this->editor->createBranch(root: $this->root, branch: 'feature-a'),
			WorkspaceException::GIT_FAILED,
			'That branch could not be created. It may exist already.'
		);
	}//end testCreateBranchSwitchesAndRefusesADuplicate()

	/**
	 * An existing branch is checked out; an unknown one is refused without
	 * guessing a remote branch.
	 *
	 * @return void
	 */
	public function testCheckoutBranchSwitchesOrRefuses(): void {
		self::git($this->root, 'branch', 'other');

		self::assertSame(['branch' => 'other'], $this->editor->checkoutBranch(root: $this->root, branch: 'other'));
		self::assertSame("other\n", self::out($this->root, 'branch', '--show-current'));

		$this->assertRefused(
			fn () => $this->editor->checkoutBranch(root: $this->root, branch: 'missing'),
			WorkspaceException::GIT_FAILED,
			'That branch does not exist in the workspace, or uncommitted changes are in the way.'
		);
		self::assertSame("other\n", self::out($this->root, 'branch', '--show-current'));
	}//end testCheckoutBranchSwitchesOrRefuses()

	/**
	 * Assert a call throws a WorkspaceException with this code and message.
	 *
	 * @param callable $call    The call.
	 * @param string   $code    The expected error code.
	 * @param string   $message The expected message.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $code, string $message): void {
		try {
			$call();
		} catch (WorkspaceException $error) {
			self::assertSame([$code, $message], [$error->getErrorCode(), $error->getMessage()]);
			return;
		}

		self::fail('Expected a WorkspaceException ' . $code);
	}//end assertRefused()

	/**
	 * Run git and return stdout.
	 *
	 * @param string $dir  The repository.
	 * @param string ...$args The arguments.
	 *
	 * @return string
	 */
	private static function out(string $dir, string ...$args): string {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		return (string)shell_exec($command);
	}//end out()

	/**
	 * Run git quietly.
	 *
	 * @param string $dir  The repository.
	 * @param string ...$args The arguments.
	 *
	 * @return void
	 */
	private static function git(string $dir, string ...$args): void {
		self::out($dir, ...$args);
	}//end git()
}//end class
