<?php

/**
 * Hermiq GitHubArchiveExtractorTest
 *
 * Exercises the archive shortcut against REAL archive bytes on a REAL
 * filesystem. While this logic lived inside GitHubTemplateCatalogService the
 * only route to it was a mocked HTTP client plus a mocked temp manager, so every
 * failure branch — oversized, corrupt, unexpected layout — could only be
 * asserted about, never run. Each of them is run here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GitHubArchiveExtractor;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use PharData;
use Psr\Log\NullLogger;

/**
 * Tests for {@see GitHubArchiveExtractor}.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */
class GitHubArchiveExtractorTest extends TestCase {
	/**
	 * Every temp path this test allocated, removed in tearDown().
	 *
	 * @var array<int, string>
	 */
	private array $allocated = [];

	/**
	 * Remove everything the fake temp manager handed out.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (array_reverse($this->allocated) as $path) {
			if (is_dir($path) === true) {
				$this->rmrf($path);
				continue;
			}

			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		$this->allocated = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * Recursively remove a directory (test-side cleanup, independent of the
	 * class under test so a bug in its cleanup cannot hide behind ours).
	 *
	 * @param string $path The directory to remove.
	 *
	 * @return void
	 */
	private function rmrf(string $path): void {
		foreach ((scandir($path) ?: []) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $path . '/' . $entry;
			if (is_dir($full) === true) {
				$this->rmrf($full);
				continue;
			}

			unlink($full);
		}

		rmdir($path);
	}//end rmrf()

	/**
	 * A temp manager backed by real files under the system temp directory.
	 *
	 * @return ITempManager
	 */
	private function tempManager(): ITempManager {
		$manager = $this->createMock(ITempManager::class);

		$manager->method('getTemporaryFile')->willReturnCallback(
			function (string $suffix = ''): string {
				$path = sys_get_temp_dir() . '/hermiq-arch-' . bin2hex(random_bytes(8)) . $suffix;
				touch($path);
				$this->allocated[] = $path;
				return $path;
			}
		);

		$manager->method('getTemporaryFolder')->willReturnCallback(
			function (): string {
				$path = sys_get_temp_dir() . '/hermiq-arch-dir-' . bin2hex(random_bytes(8));
				mkdir($path, 0700, true);
				$this->allocated[] = $path;
				return $path;
			}
		);

		return $manager;
	}//end tempManager()

	/**
	 * Build a real `.tar.gz` whose entries sit under a single GitHub-style root.
	 *
	 * @param array<string, string> $files The `relative path => contents` entries.
	 * @param string $root The archive's single top-level directory name.
	 *
	 * @return string The raw gzipped tar bytes.
	 */
	private function archiveBytes(array $files, string $root = 'acme-demo-abc1234'): string {
		$stage = sys_get_temp_dir() . '/hermiq-arch-src-' . bin2hex(random_bytes(8));
		$this->allocated[] = $stage;
		mkdir($stage . '/' . $root, 0700, true);

		foreach ($files as $path => $contents) {
			$full = $stage . '/' . $root . '/' . $path;
			$dir = dirname($full);
			if (is_dir($dir) === false) {
				mkdir($dir, 0700, true);
			}

			file_put_contents($full, $contents);
		}

		$tarPath = sys_get_temp_dir() . '/hermiq-arch-mk-' . bin2hex(random_bytes(8)) . '.tar';
		$this->allocated[] = $tarPath;
		$this->allocated[] = $tarPath . '.gz';

		$phar = new PharData($tarPath);
		$phar->buildFromDirectory($stage);
		$phar->compress(\Phar::GZ);
		unset($phar);

		return (string)file_get_contents($tarPath . '.gz');
	}//end archiveBytes()

	/**
	 * The class under test, wired to real temp storage.
	 *
	 * @return GitHubArchiveExtractor
	 */
	private function extractor(): GitHubArchiveExtractor {
		return new GitHubArchiveExtractor($this->tempManager(), new NullLogger());
	}//end extractor()

