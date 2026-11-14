<?php

/**
 * Contract of SkillVersionService
 *
 * The public surface of SkillVersionService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of SkillVersionService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillVersionServiceInterface {

	/**
	 * The current (newest) version identifier for a Skill — pinned onto drafts as
	 * `baseVersionId` and onto run audit entries. Never fatal: any failure
	 * (including "no versions found") returns null rather than propagating, so a
	 * version-pin lookup can never break a run (spec: "a pin failure is never
	 * fatal").
	 *
	 * @param string $skillUuid The Skill object UUID.
	 *
	 * @return string|null The newest version's AuditTrail entry UUID, or null.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-runs-pin-the-exact-skill-versions-that-executed
	 */
	public function currentVersionId(string $skillUuid): ?string;

	/**
	 * Diff two versions across the fixed `VERSIONED_FIELDS` set — `frontmatter`,
	 * `body`, `files` only; a `state`/provenance/maturity change never appears.
	 *
	 * @param string $skillUuid The Skill object UUID.
	 * @param string $fromId The "old" version's AuditTrail entry UUID.
	 * @param string $toId The "new" version's AuditTrail entry UUID.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> Only the versioned fields
	 *                                                      that differ between the two versions.
	 *
	 * @throws RuntimeException When either version id is unknown for this skill.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	public function diff(string $skillUuid, string $fromId, string $toId): array;

	/**
	 * List a Skill's version history, newest-first.
	 *
	 * @param string $skillUuid The Skill object UUID.
	 *
	 * @return array<int, array<string, mixed>> The versions (id, timestamp, user,
	 *                                          action, changedFields), newest-first.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	public function listVersions(string $skillUuid): array;

	/**
	 * Roll a Skill back to a previous version's content (`frontmatter`/`body`/
	 * `files`) as a brand-NEW version. Non-versioned fields (identity, lifecycle
	 * `state`, GitHub provenance, maturity/evidence, `installedOn`, …) keep their
	 * CURRENT values because the rollback payload starts from the live object and
	 * replaces ONLY the versioned fields; existing AuditTrail entries are only
	 * ever read.
	 *
	 * @param string $skillUuid The Skill object UUID.
	 * @param string $versionId The target version's AuditTrail entry UUID.
	 *
	 * @return ObjectEntity The updated skill (a new version, equal in content to
	 *                      the target for every versioned field).
	 *
	 * @throws RuntimeException When the version id is unknown, or the skill cannot
	 *                          be resolved.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
	 */
	public function rollback(string $skillUuid, string $versionId): ObjectEntity;
}//end interface
