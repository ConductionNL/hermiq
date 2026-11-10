<?php

/**
 * Hermiq CatalogFetch.
 *
 * One read from a GitHub catalogue repository, as GitHubTemplateCatalogService
 * carries it through its steps: the repository, the ref and caller the entry
 * point assigns, the bounds of a blob collection, and the files, bytes and
 * truncation recorded so far. Built by that service and never handed out of it.
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
 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One read from a catalogue repository.
 *
 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
 */
final class CatalogFetch {

	/**
	 * The ref to read at, or null/'' for the default branch.
	 *
	 * @var string|null
	 */
	public ?string $ref = null;

	/**
	 * The session UID (broker identity), or null.
	 *
	 * @var string|null
	 */
	public ?string $actingUserId = null;

	/**
	 * The allowed `github` credential, or null/'' to read anonymously.
	 *
	 * @var string|null
	 */
	public ?string $credentialId = null;

	/**
	 * The most blobs a collection may read.
	 *
	 * @var int
	 */
	public int $maxFiles = 0;

	/**
	 * The most bytes a collection may read.
	 *
	 * @var int
	 */
	public int $maxBytes = 0;

	/**
	 * The blobs read so far, path => contents.
	 *
	 * @var array<string, string>
	 */
	public array $files = [];

	/**
	 * The bytes read so far.
	 *
	 * @var int
	 */
	public int $bytes = 0;

	/**
	 * Whether a wanted blob was left out at a bound.
	 *
	 * @var bool
	 */
	public bool $truncated = false;

	/**
	 * Start the read.
	 *
	 * @param string $owner The validated repository owner.
	 * @param string $repo The validated repository name.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
	) {
	}//end __construct()

	/**
	 * The repository's API path, `/repos/<owner>/<repo>`.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	public function apiPath(): string {
		return '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
	}//end apiPath()

	/**
	 * Whether the file or byte bound has been reached.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function isFull(): bool {
		return count($this->files) >= $this->maxFiles || $this->bytes >= $this->maxBytes;
	}//end isFull()
}//end class
