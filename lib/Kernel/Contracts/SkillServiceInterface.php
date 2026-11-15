<?php

/**
 * Contract of SkillService
 *
 * The public surface of SkillService that other components call, so they depend
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of SkillService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillServiceInterface {

	/**
	 * Export a Skill back to an agentskills.io package string.
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return string|null The package string, or null when the skill is not found.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-catalog/tasks.md#3-skillservice
	 */
	public function exportSkill(string $skillId): ?string;

	/**
	 * Get a Skill by UUID (tenant-scoped), or null.
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return ObjectEntity|null The Skill object, or null.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-catalog/tasks.md#3-skillservice
	 */
	public function getSkill(string $skillId): ?ObjectEntity;

	/**
	 * Import an agentskills.io package into a new Skill object.
	 *
	 * @param string $package The agentskills.io package string.
	 * @param string $createdBy The importing user id.
	 * @param array $auxFiles Auxiliary `{name, content}` files travelling with the
	 *                        package. Unsafe paths are dropped by the serialiser
	 *                        rather than failing the import.
	 *
	 * @return ObjectEntity The persisted Skill object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-catalog/tasks.md#3-skillservice
	 * @spec openspec/changes/archive/2026-09-06-skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function importSkill(string $package, string $createdBy, array $auxFiles = []): ObjectEntity;

	/**
	 * Install a skill onto an agent — append the agent uuid to installedOn (idempotent),
	 * and keep the agent's own `skillInstalls` allowlist in sync (agent-capability-profile:
	 * a genuine bidirectional join, not a second source of truth — this is the ONLY write
	 * path for both directions).
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null The updated Skill object, or null when not found.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-catalog/tasks.md#3-skillservice
	 * @spec openspec/changes/archive/2026-09-06-agent-capability-profile/tasks.md#4-skillservice-bidirectional-install-join
	 */
	public function installOnAgent(string $skillId, string $agentId): ?ObjectEntity;

	/**
	 * List the skills visible in the caller's tenant.
	 *
	 * @return array<int, ObjectEntity> The Skill objects.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-catalog/tasks.md#3-skillservice
	 */
	public function listSkills(): array;

	/**
	 * The publish-time FILE SELECTION (skill-self-improvement / skills-marketplace
	 * delta): the exported/committed package ships `files['learnings.md']` (a skill's
	 * vetted experience travels with it — ADR-068 §3) but STRIPS
	 * `files['learning-candidates.md']` — unvetted observations never leave the
	 * instance. This is selection, not serialization: `SkillSerializer`'s
	 * byte-for-byte round-trip of the files it does emit is untouched. Both publish
	 * routes (GitHub primary AND the OpenConnector `publishToHub` secondary) export
	 * through this one selection.
	 *
	 * @param string $skillId The Skill UUID.
	 *
	 * @return array<int, array{name: string, content: string}>|null The selected
	 *                                                               files, or null when the skill is not found (tenant-scoped, 404 shape).
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function publishFileSelection(string $skillId): ?array;

	/**
	 * Stamp GitHub publish provenance onto a Skill — `githubOwner`/`githubRepo`/
	 * `publishedAt` only (hermiq-github-store). Provenance-only: never
	 * round-tripped through the exported agentskills.io package
	 * (`SkillSerializer::toPackage()` never emits these fields), mirroring
	 * `AgentTemplateService::update()`'s stamp in `AgentTemplateController::
	 * publishGithub()`.
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $owner The GitHub owner the skill was published to.
	 * @param string $repo The GitHub repository name.
	 * @param string $publishedAt ISO-8601 publish timestamp.
	 *
	 * @return ObjectEntity|null The updated Skill, or null when not found.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
	 */
	public function stampGithubPublish(string $skillId, string $owner, string $repo, string $publishedAt): ?ObjectEntity;

	/**
	 * Detach a skill from an agent — remove the agent uuid from installedOn (idempotent),
	 * and keep the agent's own `skillInstalls` allowlist in sync (the mirror of
	 * installOnAgent: a genuine bidirectional join, the ONLY write path for both
	 * directions).
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null The updated Skill object, or null when not found.
	 *
	 * @spec openspec/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
	 */
	public function uninstallFromAgent(string $skillId, string $agentId): ?ObjectEntity;

	/**
	 * Update a Skill from a client-supplied payload — the skill "merge" write path
	 * (skill-maturity): the incoming payload is guarded by
	 * `SkillMaturityService::preserveComputedFields()`, so client-supplied
	 * `maturityLevel` and `levelEvidence.l1`–`l4` + `l6` are silently overwritten by
	 * the STORED values (only qualify/attest write l1–l4; only the skill-learnings
	 * capture/promotion subsystem writes l6), while `targetLevel` and every
	 * ordinary field stay freely editable. Runs in the caller's session context, so
	 * OpenRegister's native RBAC still authorizes the write itself.
	 *
	 * @param string $skillId The Skill UUID.
	 * @param array<string, mixed> $data The client-supplied skill payload.
	 *
	 * @return ObjectEntity|null The updated Skill, or null when not found.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable
	 */
	public function updateSkill(string $skillId, array $data): ?ObjectEntity;
}//end interface
