<?php

/**
 * WorkspacePathGuard (hermiq-runner-git-capability, task 2).
 *
 * Runs against a real temporary directory with real symbolic links: the
 * containment rule is about what the filesystem resolves, so a fake filesystem
 * would test the wrong thing.
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspacePathGuard;
use PHPUnit\Framework\TestCase;

/**
 * Confinement and the metadata deny-write rule.
 */
final class WorkspacePathGuardTest extends TestCase {

	private string $base;

	private string $root;

	private string $outside;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/hermiq-guard-' . bin2hex(random_bytes(4));
		$this->root = $this->base . '/repo';
		$this->outside = $this->base . '/outside';
		mkdir($this->root . '/.git/hooks', 0700, true);
		mkdir($this->root . '/lib', 0700, true);
		mkdir($this->outside, 0700, true);
		file_put_contents($this->root . '/lib/A.php', '<?php');
		file_put_contents($this->root . '/.git/config', '[core]');
		file_put_contents($this->outside . '/secret.txt', 'secret');
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}//end tearDown()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function lexicalEscapes(): array {
		return [
			'parent segment' => ['../outside/secret.txt'],
			'nested parent segment' => ['lib/../../outside/secret.txt'],
			'absolute' => ['/etc/passwd'],
			'drive letter' => ['C:/Windows'],
			'home' => ['~/x'],
			'empty' => [''],
			'nul byte' => ["lib/A.php\0.txt"],
			'backslash traversal' => ['lib\\..\\..\\outside'],
		];
	}//end lexicalEscapes()

	/**
	 * @dataProvider lexicalEscapes
	 */
	public function testATraversalPathIsRefusedBeforeAnyFilesystemOperation(string $path): void {
		try {
			(new WorkspacePathGuard())->forWrite(root: $this->root, relativePath: $path);
			self::fail('The path was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::PATH_OUTSIDE_WORKSPACE, $e->getErrorCode());
			self::assertStringNotContainsString($this->base, $e->getMessage());
		}
	}//end testATraversalPathIsRefusedBeforeAnyFilesystemOperation()

	public function testASymlinkEscapeIsRefusedAfterResolutionNotOnItsLexicalForm(): void {
		symlink($this->outside, $this->root . '/lib/escape');
		$guard = new WorkspacePathGuard();

		foreach (['lib/escape/secret.txt', 'lib/escape/new.txt', 'lib/escape'] as $path) {
			try {
				$guard->forRead(root: $this->root, relativePath: $path);
				self::fail('Read through the link was accepted: ' . $path);
			} catch (WorkspaceException $e) {
				self::assertSame(WorkspaceException::PATH_OUTSIDE_WORKSPACE, $e->getErrorCode());
			}
		}
	}//end testASymlinkEscapeIsRefusedAfterResolutionNotOnItsLexicalForm()

	public function testALinkInsideTheWorkspaceStillResolves(): void {
		symlink($this->root . '/lib', $this->root . '/src');
		$resolved = (new WorkspacePathGuard())->forRead(root: $this->root, relativePath: 'src/A.php');

		self::assertSame(realpath($this->root . '/lib/A.php'), $resolved);
	}//end testALinkInsideTheWorkspaceStillResolves()

	public function testAHookWriteIsRefusedAsForbidden(): void {
		$this->assertForbidden(path: '.git/hooks/pre-commit');
		self::assertFileDoesNotExist($this->root . '/.git/hooks/pre-commit');
	}//end testAHookWriteIsRefusedAsForbidden()

	public function testAConfigurationWriteIsRefusedThoughItLiesInsideTheRoot(): void {
		$this->assertForbidden(path: '.git/config');
		$this->assertForbidden(path: './.git/config');
		$this->assertForbidden(path: '.GIT/config');
	}//end testAConfigurationWriteIsRefusedThoughItLiesInsideTheRoot()

	public function testTheRefusalIsABlanketRuleNotAnAllowlist(): void {
		// `description` executes nothing in any git version; it is refused anyway.
		$this->assertForbidden(path: '.git/description');
		$this->assertForbidden(path: '.git');
	}//end testTheRefusalIsABlanketRuleNotAnAllowlist()

	public function testALinkThatLeadsIntoTheMetadataDirectoryIsRefusedForWrites(): void {
		symlink($this->root . '/.git', $this->root . '/meta');
		$this->assertForbidden(path: 'meta/hooks/pre-commit');
	}//end testALinkThatLeadsIntoTheMetadataDirectoryIsRefusedForWrites()

	public function testAPatchTouchingARefusedPathIsRejectedWhole(): void {
		$patch = "--- a/lib/A.php\n+++ b/lib/A.php\n@@ -1 +1 @@\n-<?php\n+<?php // changed\n"
			. "--- /dev/null\n+++ b/.git/hooks/pre-commit\n@@ -0,0 +1 @@\n+#!/bin/sh\n";

		try {
			(new WorkspacePathGuard())->patchTargets(root: $this->root, patch: $patch);
			self::fail('The patch was accepted.');
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::PATH_FORBIDDEN, $e->getErrorCode());
		}

		self::assertSame('<?php', file_get_contents($this->root . '/lib/A.php'));
	}//end testAPatchTouchingARefusedPathIsRejectedWhole()

	public function testAPatchThatRenamesOrLinksIsRejected(): void {
		$guard = new WorkspacePathGuard();
		foreach (["diff --git a/x b/y\nrename from x\nrename to y\n", "diff --git a/l b/l\nnew file mode 120000\n--- /dev/null\n+++ b/l\n"] as $patch) {
			try {
				$guard->patchTargets(root: $this->root, patch: $patch);
				self::fail('The patch was accepted.');
			} catch (WorkspaceException $e) {
				self::assertSame(WorkspaceException::PATCH_REJECTED, $e->getErrorCode());
			}
		}
	}//end testAPatchThatRenamesOrLinksIsRejected()

	public function testAPlainPatchListsItsTargets(): void {
		$patch = "--- a/lib/A.php\n+++ b/lib/A.php\n@@ -1 +1 @@\n-<?php\n+<?php // changed\n--- /dev/null\n+++ b/lib/B.php\n@@ -0,0 +1 @@\n+<?php\n";

		self::assertSame(['lib/A.php', 'lib/B.php'], (new WorkspacePathGuard())->patchTargets(root: $this->root, patch: $patch));
	}//end testAPlainPatchListsItsTargets()

	/**
	 * Assert a write to the path is refused as forbidden.
	 */
	private function assertForbidden(string $path): void {
		try {
			(new WorkspacePathGuard())->forWrite(root: $this->root, relativePath: $path);
			self::fail('The write was accepted: ' . $path);
		} catch (WorkspaceException $e) {
			self::assertSame(WorkspaceException::PATH_FORBIDDEN, $e->getErrorCode(), $path);
		}
	}//end assertForbidden()
}//end class
