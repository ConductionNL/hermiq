<?php

/**
 * Contract of GitHubTemplatePushService
 *
 * The public surface of GitHubTemplatePushService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of GitHubTemplatePushService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubTemplatePushServiceInterface {

	/**
	 * The `AgentTemplate` kind — the original (and default) publish kind.
	 *
	 * @var string
	 */
	public const KIND_AGENT_TEMPLATE = 'agent-template';

	/**
	 * The `Skill` kind (hermiq-github-store) — agentskills.io packages.
	 *
	 * @var string
	 */
	public const KIND_SKILL = 'skill';

	/**
	 * Whether OpenRegister's credential broker is installed.
	 *
	 * @return bool True when the broker class can be resolved.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function isBrokerAvailable(): bool;

	/**
	 * BUNDLE PUBLISH (skill-bundle-publish): commit a whole `path => contents` tree
	 * to a repository, CREATING it when absent and UPDATING it when present.
	 *
	 * This is the deliberate, narrow second carve-out from "refuse to overwrite an
	 * existing repository". The original refusal protects a single-skill publish
	 * from clobbering an unrelated repo; a bundle repo is by definition re-synced,
	 * so refusing it would make the feature useless. The safeguards that make the
	 * carve-out safe are:
	 *
	 *   - the commit rides `base_tree`, so paths OUTSIDE the bundle's own
	 *     `skills/` + manifest are preserved rather than truncated;
	 *   - the ref is PATCHed forward, never force-pushed;
	 *   - the caller supplies the tree from SkillBundleSerializer, which has
	 *     already validated every name and path.
	 *
	 * @param array<string,string> $files The `path => contents` tree to commit.
	 * @param string $owner Target GitHub owner (user or organisation).
	 * @param string $repo Target repository name.
	 * @param string $visibility Visibility for a freshly created repo.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Credential owner.
	 *
	 * @return array{repoUrl:string,commitSha:string,created:bool} The publish outcome.
	 *
	 * @throws RuntimeException On a missing broker, bad coordinates, or API failure.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function publishBundle(
		array $files,
		string $owner,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId = null,
	): array;

	/**
	 * Publish a serialized AgentTemplate package to a new, tagged GitHub repository.
	 *
	 * @param string $package The JSON package string (`AgentTemplateSerializer::toPackage()` output).
	 * @param string $owner Target GitHub owner (user or organisation).
	 * @param string $repo Target repository name.
	 * @param string $visibility Repository visibility (`public`|`private`).
	 * @param string $credentialId Broker credential UUID for a `github` provider credential.
	 *                             Not a token: this service cannot read the secret behind
	 *                             it.
	 * @param string|null $actingUserId Credential owner. Required when there is no user
	 *                                  session for the broker's ownership guard to read.
	 * @param string $kind The publish kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`,
	 *                     hermiq-github-store). Defaults to `KIND_AGENT_TEMPLATE` —
	 *                     every existing caller that omits `$kind` gets EXACTLY the
	 *                     prior agent-template-only behaviour.
	 * @param array $auxFiles Additional repo-root files (`{name, content}` entries) to
	 *                        commit alongside the package (skill-self-improvement:
	 *                        the ALREADY-SELECTED skill files — the caller applies the
	 *                        `learning-candidates.md` strip BEFORE this boundary).
	 *                        Empty (every pre-existing caller) is byte-identical to
	 *                        before.
	 *
	 * @return array{repoUrl:string,commitSha:string} The repo URL and the commit SHA the package landed in.
	 *
	 * @throws RuntimeException On any GitHub API failure (broker absent, no credential,
	 *                          repo already exists, auth failure, …).
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function push(
		string $package,
		string $owner,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId = null,
		string $kind = self::KIND_AGENT_TEMPLATE,
		array $auxFiles = [],
	): array;

	/**
	 * REPUBLISH: update-mode push to an EXISTING repository (skill-self-improvement) —
	 * the exactly-one carve-out from "refuse to overwrite an existing repository".
	 * Reachable ONLY for the repo already stamped on the SAME skill's provenance:
	 * the CALLER (SkillVersionController::republish()) derives owner/repo from the
	 * skill's own `githubOwner`/`githubRepo` and never accepts client coordinates,
	 * so publishing to any OTHER existing repository still refuses through the
	 * normal `push()` path. Same fail-closed broker chain, token never held or
	 * logged. The repo MUST already exist — the inverse of `assertRepoAbsent()`.
	 *
	 * @param string $package The serialized package string.
	 * @param string $owner The provenance-stamped GitHub owner.
	 * @param string $repo The provenance-stamped repository name.
	 * @param string $credentialId Broker credential UUID for a `github` provider credential.
	 * @param string|null $actingUserId Credential owner.
	 * @param string $kind The publish kind (`KIND_SKILL` for skills).
	 * @param array $auxFiles Additional repo-root files (already selection-filtered).
	 *
	 * @return array{repoUrl:string,commitSha:string} The repo URL and the update commit SHA.
	 *
	 * @throws RuntimeException On any GitHub API failure (broker absent, no credential,
	 *                          repo missing, auth failure, …).
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
	 */
	public function pushUpdate(
		string $package,
		string $owner,
		string $repo,
		string $credentialId,
		?string $actingUserId = null,
		string $kind = self::KIND_SKILL,
		array $auxFiles = [],
	): array;
}//end interface
