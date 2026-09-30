<?php

/**
 * Hermiq WorkspacePathGuard.
 *
 * Confines every path argument of a governed workspace tool to the workspace.
 * Two independent checks:
 *
 * 1. Containment. An absolute path, a drive letter, a NUL byte or a `..`
 *    segment is refused before anything touches the filesystem. What is left is
 *    resolved THROUGH its symbolic links (the deepest existing ancestor goes
 *    through realpath) and must land under the workspace root. The lexical form
 *    never decides: `docs/link/x` where `docs/link` points at `/etc` is refused.
 * 2. The repository metadata directory. Any write-shaped target that resolves
 *    under `.git` is refused, whatever file it names. There is no allowlist of
 *    "safe" metadata files: the set of git configuration keys that run a command
 *    grows with git, so an allowlist would be a denylist in disguise.
 *
 * A patch is checked target by target before anything is applied, and one bad
 * target refuses the whole patch.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * Resolves a workspace-relative path to an absolute one, or refuses it.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution
 */
class WorkspacePathGuard {

	/**
	 * The repository metadata directory name.
	 *
	 * @var string
	 */
	private const METADATA_DIR = '.git';

	/**
	 * Resolve a workspace-relative path for reading.
	 *
	 * @param string $root         The workspace root (absolute).
	 * @param string $relativePath The path the model supplied.
	 *
	 * @return string The absolute, contained path.
	 *
	 * @throws WorkspaceException path_outside_workspace.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-every-path-argument-is-confined-to-the-workspace-after-symlink-resolution
	 */
	public function forRead(string $root, string $relativePath): string {
		return $this->contain(root: $root, relativePath: $relativePath);
	}//end forRead()

	/**
	 * Resolve a workspace-relative path for a write-shaped operation.
	 *
	 * @param string $root         The workspace root (absolute).
	 * @param string $relativePath The path the model supplied.
	 *
	 * @return string The absolute, contained path, never under `.git`.
	 *
	 * @throws WorkspaceException path_outside_workspace or path_forbidden.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
	 */
	public function forWrite(string $root, string $relativePath): string {
		// The metadata check runs on the supplied form too, so `.git/hooks/x` is
		// refused even before the containment walk, and again on the resolved
		// form below, so a symlink that leads into `.git` is refused as well.
		if ($this->namesMetadata(relativePath: $relativePath) === true) {
			throw $this->forbidden();
		}

		$absolute = $this->contain(root: $root, relativePath: $relativePath);
		$realRoot = (string)realpath($root);
		$inside = substr($absolute, strlen($realRoot) + 1);
		if ($this->namesMetadata(relativePath: $inside) === true) {
			throw $this->forbidden();
		}

		return $absolute;
	}//end forWrite()

