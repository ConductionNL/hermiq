<?php

/**
 * Hermiq GitHubCommitWriter.
 *
 * Lands one commit on a repository through the GitHub Git-Data API for
 * GitHubTemplatePushService: resolve the branch tip, upload the blobs, build
 * the tree on top of the tip's tree, create the commit and move the branch
 * forward. Built by that service over its broker client.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One commit through the GitHub Git-Data API.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
 */
final class GitHubCommitWriter {

	/**
	 * Per-kind repo-root file name the committed package is written to (mirrors
	 * `GitHubTemplateCatalogService::PACKAGE_FILES` — the same convention on both
	 * sides of the publish/install round-trip).
	 *
	 * @var array<string,string>
	 */
	private const PACKAGE_FILES = [
		GitHubTemplatePushService::KIND_AGENT_TEMPLATE => 'hermiq-agent-template.json',
		GitHubTemplatePushService::KIND_SKILL => 'hermiq-skill.md',
	];

	/**
	 * Build the writer.
	 *
	 * @param GitHubBrokerClient $broker The brokered GitHub API.
	 * @param LoggerInterface $logger Where a skipped unsafe path is logged.
	 */
	public function __construct(
		private readonly GitHubBrokerClient $broker,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Commit the serialized package as a single blob on the run's branch
	 * (created by `auto_init: true`, which already carries one README commit),
	 * together with its auxiliary files.
	 *
	 * @param GitHubPublishRun $run The publish.
	 * @param PackagePayload $payload The package and its auxiliary files.
	 *
	 * @return string The new commit SHA.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function commitPackage(GitHubPublishRun $run, PackagePayload $payload): string {
		$this->resolveBaseCommit(run: $run);
		$treeEntries = $this->packageEntries(run: $run, payload: $payload);

		return $this->finishCommit(
			run: $run,
			draft: new CommitDraft(entries: $treeEntries, message: $this->commitMessageFor(kind: $run->kind))
		);
	}//end commitPackage()

	/**
	 * Upload the package and its auxiliary files as blobs and return their tree entries.
	 *
	 * Skill-self-improvement: auxiliary skill files (learnings.md included, the
	 * learning-candidates.md strip already applied by the caller's selection)
	 * ride the SAME commit as additional blobs at their own paths.
	 *
	 * @param GitHubPublishRun $run The publish.
	 * @param PackagePayload $payload The package and its auxiliary files.
	 *
	 * @return array<int,array<string,string>> The tree entries.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function packageEntries(GitHubPublishRun $run, PackagePayload $payload): array {
		$packageSha = $this->uploadBlob(run: $run, contents: $payload->package);
		$treeEntries = [$this->treeEntry(path: $this->packageFileFor(kind: $run->kind), sha: $packageSha)];

		foreach ($payload->auxFiles as $auxFile) {
			if (is_array($auxFile) === false) {
				continue;
			}

			$auxName = (string)($auxFile['name'] ?? '');
			if ($auxName === '' || $this->isSafeRepoPath(path: $auxName) === false) {
				continue;
			}

			$treeEntries[] = $this->treeEntry(path: $auxName, sha: $this->uploadBlob(run: $run, contents: (string)($auxFile['content'] ?? '')));
		}

		return $treeEntries;
	}//end packageEntries()

	/**
	 * Commit an arbitrary `path => contents` tree in ONE commit.
	 *
	 * The map-based sibling of commitPackage(): the same Git-Data sequence (ref →
	 * base commit → blobs → tree → commit → ref update), but the caller supplies
	 * the whole tree rather than a package plus auxiliaries.
	 *
	 * @param GitHubPublishRun $run The bundle publish.
	 * @param array<string,string> $files The tree to commit.
	 *
	 * @return string The new commit SHA.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function commitTree(GitHubPublishRun $run, array $files): string {
		$this->resolveBaseCommit(run: $run);

		$treeEntries = $this->uploadBlobs(run: $run, files: $files);
		if ($treeEntries === []) {
			throw new RuntimeException('GitHub bundle publish: no publishable entries after path validation.');
		}

		return $this->finishCommit(
			run: $run,
			draft: new CommitDraft(entries: $treeEntries, message: 'chore: publish agent skill bundle from Hermiq')
		);
	}//end commitTree()

	/**
	 * Upload every file as a blob and return the resulting tree entries.
	 *
	 * Unsafe paths (absolute, traversal, empty segments) are skipped with a
	 * warning rather than failing the whole publish.
	 *
	 * @param GitHubPublishRun $run The bundle publish.
	 * @param array<string,string> $files The `path => contents` tree.
	 *
	 * @return array<int,array<string,string>> Tree entries for the uploaded blobs.
	 *
	 * @throws RuntimeException On API failure, or a blob that came back without a sha.
	 */
	private function uploadBlobs(GitHubPublishRun $run, array $files): array {
		$treeEntries = [];

		foreach ($files as $path => $contents) {
			$path = (string)$path;
			if ($path === '' || $this->isSafeRepoPath(path: $path) === false) {
				$this->logger->warning('Hermiq bundle publish: skipped unsafe path.', ['path' => $path]);
				continue;
			}

			$treeEntries[] = $this->treeEntry(path: $path, sha: $this->requiredBlobSha(run: $run, file: [$path, (string)$contents]));
		}

		return $treeEntries;
	}//end uploadBlobs()

	/**
	 * Upload one bundle file and return its blob sha, which must come back.
	 *
	 * A blob whose sha did not come back MUST NOT reach the tree. GitHub rejects
	 * the WHOLE create-tree request with a 422 for one bad entry, so a single
	 * silently-empty sha discards every other blob uploaded in this run — hundreds
	 * of successful uploads lost to an error message that names no file. Failing
	 * here names the path instead.
	 *
	 * @param GitHubPublishRun $run The bundle publish.
	 * @param array{0:string,1:string} $file The path and its contents.
	 *
	 * @return string The blob sha.
	 *
	 * @throws RuntimeException On API failure, or when no sha came back.
	 */
	private function requiredBlobSha(GitHubPublishRun $run, array $file): string {
		$blobSha = $this->uploadBlob(run: $run, contents: $file[1]);
		if ($blobSha === '') {
			throw new RuntimeException('GitHub bundle publish: blob upload returned no sha for "' . $file[0] . '".');
		}

		return $blobSha;
	}//end requiredBlobSha()

	/**
	 * Upload one blob and return its sha ('' when none came back).
	 *
	 * @param GitHubPublishRun $run The publish.
	 * @param string $contents The blob contents.
	 *
	 * @return string The blob sha.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function uploadBlob(GitHubPublishRun $run, string $contents): string {
		$blob = $this->broker->postJson(
			run: $run,
			call: (new GitHubCall(method: 'POST', path: $run->target->apiPath() . '/git/blobs'))
				->withBody(['content' => base64_encode($contents), 'encoding' => 'base64'])
		);

		return (string)($blob['sha'] ?? '');
	}//end uploadBlob()

	/**
	 * A tree entry for a regular file blob.
	 *
	 * @param string $path The repository path.
	 * @param string $sha The blob sha.
	 *
	 * @return array<string,string> The entry.
	 */
	private function treeEntry(string $path, string $sha): array {
		return [
			'path' => $path,
			'mode' => '100644',
			'type' => 'blob',
			'sha' => $sha,
		];
	}//end treeEntry()

	/**
	 * Resolve the tip of the run's branch and its tree, which the commit builds on.
	 *
	 * @param GitHubPublishRun $run The publish; the base commit and tree are recorded here.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the branch tip cannot be resolved, or on API failure.
	 */
	private function resolveBaseCommit(GitHubPublishRun $run): void {
		$base = $run->target->apiPath();

		$ref = $this->broker->getJson(run: $run, path: $base . '/git/refs/heads/' . rawurlencode($run->branch));
		$run->baseCommitSha = (string)($ref['object']['sha'] ?? '');
		if ($run->baseCommitSha === '') {
			throw new RuntimeException($run->commitLabel . ': could not resolve the default branch tip.');
		}

		$baseCommit = $this->broker->getJson(run: $run, path: $base . '/git/commits/' . rawurlencode($run->baseCommitSha));
		$run->baseTreeSha = (string)($baseCommit['tree']['sha'] ?? '');
	}//end resolveBaseCommit()

	/**
	 * Create the tree and the commit on top of the run's base, then move the branch forward.
	 *
	 * The tree rides `base_tree`, which preserves every path this publish does not
	 * own — the property that makes updating an existing repository safe rather
	 * than truncating. The ref is PATCHed forward, never force-pushed.
	 *
	 * @param GitHubPublishRun $run The publish, with its base commit resolved.
	 * @param CommitDraft $draft The tree entries and the commit message.
	 *
	 * @return string The new commit SHA.
	 *
	 * @throws RuntimeException When the commit could not be created, or on API failure.
	 */
	private function finishCommit(GitHubPublishRun $run, CommitDraft $draft): string {
		$base = $run->target->apiPath();

		$commitSha = $this->createCommit(run: $run, draft: $draft);

		$this->broker->call(
			run: $run,
			call: (new GitHubCall(method: 'PATCH', path: $base . '/git/refs/heads/' . rawurlencode($run->branch)))->withBody(['sha' => $commitSha])
		);

		return $commitSha;
	}//end finishCommit()

	/**
	 * Create the tree on top of the base tree, and the commit on top of the base commit.
	 *
	 * @param GitHubPublishRun $run The publish, with its base commit resolved.
	 * @param CommitDraft $draft The tree entries and the commit message.
	 *
	 * @return string The new commit SHA.
	 *
	 * @throws RuntimeException When the commit could not be created, or on API failure.
	 */
	private function createCommit(GitHubPublishRun $run, CommitDraft $draft): string {
		$treeSha = $this->createTree(run: $run, draft: $draft);
		$commit = $this->broker->postJson(
			run: $run,
			call: (new GitHubCall(method: 'POST', path: $run->target->apiPath() . '/git/commits'))
				->withBody(['message' => $draft->message, 'tree' => $treeSha, 'parents' => [$run->baseCommitSha]])
		);
		$commitSha = (string)($commit['sha'] ?? '');
		if ($commitSha === '') {
			throw new RuntimeException($run->commitLabel . ': commit creation failed.');
		}

		return $commitSha;
	}//end createCommit()

	/**
	 * Create the draft's tree on top of the run's base tree.
	 *
	 * @param GitHubPublishRun $run The publish, with its base commit resolved.
	 * @param CommitDraft $draft The tree entries.
	 *
	 * @return string The new tree SHA ('' when none came back).
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function createTree(GitHubPublishRun $run, CommitDraft $draft): string {
		$tree = $this->broker->postJson(
			run: $run,
			call: (new GitHubCall(method: 'POST', path: $run->target->apiPath() . '/git/trees'))
				->withBody(['base_tree' => $run->baseTreeSha, 'tree' => $draft->entries])
		);

		return (string)($tree['sha'] ?? '');
	}//end createTree()

	/**
	 * Resolve a kind's repo-root package file name (hermiq-github-store),
	 * falling back to `KIND_AGENT_TEMPLATE`'s file name for an unrecognised kind.
	 *
	 * @param string $kind The publish kind.
	 *
	 * @return string The repo-relative package file name.
	 */
	private function packageFileFor(string $kind): string {
		return self::PACKAGE_FILES[$kind] ?? self::PACKAGE_FILES[GitHubTemplatePushService::KIND_AGENT_TEMPLATE];
	}//end packageFileFor()

	/**
	 * Kind-appropriate commit message (hermiq-github-store).
	 *
	 * @param string $kind The publish kind.
	 *
	 * @return string The commit message.
	 */
	private function commitMessageFor(string $kind): string {
		if ($kind === GitHubTemplatePushService::KIND_SKILL) {
			return 'chore: publish agent skill from Hermiq';
		}

		return 'chore: publish agent template from Hermiq';
	}//end commitMessageFor()

	/**
	 * Whether an auxiliary file name is a safe repo-relative path: no absolute
	 * paths, no `..` traversal, no backslashes, sane length.
	 *
	 * @param string $path The candidate repo path.
	 *
	 * @return bool True when safe to commit.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	private function isSafeRepoPath(string $path): bool {
		if (strlen($path) > 200 || str_starts_with($path, '/') === true || str_contains($path, '\\') === true) {
			return false;
		}

		return $this->hasOnlyNamedSegments(path: $path);
	}//end isSafeRepoPath()

	/**
	 * Whether no segment of a path is empty, `.` or `..`.
	 *
	 * @param string $path The repository-relative path.
	 *
	 * @return bool True when every segment names something.
	 */
	private function hasOnlyNamedSegments(string $path): bool {
		foreach (explode('/', $path) as $segment) {
			if (in_array($segment, ['', '.', '..'], true) === true) {
				return false;
			}
		}

		return true;
	}//end hasOnlyNamedSegments()
}//end class