	/**
	 * A well-formed archive yields exactly the accepted entries, keyed by their
	 * path with the archive's own root prefix stripped.
	 *
	 * @return void
	 */
	public function testExtractReturnsAcceptedEntriesWithRootStripped(): void {
		$bytes = $this->archiveBytes([
			'hermiq-skills.json' => '{"formatVersion":"1.1"}',
			'skills/alpha/SKILL.md' => 'alpha body',
			'skills/alpha/aux/notes.md' => 'aux notes',
			'agents/triage.json' => '{"name":"triage"}',
			'README.md' => 'not wanted',
			'src/index.js' => 'not wanted either',
		]);

		$files = $this->extractor()->extract(
			$bytes,
			static fn (string $path): bool => ($path === 'hermiq-skills.json'
				|| str_starts_with($path, 'skills/')
				|| str_starts_with($path, 'agents/')),
			16777216
		);

		$this->assertIsArray($files);
		$this->assertSame(
			['agents/triage.json', 'hermiq-skills.json', 'skills/alpha/SKILL.md', 'skills/alpha/aux/notes.md'],
			$this->sortedKeys($files)
		);
		$this->assertSame('alpha body', $files['skills/alpha/SKILL.md']);
		$this->assertSame('{"name":"triage"}', $files['agents/triage.json']);

		// The predicate is a filter, not a suggestion: rejected entries are absent
		// rather than present-and-empty.
		$this->assertArrayNotHasKey('README.md', $files);
		$this->assertArrayNotHasKey('src/index.js', $files);
	}//end testExtractReturnsAcceptedEntriesWithRootStripped()

	/**
	 * A predicate that accepts nothing yields an EMPTY MAP, not null — "the
	 * archive contained nothing I wanted" and "the archive was unusable" are
	 * different answers and the caller falls back on only one of them.
	 *
	 * @return void
	 */
	public function testExtractAcceptingNothingReturnsEmptyArrayNotNull(): void {
		$bytes = $this->archiveBytes(['README.md' => 'hello']);

		$files = $this->extractor()->extract($bytes, static fn (string $path): bool => false, 16777216);

		$this->assertSame([], $files);
		$this->assertNotNull($files);
	}//end testExtractAcceptingNothingReturnsEmptyArrayNotNull()

	/**
	 * An archive larger than the bound is refused BEFORE it is written to disk.
	 *
	 * @return void
	 */
	public function testExtractRefusesArchiveOverTheByteBound(): void {
		$bytes = $this->archiveBytes(['hermiq-skills.json' => '{}']);

		$files = $this->extractor()->extract($bytes, static fn (string $path): bool => true, 8);

		$this->assertNull($files);
	}//end testExtractRefusesArchiveOverTheByteBound()

	/**
	 * Empty bytes are refused rather than unpacked into an empty result — a
	 * fetch that returned nothing is a failed fetch, not an empty repository.
	 *
	 * @return void
	 */
	public function testExtractRefusesEmptyBody(): void {
		$this->assertNull($this->extractor()->extract('', static fn (string $path): bool => true, 16777216));
	}//end testExtractRefusesEmptyBody()

	/**
	 * Bytes that are not an archive at all return null rather than throwing —
	 * the caller's contract is a clean fallback, not an exception.
	 *
	 * @return void
	 */
	public function testExtractReturnsNullOnCorruptArchive(): void {
		$files = $this->extractor()->extract(
			'this is definitely not a gzipped tarball',
			static fn (string $path): bool => true,
			16777216
		);

		$this->assertNull($files);
	}//end testExtractReturnsNullOnCorruptArchive()

	/**
	 * An archive with more than one top-level directory is refused. Guessing
	 * which root to read would silently return the wrong file set, which reads
	 * to the caller as a complete bundle.
	 *
	 * @return void
	 */
	public function testExtractRefusesArchiveWithoutASingleRoot(): void {
		$stage = sys_get_temp_dir() . '/hermiq-arch-multi-' . bin2hex(random_bytes(8));
		$this->allocated[] = $stage;
		mkdir($stage . '/first-root', 0700, true);
		mkdir($stage . '/second-root', 0700, true);
		file_put_contents($stage . '/first-root/a.txt', 'a');
		file_put_contents($stage . '/second-root/b.txt', 'b');

		$tarPath = sys_get_temp_dir() . '/hermiq-arch-multi-' . bin2hex(random_bytes(8)) . '.tar';
		$this->allocated[] = $tarPath;
		$this->allocated[] = $tarPath . '.gz';
		$phar = new PharData($tarPath);
		$phar->buildFromDirectory($stage);
		$phar->compress(\Phar::GZ);
		unset($phar);

		$files = $this->extractor()->extract(
			(string)file_get_contents($tarPath . '.gz'),
			static fn (string $path): bool => true,
			16777216
		);

		$this->assertNull($files);
	}//end testExtractRefusesArchiveWithoutASingleRoot()