	/**
	 * Validate a branch or tag name the model supplied: letters, digits, dots,
	 * dashes and slashes, no `..`, no trailing `/` or `.lock`, never an option.
	 *
	 * @param string $value The name.
	 *
	 * @return string The trimmed name.
	 *
	 * @throws WorkspaceException invalid_argument.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-workspace-and-git-capability-is-exposed-only-as-a-closed-named-mcp-tool-surface
	 */
	public function refName(string $value): string {
		$ref = trim($value);
		if ($ref === '' || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#', $ref) !== 1
			|| str_contains($ref, '..') === true || str_ends_with($ref, '.lock') === true || str_ends_with($ref, '/') === true
		) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::INVALID_ARGUMENT,
				message: 'Name a branch or tag with letters, digits, dots, dashes and slashes.'
			);
		}

		return $ref;
	}//end refName()

	/**
	 * Check every target of a unified diff before any of it is applied.
	 *
	 * Refuses renames, copies and symbolic-link modes outright: they create or
	 * move paths in ways a per-target check cannot describe.
	 *
	 * @param string $root  The workspace root (absolute).
	 * @param string $patch The unified diff.
	 *
	 * @return array<int, string> The workspace-relative targets, deduplicated.
	 *
	 * @throws WorkspaceException patch_rejected, path_outside_workspace or path_forbidden.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-patch-touching-a-refused-path-is-rejected-whole
	 */
	public function patchTargets(string $root, string $patch): array {
		if (preg_match('/^(rename|copy) (from|to) /m', $patch) === 1
			|| preg_match('/^(new file mode|new mode|old mode|deleted file mode) 120000/m', $patch) === 1
			|| preg_match('/^GIT binary patch/m', $patch) === 1
		) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::PATCH_REJECTED,
				message: 'The patch renames, copies or links a file, or is binary. Send plain content changes only.'
			);
		}

		$targets = [];
		$matched = preg_match_all('/^(?:---|\+\+\+) (\S+)/m', $patch, $matches);
		if ($matched === false || $matched === 0) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::PATCH_REJECTED,
				message: 'The patch names no file.'
			);
		}

		foreach ($matches[1] as $raw) {
			if ($raw === '/dev/null') {
				continue;
			}

			$target = preg_replace('#^[ab]/#', '', $raw);
			$targets[(string)$target] = true;
		}

		$targets = array_keys($targets);
		foreach ($targets as $target) {
			$this->forWrite(root: $root, relativePath: $target);
		}

		return $targets;
	}//end patchTargets()

	/**
	 * Lexical refusal, then symlink-resolved containment.
	 *
	 * @param string $root         The workspace root.
	 * @param string $relativePath The supplied path.
	 *
	 * @return string The absolute path.
	 *
	 * @throws WorkspaceException path_outside_workspace.
	 */
	private function contain(string $root, string $relativePath): string {
		$segments = $this->segments(relativePath: $relativePath);
		$realRoot = realpath($root);
		if ($realRoot === false) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::WORKSPACE_ABSENT,
				message: 'No workspace is open for this run. Call workspace open first.'
			);
		}

		// Walk down to the deepest part that exists, resolve THAT through its
		// links, and append what does not exist yet (a file about to be written).
		$existing = $realRoot;
		$rest = [];
		foreach ($segments as $index => $segment) {
			$candidate = $existing . '/' . $segment;
			if (file_exists($candidate) === false && is_link($candidate) === false) {
				$rest = array_slice($segments, $index);
				break;
			}

			$resolved = realpath($candidate);
			if ($resolved === false) {
				// A dangling link: its target cannot be checked, so it is refused.
				throw $this->outside();
			}

			$existing = $resolved;
		}

		if ($existing !== $realRoot && str_starts_with($existing, $realRoot . '/') === false) {
			throw $this->outside();
		}

		if ($rest === []) {
			return $existing;
		}

		return $existing . '/' . implode('/', $rest);
	}//end contain()

	/**
	 * The lexical refusal: the path's segments, or a refusal for an absolute,
	 * drive-letter, home-relative, NUL-carrying, empty or traversing path.
	 *
	 * @param string $relativePath The supplied path.
	 *
	 * @return array<int, string> The non-empty segments.
	 *
	 * @throws WorkspaceException path_outside_workspace.
	 */
	private function segments(string $relativePath): array {
		$path = str_replace('\\', '/', $relativePath);
		$absolute = (str_starts_with($path, '/') === true || str_starts_with($path, '~') === true || preg_match('/^[A-Za-z]:/', $path) === 1);
		if ($path === '' || str_contains($path, "\0") === true || $absolute === true) {
			throw $this->outside();
		}

		$segments = $this->split(path: $path);
		if ($segments === [] || in_array('..', $segments, true) === true) {
			throw $this->outside();
		}

		return $segments;
	}//end segments()

	/**
	 * Split a path into its segments, dropping empty and `.` ones.
	 *
	 * @param string $path The path with forward slashes.
	 *
	 * @return array<int, string>
	 */
	private function split(string $path): array {
		return array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '' && $segment !== '.'));
	}//end split()

	/**
	 * Whether a workspace-relative path lies under the metadata directory.
	 *
	 * Case-insensitive, because on a case-insensitive filesystem `.GIT/config`
	 * is the same file.
	 *
	 * @param string $relativePath The path.
	 *
	 * @return bool
	 */
	private function namesMetadata(string $relativePath): bool {
		$segments = $this->split(path: str_replace('\\', '/', $relativePath));
		return ($segments !== [] && strtolower($segments[0]) === self::METADATA_DIR);
	}//end namesMetadata()

	/**
	 * The containment refusal.
	 *
	 * @return WorkspaceException
	 */
	private function outside(): WorkspaceException {
		return new WorkspaceException(
			errorCode: WorkspaceException::PATH_OUTSIDE_WORKSPACE,
			message: 'The path is outside the workspace. Use a path relative to the repository root, without "..".'
		);
	}//end outside()

	/**
	 * The metadata refusal.
	 *
	 * @return WorkspaceException
	 */
	private function forbidden(): WorkspaceException {
		return new WorkspaceException(
			errorCode: WorkspaceException::PATH_FORBIDDEN,
			message: 'The repository metadata directory cannot be written.'
		);
	}//end forbidden()
}//end class
