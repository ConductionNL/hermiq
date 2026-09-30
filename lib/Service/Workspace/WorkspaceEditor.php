<?php

/**
 * Hermiq WorkspaceEditor.
 *
 * The in-workspace write tools of the governed git surface: write, delete and
 * patch files, create and switch branches, and commit. Every path goes through
 * the path guard's write form (containment after symlink resolution, `.git`
 * deny-write), every growth through the provider's budget, and every commit is
 * authored and committed as the resolved run owner: the commit tool takes no
 * identity argument, and the environment outranks any repository-local
 * configuration the model could have written.
 *
 * Callers must have passed the approval gate (`WorkspaceWriteAuthoriser`)
 * first; this class does not decide who may write, only how a write is done.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCP\IUserManager;

/**
 * Performs the in-workspace writes.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner
 */
class WorkspaceEditor {

	/**
	 * The largest file content a single write accepts.
	 *
	 * @var int
	 */
	public const MAX_WRITE_BYTES = 1048576;

	/**
	 * The longest commit message accepted.
	 *
	 * @var int
	 */
	private const MAX_MESSAGE_CHARS = 5000;

	/**
	 * Budget for a local git command.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 20;

	/**
	 * Build the editor.
	 *
	 * @param WorkspaceProvider  $provider    The workspace store (budgets).
	 * @param WorkspacePathGuard $guard       Path confinement.
	 * @param GitRunner          $git         The hardened git runner.
	 * @param IUserManager       $userManager Resolves the run owner.
	 */
	public function __construct(
		private readonly WorkspaceProvider $provider,
		private readonly WorkspacePathGuard $guard,
		private readonly GitRunner $git,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * `write_file`: create or overwrite one file.
	 *
	 * @param string               $runKey    The run id.
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments `path`, `content`, optional `mode` (overwrite|create).
	 *
	 * @return array{path: string, action: string, bytes: int}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
	 */
	public function writeFile(string $runKey, string $root, array $arguments): array {
		$path = (string)($arguments['path'] ?? '');
		$content = $arguments['content'] ?? null;
		if (is_string($content) === false || strlen($content) > self::MAX_WRITE_BYTES) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::INVALID_ARGUMENT,
				message: 'Send the file content as text of at most one megabyte.'
			);
		}

