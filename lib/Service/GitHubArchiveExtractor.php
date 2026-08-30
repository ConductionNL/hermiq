<?php

/**
 * Hermiq GitHubArchiveExtractor
 *
 * Extracts the wanted entries out of a GitHub repository tarball.
 *
 * Split out of GitHubTemplateCatalogService deliberately. That class owns the
 * SSRF-safe fixed-host read path — every outbound call lives behind it, which is
 * the whole point of it being one class. Unpacking an archive that has ALREADY
 * been fetched makes no outbound call at all, so it is not covered by that
 * invariant and does not belong inside it: keeping it there only added a
 * temp-file dependency, four filesystem/Phar collaborators and a method with an
 * NPath of 3540 to a class whose complexity is already suppressed.
 *
 * Taking it out also makes the unpacking directly testable. In the catalogue
 * service the only way to reach this code was through a mocked HTTP client and a
 * mocked temp manager; here a test hands it real archive bytes and reads the
 * result, so the failure branches (oversized, corrupt, no single root) are
 * exercised as themselves rather than inferred.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use FilesystemIterator;
use OCP\ITempManager;
use PharData;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Unpacks a GitHub repository tarball into a `path => contents` map.
 *
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
 */
class GitHubArchiveExtractor {
	/**
	 * Constructor.
	 *
	 * @param ITempManager $tempManager Temp file/folder allocation.
	 * @param LoggerInterface $logger PSR logger for extraction diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ITempManager $tempManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Extract the accepted entries from a `.tar.gz` repository archive.
	 *
	 * Best-effort by contract: returns null on ANY failure (oversized, unwritable
	 * temp space, corrupt archive, unexpected layout) so the caller can fall back
	 * to a per-file fetch rather than fail a whole install over an optimisation.
	 *
	 * Never partial. An extraction that fails partway is discarded whole, so the
	 * caller's fallback is a clean do-over rather than a merge of two partial
	 * sets — a half-populated map would read to the caller as a complete bundle
	 * that happens to be missing files, which is exactly the "clean but wrong"
	 * outcome this whole path exists to avoid.
	 *
	 * @param string $body The raw archive bytes as fetched.
	 * @param callable $accept Predicate deciding whether an entry's path (with the archive's
	 *                         own `{owner}-{repo}-{sha}/` root stripped) is wanted.
	 * @param int $maxBytes Refuse an archive larger than this, before writing it to disk.
	 *
	 * @return array<string,string>|null The `path => contents` map, or null when unavailable.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
	 */
	public function extract(string $body, callable $accept, int $maxBytes): ?array {
		if ($body === '' || strlen($body) > $maxBytes) {
			// An archive this large would exceed the bundle bound on extraction
			// anyway — refuse before writing it to disk rather than after.
			return null;
		}

		$archivePath = $this->tempManager->getTemporaryFile('.tar.gz');
		if (is_string($archivePath) === false) {
			return null;
		}

		// BOTH temp paths are allocated HERE, before the try, so `finally` always
		// has the real value of each. Allocating the unpack directory inside
		// unpack() instead meant a throw from PharData — a corrupt archive, the
		// single likeliest failure on this path — returned before the directory
		// was handed back, leaving the local still null and the directory on disk
		// forever. A leak on the failure path is the one that accumulates, since
		// the caller falls back and carries on as though nothing happened.
		$extractDir = $this->tempManager->getTemporaryFolder();
		if (is_string($extractDir) === false) {
			$this->cleanup(archivePath: $archivePath, extractDir: null);
			return null;
		}

		try {
			if ($this->unpack(archivePath: $archivePath, extractDir: $extractDir, body: $body) === false) {
				return null;
			}

			$root = $this->resolveRoot(extractDir: $extractDir);
			if ($root === null) {
				return null;
			}

			return $this->collect(root: $root, accept: $accept);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq GitHub archive: fetch/extract failed, falling back to per-file fetch: ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		} finally {
			$this->cleanup(archivePath: $archivePath, extractDir: $extractDir);
		}//end try

	}//end extract()

