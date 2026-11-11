<?php

/**
 * Characterisation of WorkspaceEditor's write_file and commit: every refusal
 * sentence, the budget reservation, the created/modified split, staging, the
 * owner identity and the read-back. Real git in a temp dir; the provider is a
 * double that records reservations.
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
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class WorkspaceEditorTest extends TestCase {

	private string $root;

	/**
	 * Reservations made: [runKey, addBytes, addFiles].
	 *
	 * @var array<int, array{0: string, 1: int, 2: int}>
	 */
	private array $reserved = [];

	private WorkspaceEditor $editor;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/hermiq-wse-' . bin2hex(random_bytes(4));
		mkdir($this->root, 0700, true);
		file_put_contents($this->root . '/README.md', "hello\n");
		mkdir($this->root . '/lib');
		self::git($this->root, 'init', '-q', '-b', 'main');
		self::git($this->root, 'add', '.');
		self::git($this->root, '-c', 'user.name=Seed', '-c', 'user.email=seed@example.invalid', 'commit', '-q', '-m', 'seed');

		$provider = $this->createMock(WorkspaceProvider::class);
		$provider->method('reserve')->willReturnCallback(
			function (string $runKey, int $addBytes, int $addFiles): void {
				$this->reserved[] = [$runKey, $addBytes, $addFiles];
			}
		);
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice Owner');
		$alice->method('getEMailAddress')->willReturn('');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'alice' ? $alice : null));
		$this->editor = new WorkspaceEditor(provider: $provider, guard: new WorkspacePathGuard(), git: new GitRunner(), userManager: $users);
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
	}//end tearDown()

	public function testWriteRefusesContentThatIsNotTextOrTooLarge(): void {
		foreach ([['path' => 'a.txt'], ['path' => 'a.txt', 'content' => 5], ['path' => 'a.txt', 'content' => str_repeat('x', 1048577)]] as $arguments) {
			$this->assertRefused(
				fn () => $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: $arguments),
				WorkspaceException::INVALID_ARGUMENT,
				'Send the file content as text of at most one megabyte.'
			);
		}

		self::assertSame([], $this->reserved);
	}//end testWriteRefusesContentThatIsNotTextOrTooLarge()

	public function testWriteAcceptsExactlyOneMegabyte(): void {
		$result = $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: ['path' => 'big.txt', 'content' => str_repeat('x', 1048576)]);

		self::assertSame(1048576, $result['bytes']);
	}//end testWriteAcceptsExactlyOneMegabyte()

	public function testWriteRefusesAFolderAndAnExistingFileInCreateMode(): void {
		$this->assertRefused(
			fn () => $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: ['path' => 'lib', 'content' => 'x']),
			WorkspaceException::INVALID_ARGUMENT,
			'That path is a folder, not a file.'
		);
		$this->assertRefused(
			fn () => $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: ['path' => 'README.md', 'content' => 'x', 'mode' => 'create']),
			WorkspaceException::INVALID_ARGUMENT,
			'That file already exists.'
		);

		self::assertSame([], $this->reserved);
		self::assertSame("hello\n", file_get_contents($this->root . '/README.md'));
	}//end testWriteRefusesAFolderAndAnExistingFileInCreateMode()

	public function testWriteCreatesNestedFilesAndReservesTheirGrowth(): void {
		$result = $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: ['path' => 'deep/er/new.txt', 'content' => 'abc', 'mode' => 'create']);

		self::assertSame(['path' => 'deep/er/new.txt', 'action' => 'created', 'bytes' => 3], $result);
		self::assertSame([['r', 3, 1]], $this->reserved);
		self::assertSame('abc', file_get_contents($this->root . '/deep/er/new.txt'));
	}//end testWriteCreatesNestedFilesAndReservesTheirGrowth()

	public function testWriteOverwritesAndReservesOnlyTheDifference(): void {
		$result = $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: ['path' => 'README.md', 'content' => 'hi']);

		self::assertSame(['path' => 'README.md', 'action' => 'modified', 'bytes' => 2], $result);
		self::assertSame([['r', -4, 0]], $this->reserved);
		self::assertSame('hi', file_get_contents($this->root . '/README.md'));
	}//end testWriteOverwritesAndReservesOnlyTheDifference()

	public function testWriteGuardsThePathBeforeTouchingIt(): void {
		$this->assertRefused(
			fn () => $this->editor->writeFile(runKey: 'r', root: $this->root, arguments: ['path' => '.git/config', 'content' => 'x']),
			WorkspaceException::PATH_FORBIDDEN,
			null
		);

		self::assertSame([], $this->reserved);
	}//end testWriteGuardsThePathBeforeTouchingIt()

	public function testCommitRefusesAnEmptyOrOverlongMessage(): void {
		foreach ([[], ['message' => '   '], ['message' => str_repeat('é', 5001)]] as $arguments) {
			$this->assertRefused(
				fn () => $this->editor->commit(root: $this->root, ownerUid: 'alice', arguments: $arguments),
				WorkspaceException::INVALID_ARGUMENT,
				'Write a commit message of at most 5000 characters.'
			);
		}
	}//end testCommitRefusesAnEmptyOrOverlongMessage()

	public function testCommitChecksTheOwnerBeforeTheMessage(): void {
		$this->assertRefused(
			fn () => $this->editor->commit(root: $this->root, ownerUid: '  ', arguments: []),
			WorkspaceException::OWNER_UNRESOLVABLE,
			'This run has no owner to author a commit. It can still read the workspace.'
		);
	}//end testCommitChecksTheOwnerBeforeTheMessage()

	public function testCommitWithNothingStagedIsRefused(): void {
		$this->assertRefused(
			fn () => $this->editor->commit(root: $this->root, ownerUid: 'alice', arguments: ['message' => 'x']),
			WorkspaceException::INVALID_ARGUMENT,
			'There is nothing to commit.'
		);
	}//end testCommitWithNothingStagedIsRefused()

	public function testCommitStagesEverythingByDefaultAsTheOwner(): void {
		file_put_contents($this->root . '/README.md', "bye\n");
		file_put_contents($this->root . '/lib/A.php', "<?php\n");

		$result = $this->editor->commit(root: $this->root, ownerUid: 'alice', arguments: ['message' => "  feat: two\n"]);

		self::assertSame(['branch' => 'main', 'author' => 'alice', 'filesChanged' => 2], array_diff_key($result, ['sha' => true]));
		self::assertSame(trim(self::out($this->root, 'rev-parse', 'HEAD')), $result['sha']);
		self::assertSame("Alice Owner\x1falice@users.noreply.invalid\x1fAlice Owner\x1falice@users.noreply.invalid\x1ffeat: two", trim(self::out($this->root, 'log', '-1', '--format=%an%x1f%ae%x1f%cn%x1f%ce%x1f%B')));
	}//end testCommitStagesEverythingByDefaultAsTheOwner()

	public function testCommitStagesOnlyTheNamedPaths(): void {
		file_put_contents($this->root . '/README.md', "bye\n");
		file_put_contents($this->root . '/lib/A.php', "<?php\n");

		$result = $this->editor->commit(root: $this->root, ownerUid: 'alice', arguments: ['message' => 'x', 'paths' => ['lib/A.php']]);

		self::assertSame(1, $result['filesChanged']);
		self::assertSame(" M README.md\n", self::out($this->root, 'status', '--porcelain'));
	}//end testCommitStagesOnlyTheNamedPaths()

	public function testCommitRefusesAGuardedPathBeforeStaging(): void {
		file_put_contents($this->root . '/README.md', "bye\n");

		$this->assertRefused(
			fn () => $this->editor->commit(root: $this->root, ownerUid: 'alice', arguments: ['message' => 'x', 'paths' => ['README.md', '../x']]),
			WorkspaceException::PATH_OUTSIDE_WORKSPACE,
			null
		);

		self::assertSame(" M README.md\n", self::out($this->root, 'status', '--porcelain'));
	}//end testCommitRefusesAGuardedPathBeforeStaging()

	/**
	 * Assert a call throws a WorkspaceException with a code and, if given, a message.
	 *
	 * @param callable    $call    The call.
	 * @param string      $code    The expected error code.
	 * @param string|null $message The expected message, or null to skip.
	 *
	 * @return void
	 */
	private function assertRefused(callable $call, string $code, ?string $message): void {
		try {
			$call();
		} catch (WorkspaceException $error) {
			self::assertSame($code, $error->getErrorCode());
			if ($message !== null) {
				self::assertSame($message, $error->getMessage());
			}

			return;
		}

		self::fail('Expected a WorkspaceException ' . $code);
	}//end assertRefused()

	private static function out(string $dir, string ...$args): string {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		return (string)shell_exec($command);
	}//end out()

	private static function git(string $dir, string ...$args): void {
		$command = 'git -C ' . escapeshellarg($dir);
		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		exec($command . ' 2>&1', $out, $code);
		self::assertSame(0, $code, implode("\n", $out));
	}//end git()
}//end class