		$absolute = $this->guard->forWrite(root: $root, relativePath: $path);
		$exists = is_file($absolute);
		if ($exists === false && file_exists($absolute) === true) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That path is a folder, not a file.');
		}

		if ($exists === true && ($arguments['mode'] ?? 'overwrite') === 'create') {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That file already exists.');
		}

		$oldBytes = 0;
		$newFiles = 1;
		$action = 'created';
		if ($exists === true) {
			$oldBytes = (int)filesize($absolute);
			$newFiles = 0;
			$action = 'modified';
		}

		$this->provider->reserve(runKey: $runKey, addBytes: (strlen($content) - $oldBytes), addFiles: $newFiles);

		$folder = dirname($absolute);
		if (is_dir($folder) === false) {
			mkdir($folder, 0755, true);
		}

		file_put_contents($absolute, $content);

		return ['path' => $path, 'action' => $action, 'bytes' => strlen($content)];
	}//end writeFile()

	/**
	 * `delete_file`: remove one file.
	 *
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments `path`.
	 *
	 * @return array{path: string, action: string, bytes: int}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution
	 */
	public function deleteFile(string $root, array $arguments): array {
		$path = (string)($arguments['path'] ?? '');
		$absolute = $this->guard->forWrite(root: $root, relativePath: $path);
		if (is_file($absolute) === false) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That file does not exist in the workspace.');
		}

		unlink($absolute);

		return ['path' => $path, 'action' => 'deleted', 'bytes' => 0];
	}//end deleteFile()

	/**
	 * `apply_patch`: apply a unified diff whole, or not at all.
	 *
	 * @param string               $runKey    The run id.
	 * @param string               $root      The workspace root.
	 * @param array<string, mixed> $arguments `patch`.
	 *
	 * @return array{files: array<int, string>, action: string}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-patch-touching-a-refused-path-is-rejected-whole
	 */
	public function applyPatch(string $runKey, string $root, array $arguments): array {
		$patch = (string)($arguments['patch'] ?? '');
		if ($patch === '' || strlen($patch) > self::MAX_WRITE_BYTES) {
			throw new WorkspaceException(errorCode: WorkspaceException::PATCH_REJECTED, message: 'Send a unified diff of at most one megabyte.');
		}

		$targets = $this->guard->patchTargets(root: $root, patch: $patch);
		$this->provider->reserve(runKey: $runKey, addBytes: strlen($patch), addFiles: count($targets));

		// `--check` first, so a patch that does not apply cleanly leaves no hunk
		// behind; git itself also refuses paths beyond a symbolic link.
		foreach ([['apply', '--check', '--whitespace=nowarn', '-'], ['apply', '--whitespace=nowarn', '-']] as $command) {
			$result = $this->git->run(arguments: $command, workingDir: $root, timeoutSeconds: self::TIMEOUT_SECONDS, stdin: $patch);
			if ($result['exit'] !== 0) {
				throw new WorkspaceException(
					errorCode: WorkspaceException::PATCH_REJECTED,
					message: 'The patch does not apply to the workspace as it is. Read the files again and send a fresh diff.'
				);
			}
		}

		return ['files' => $targets, 'action' => 'patched'];
	}//end applyPatch()

	/**
	 * `create_branch`: start a new local branch at the current commit and switch to it.
	 *
	 * @param string $root   The workspace root.
	 * @param string $branch A validated branch name.
	 *
	 * @return array{branch: string}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
	 */
	public function createBranch(string $root, string $branch): array {
		$this->gitOk(root: $root, arguments: ['switch', '--no-guess', '-c', $branch], failure: 'That branch could not be created. It may exist already.');
		return ['branch' => $branch];
	}//end createBranch()

	/**
	 * `checkout_branch`: switch to an existing local branch.
	 *
	 * @param string $root   The workspace root.
	 * @param string $branch A validated branch name.
	 *
	 * @return array{branch: string}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
	 */
	public function checkoutBranch(string $root, string $branch): array {
		$this->gitOk(
			root: $root,
			arguments: ['switch', '--no-guess', $branch],
			failure: 'That branch does not exist in the workspace, or uncommitted changes are in the way.'
		);
		return ['branch' => $branch];
	}//end checkoutBranch()

	/**
	 * `commit`: commit the changes as the run owner.
	 *
	 * @param string               $root      The workspace root.
	 * @param string               $ownerUid  The resolved run owner, from the verified token.
	 * @param array<string, mixed> $arguments `message`, optional `paths`.
	 *
	 * @return array{sha: string, branch: string, author: string, filesChanged: int}
	 *
	 * @throws WorkspaceException owner_unresolvable when the run has no owner.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-commit-identity-comes-from-the-owner-not-the-model
	 */
	public function commit(string $root, string $ownerUid, array $arguments): array {
		$identity = $this->identity(ownerUid: $ownerUid);
		$message = trim((string)($arguments['message'] ?? ''));
		if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_CHARS) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'Write a commit message of at most 5000 characters.');
		}

		$add = ['add', '--all', '--'];
		foreach ((array)($arguments['paths'] ?? []) as $path) {
			$this->guard->forWrite(root: $root, relativePath: (string)$path);
			$add[] = (string)$path;
		}

		if (count($add) === 3) {
			$add[] = '.';
		}

		$this->gitOk(root: $root, arguments: $add, failure: 'The changes could not be staged.');

		$env = [
			'GIT_AUTHOR_NAME' => $identity['name'],
			'GIT_AUTHOR_EMAIL' => $identity['email'],
			'GIT_COMMITTER_NAME' => $identity['name'],
			'GIT_COMMITTER_EMAIL' => $identity['email'],
		];
		$result = $this->git->run(
			arguments: ['commit', '--quiet', '--no-verify', '--no-gpg-sign', '--file', '-'],
			workingDir: $root,
			timeoutSeconds: self::TIMEOUT_SECONDS,
			extraEnv: $env,
			stdin: $message
		);
		if ($result['exit'] !== 0) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'There is nothing to commit.');
		}

		$changed = $this->gitOk(
			root: $root,
			arguments: ['diff-tree', '--no-commit-id', '--name-only', '-r', 'HEAD'],
			failure: 'The commit could not be read back.'
		);

		return [
			'sha' => trim($this->gitOk(root: $root, arguments: ['rev-parse', 'HEAD'], failure: 'The commit could not be read back.')),
			'branch' => trim($this->gitOk(root: $root, arguments: ['branch', '--show-current'], failure: 'The branch could not be read.')),
			'author' => $ownerUid,
			'filesChanged' => count(array_filter(explode("\n", $changed), static fn (string $line): bool => $line !== '')),
		];
	}//end commit()

	/**
	 * The commit identity of the run owner.
	 *
	 * @param string $ownerUid The run owner's Nextcloud user id.
	 *
	 * @return array{name: string, email: string}
	 *
	 * @throws WorkspaceException owner_unresolvable.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-an-unowned-run-cannot-author-or-push
	 */
	private function identity(string $ownerUid): array {
		$user = null;
		if (trim($ownerUid) !== '') {
			$user = $this->userManager->get($ownerUid);
		}

		if ($user === null) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::OWNER_UNRESOLVABLE,
				message: 'This run has no owner to author a commit. It can still read the workspace.'
			);
		}

		$email = (string)$user->getEMailAddress();
		if ($email === '') {
			$email = $ownerUid . '@users.noreply.invalid';
		}

		return ['name' => $user->getDisplayName(), 'email' => $email];
	}//end identity()

	/**
	 * Run a local git command and return its output, or refuse with a sentence.
	 *
	 * @param string             $root      The workspace root.
	 * @param array<int, string> $arguments The git arguments.
	 * @param string             $failure   The sentence for the model on failure.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException git_failed.
	 */
	private function gitOk(string $root, array $arguments, string $failure): string {
		$result = $this->git->run(arguments: $arguments, workingDir: $root, timeoutSeconds: self::TIMEOUT_SECONDS);
		if ($result['exit'] !== 0) {
			throw new WorkspaceException(errorCode: WorkspaceException::GIT_FAILED, message: $failure);
		}

		return $result['stdout'];
	}//end gitOk()
}//end class