	/**
	 * Discard everything this extraction staged on disk.
	 *
	 * Runs from `finally`, so it runs on the success path, on every early
	 * return and on a thrown exception alike — there is no exit from
	 * {@see extract()} that leaves an archive or an unpacked tree behind.
	 *
	 * @param string $archivePath The staged archive, which may never have been written.
	 * @param string|null $extractDir The unpack directory, which may never have been allocated.
	 *
	 * @return void
	 */
	private function cleanup(string $archivePath, ?string $extractDir): void {
		if (file_exists($archivePath) === true) {
			unlink($archivePath);
		}

		if ($extractDir !== null && is_dir($extractDir) === true) {
			$this->removeDirectoryRecursive(path: $extractDir);
		}

	}//end cleanup()

	/**
	 * Write the archive to disk and unpack it into the caller's temporary folder.
	 *
	 * Does NOT allocate the destination — {@see extract()} owns both temp paths so
	 * that its `finally` can always discard them, including when this method
	 * throws partway through unpacking.
	 *
	 * @param string $archivePath Where to stage the archive bytes.
	 * @param string $extractDir The already-allocated directory to unpack into.
	 * @param string $body The raw archive bytes.
	 *
	 * @return bool True when the archive was unpacked.
	 */
	private function unpack(string $archivePath, string $extractDir, string $body): bool {
		if (file_put_contents($archivePath, $body) === false) {
			return false;
		}

		$phar = new PharData($archivePath);
		$phar->extractTo($extractDir, null, true);

		return true;
	}//end unpack()

	/**
	 * Resolve the archive's single top-level directory.
	 *
	 * GitHub's tarball root is `{owner}-{repo}-{shortsha}/…` — exactly one
	 * top-level directory. Resolved by listing rather than assumed by name, since
	 * the short SHA is not knowable in advance. Anything other than exactly one
	 * directory is an archive shape this code does not understand, and guessing
	 * which root to read would silently return the wrong file set.
	 *
	 * @param string $extractDir The directory the archive was unpacked into.
	 *
	 * @return string|null The absolute root path, or null when the layout is unexpected.
	 */
	private function resolveRoot(string $extractDir): ?string {
		$entries = scandir($extractDir);
		if (is_array($entries) === false) {
			return null;
		}

		$roots = array_values(array_filter(
			$entries,
			static fn (string $entry): bool => ($entry !== '.' && $entry !== '..' && is_dir($extractDir . '/' . $entry))
		));

		if (count($roots) !== 1) {
			return null;
		}

		return $extractDir . '/' . $roots[0];
	}//end resolveRoot()

	/**
	 * Walk the unpacked tree and collect every accepted entry's contents.
	 *
	 * @param string $root The archive's single top-level directory.
	 * @param callable $accept Predicate over the root-relative path.
	 *
	 * @return array<string,string> The `path => contents` map.
	 */
	private function collect(string $root, callable $accept): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $fileInfo) {
			if (($fileInfo instanceof SplFileInfo) === false || $fileInfo->isFile() === false) {
				continue;
			}

			$relative = ltrim(substr($fileInfo->getPathname(), strlen($root)), '/');
			if ($accept($relative) === false) {
				continue;
			}

			$contents = file_get_contents($fileInfo->getPathname());
			if ($contents === false) {
				continue;
			}

			$files[$relative] = $contents;
		}//end foreach

		return $files;
	}//end collect()

	/**
	 * Remove a directory and its contents. PHP has no `rm -rf` built in.
	 *
	 * @param string $path Absolute path to remove.
	 *
	 * @return void
	 */
	private function removeDirectoryRecursive(string $path): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $fileInfo) {
			if (($fileInfo instanceof SplFileInfo) === false) {
				continue;
			}

			if ($fileInfo->isDir() === true) {
				rmdir($fileInfo->getPathname());
				continue;
			}

			unlink($fileInfo->getPathname());
		}//end foreach

		rmdir($path);
	}//end removeDirectoryRecursive()
}//end class
