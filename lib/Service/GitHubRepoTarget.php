<?php

/**
 * Hermiq GitHubRepoTarget.
 *
 * The GitHub repository a publish writes to: its owner (user or organisation)
 * and name, and the paths and URL derived from them.
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One GitHub repository.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */
final class GitHubRepoTarget {

	/**
	 * GitHub's own bound on an owner or repository name.
	 *
	 * @var string
	 */
	public const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Build the target.
	 *
	 * @param string $owner The GitHub owner (user or organisation).
	 * @param string $repo The repository name.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
	) {
	}//end __construct()

	/**
	 * Whether both the owner and the repository are valid GitHub names.
	 *
	 * @return bool True when both match NAME_PATTERN.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function hasValidNames(): bool {
		return preg_match(self::NAME_PATTERN, $this->owner) === 1 && preg_match(self::NAME_PATTERN, $this->repo) === 1;
	}//end hasValidNames()

	/**
	 * The repository's API path, `/repos/<owner>/<repo>`.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function apiPath(): string {
		return '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
	}//end apiPath()

	/**
	 * The repository's web URL when GitHub did not name one.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 */
	public function defaultUrl(): string {
		return 'https://github.com/' . $this->owner . '/' . $this->repo;
	}//end defaultUrl()
}//end class
