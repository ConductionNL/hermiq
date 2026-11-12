<?php

/**
 * Hermiq GitHubPublishRun.
 *
 * One publish to GitHub as GitHubTemplatePushService carries it through its
 * steps: the target repository and broker credential, the per-publish settings
 * the entry point assigns, and the progress (branch, base commit, URL) the
 * steps record. Built by that service and never handed out of it.
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
 * The state of one GitHub publish.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
 */
final class GitHubPublishRun {

	/**
	 * How refusals of this publish are worded (`GitHub publish`, `GitHub republish`, `GitHub bundle publish`).
	 *
	 * @var string
	 */
	public string $operation = 'GitHub publish';

	/**
	 * How refusals of the commit step are worded.
	 *
	 * @var string
	 */
	public string $commitLabel = 'GitHub publish';

	/**
	 * The publish kind (agent template, skill, skill bundle).
	 *
	 * @var string
	 */
	public string $kind = 'agent-template';

	/**
	 * Visibility for a freshly created repository (`public`|`private`).
	 *
	 * @var string
	 */
	public string $visibility = 'private';

	/**
	 * The branch the commit lands on.
	 *
	 * @var string
	 */
	public string $branch = 'main';

	/**
	 * The repository's web URL.
	 *
	 * @var string
	 */
	public string $repoUrl = '';

	/**
	 * Whether this publish created the repository.
	 *
	 * @var bool
	 */
	public bool $created = false;

	/**
	 * The tip of the branch the commit builds on.
	 *
	 * @var string
	 */
	public string $baseCommitSha = '';

	/**
	 * The tree of that tip.
	 *
	 * @var string
	 */
	public string $baseTreeSha = '';

	/**
	 * Start the run.
	 *
	 * @param GitHubRepoTarget $target The repository written to.
	 * @param BrokerCredential $credential The broker credential the calls use.
	 */
	public function __construct(
		public readonly GitHubRepoTarget $target,
		public readonly BrokerCredential $credential,
	) {
	}//end __construct()
}//end class