	/**
	 * A successful extraction leaves nothing behind: neither the staged archive
	 * nor the unpacked tree survives the call. Asserted by counting what the
	 * temp manager handed out and checking each path is gone, so a leak shows up
	 * as a live path rather than as slow disk exhaustion in production.
	 *
	 * @return void
	 */
	public function testExtractCleansUpEverythingItStaged(): void {
		$bytes = $this->archiveBytes(['hermiq-skills.json' => '{}']);
		$before = count($this->allocated);

		$extractor = $this->extractor();
		$files = $extractor->extract($bytes, static fn (string $path): bool => true, 16777216);

		$this->assertIsArray($files);

		$staged = array_slice($this->allocated, $before);
		$this->assertNotSame([], $staged, 'the extractor must have allocated temp space to unpack into');

		foreach ($staged as $path) {
			$this->assertFileDoesNotExist($path, 'extract() left ' . $path . ' behind');
		}
	}//end testExtractCleansUpEverythingItStaged()

	/**
	 * Cleanup also runs when extraction FAILS — the failure path is exactly where
	 * a leak would otherwise accumulate unnoticed, since the caller falls back and
	 * carries on.
	 *
	 * @return void
	 */
	public function testExtractCleansUpAfterAFailedExtraction(): void {
		$extractor = $this->extractor();
		$before = count($this->allocated);

		$this->assertNull($extractor->extract('not an archive', static fn (string $path): bool => true, 16777216));

		$staged = array_slice($this->allocated, $before);
		foreach ($staged as $path) {
			$this->assertFileDoesNotExist($path, 'a failed extract() left ' . $path . ' behind');
		}
	}//end testExtractCleansUpAfterAFailedExtraction()

	/**
	 * An unwritable temp file allocation aborts cleanly rather than throwing.
	 *
	 * @return void
	 */
	public function testExtractReturnsNullWhenNoTempFileIsAvailable(): void {
		$manager = $this->createMock(ITempManager::class);
		$manager->method('getTemporaryFile')->willReturn(false);

		$extractor = new GitHubArchiveExtractor($manager, new NullLogger());

		$this->assertNull($extractor->extract('anything', static fn (string $path): bool => true, 16777216));
	}//end testExtractReturnsNullWhenNoTempFileIsAvailable()

	/**
	 * An unavailable temp FOLDER aborts cleanly too, and the archive file that
	 * was already staged is still removed.
	 *
	 * @return void
	 */
	public function testExtractReturnsNullWhenNoTempFolderIsAvailable(): void {
		$archivePath = sys_get_temp_dir() . '/hermiq-arch-nofolder-' . bin2hex(random_bytes(8)) . '.tar.gz';
		$this->allocated[] = $archivePath;

		$manager = $this->createMock(ITempManager::class);
		$manager->method('getTemporaryFile')->willReturn($archivePath);
		$manager->method('getTemporaryFolder')->willReturn(false);

		$extractor = new GitHubArchiveExtractor($manager, new NullLogger());

		$this->assertNull($extractor->extract('anything', static fn (string $path): bool => true, 16777216));
		$this->assertFileDoesNotExist($archivePath);
	}//end testExtractReturnsNullWhenNoTempFolderIsAvailable()

	/**
	 * The sorted key list of a map, so an assertion states the expected SET
	 * without depending on filesystem iteration order.
	 *
	 * @param array<string, string> $files The map.
	 *
	 * @return array<int, string> The sorted keys.
	 */
	private function sortedKeys(array $files): array {
		$keys = array_keys($files);
		sort($keys);
		return $keys;
	}//end sortedKeys()
}//end class
