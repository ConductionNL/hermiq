<?php

/**
 * Contract of GitHubTemplateCatalogService
 *
 * The public surface of GitHubTemplateCatalogService that other components call, so they depend
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

/**
 * Contract of GitHubTemplateCatalogService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubTemplateCatalogServiceInterface {

	/**
	 * The `AgentTemplate` kind — the original (and default) discovery kind.
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
	 * Outcome: transport failure / non-2xx that is not a rate limit.
	 *
	 * @var string
	 */
	public const OUTCOME_UNREACHABLE = 'github_unreachable';

	/**
	 * Fetch a published skill repo's AUXILIARY files — every blob in the repo tree
	 * except the package file itself (skill-package-multifile).
	 *
	 * `publish()` commits auxiliary files as sibling blobs at their own (possibly
	 * nested) paths, but `fetchPackageFile()` only ever retrieved the single package
	 * file. Installing from GitHub therefore reconstructed a bare SKILL.md and
	 * silently dropped every `references/`, `examples/` and `learnings.md` entry — a
	 * lossy round trip through the very path the app-repo store depends on.
	 *
	 * Bounded on purpose: at most MAX_AUX_FILES blobs and MAX_AUX_BYTES total, so a
	 * hostile or accidentally enormous repository cannot turn one install into an
	 * unbounded fan-out of API calls. Truncation is logged rather than silent.
	 *
	 * @param string $kind The publish kind (KIND_SKILL|KIND_AGENT_TEMPLATE).
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<int, array{name: string, content: string}> The auxiliary files.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function fetchAuxFiles(
		string $kind,
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): array;

	/**
	 * Fetch a BUNDLE repository as a `path => contents` map (skill-bundle-publish).
	 *
	 * A bundle is identified by `hermiq-skills.json` at the repo root. Its ABSENCE
	 * is a definitive "not a bundle" (null), never a best-effort parse — a repo
	 * that half-reads as a bundle is worse than one that refuses, because the
	 * caller would install a partial skill set believing it complete.
	 *
	 * Only the manifest and blobs under `skills/` are fetched; anything else in
	 * the repository is ignored, so a bundle can live alongside other content.
	 * Bounded by MAX_BUNDLE_BYTES with truncation reported rather than silent.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array{files:array<string,string>,truncated:bool}|null The bundle tree,
	 *                                                               or null when the repo is not a bundle / unreachable.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function fetchBundle(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?array;

	/**
	 * Fetch a repo's portable package file for the given kind (raw string,
	 * unparsed) — the generalised form of `fetchTemplateFile()`
	 * (hermiq-github-store). The caller hands this verbatim to the matching
	 * quarantine/scan import path: `AgentTemplateService::importPackage(source:
	 * 'hub')` for `KIND_AGENT_TEMPLATE`, `SkillMarketplaceService::
	 * installFromSource(source: 'hub')` for `KIND_SKILL`. No parsing happens here
	 * beyond that existing serializer/quarantine/scan path.
	 *
	 * @param string $kind The discovery kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string|null $ref Optional git ref (pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The raw package string, or null when missing/unreadable/invalid.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 */
	public function fetchPackageFile(
		string $kind,
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?string;

	/**
	 * Fetch a repo's portable template package file (raw JSON string, unparsed) —
	 * the caller (AgentTemplateController::githubInstall()) hands this verbatim to
	 * `AgentTemplateService::importPackage(source: 'hub')`, so no parsing happens
	 * here: the existing serializer/quarantine/scan path owns that entirely.
	 *
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string|null $ref Optional git ref (pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The raw package JSON string, or null when missing/unreadable/invalid.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	public function fetchTemplateFile(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?string;

	/**
	 * Whether the OpenRegister credential broker is present on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 */
	public function isBrokerAvailable(): bool;

	/**
	 * Search GitHub for a kind's discovery-topic repos and build cards.
	 *
	 * Hermiq-github-store: generalised with a `$kind` seam (default
	 * `KIND_AGENT_TEMPLATE`, unchanged from the original agent-template-only
	 * behaviour — every existing caller that omits `$kind` gets EXACTLY the prior
	 * result). `SkillController::githubSearch()` calls this with `KIND_SKILL`. The
	 * unified "Store" page issues one call per active kind filter and merges the
	 * kind-tagged cards client-side rather than this method searching both topics
	 * in one round trip — keeping each kind's search independently regression-safe.
	 *
	 * @param string|null $query Optional free-text term appended to the topic query.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential to upgrade the call.
	 * @param string $kind The discovery kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
	 */
	public function search(?string $query, ?string $actingUserId, ?string $credentialId = null, string $kind = self::KIND_AGENT_TEMPLATE): array;
}//end interface
