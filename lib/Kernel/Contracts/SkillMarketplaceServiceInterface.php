<?php

/**
 * Contract of SkillMarketplaceService
 *
 * The public surface of SkillMarketplaceService that other components call, so they depend
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
 * Contract of SkillMarketplaceService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillMarketplaceServiceInterface {

	/**
	 * The review gate: transition a quarantined skill to active.
	 *
	 * A `dangerous` content-scan verdict blocks one-click approval — the skill stays
	 * quarantined and the caller is told to override explicitly (approveQuarantined with
	 * $force=true), so a reviewer cannot activate malicious content by reflex.
	 *
	 * @param string $skillId The Skill UUID.
	 * @param bool $force Override a `dangerous` scan verdict (a conscious reviewer decision).
	 *
	 * @return ObjectEntity|null The updated Skill, or null when not found.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$force` is a genuine two-mode
	 * reviewer decision (explicit dangerous-verdict override), part of the public
	 * seam the controller exposes — not an SRP smell.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#2-skillmarketplaceservice
	 */
	public function approveQuarantined(string $skillId, bool $force = false): ?ObjectEntity;

	/**
	 * Age-based lifecycle curation: active→stale→archived; NEVER hard-deletes.
	 *
	 * @return array<string, int> Summary counts { scanned, staled, archived }.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#2-skillmarketplaceservice
	 */
	public function curate(): array;

	/**
	 * Install a skill from an external source into QUARANTINE (never auto-active).
	 *
	 * @param string $package The agentskills.io package.
	 * @param string $source The source (`org`|`hub`).
	 * @param string $createdBy The installing user id.
	 * @param array $auxFiles Auxiliary `{name, content}` files travelling with the
	 *                        package. Their content is part of the scanned
	 *                        material; unsafe paths are dropped rather than
	 *                        failing the install.
	 * @param string $sourceUrl The canonical identity url this skill was fetched from.
	 *                          Empty for a hand-uploaded package, which stays a plain
	 *                          install because identity cannot be established.
	 * @param array|null $outcome Out-param: how this resolved (`installed`/`updated`/
	 *                            `unchanged`, whether local learnings were kept, and
	 *                            how an existing skill was matched).
	 *
	 * @return ObjectEntity The quarantined Skill object.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#2-skillmarketplaceservice
	 * @spec openspec/changes/archive/2026-09-06-skill-package-multifile/specs/skills-marketplace/spec.md#requirement-quarantine-security-scan-on-install
	 */
	public function installFromSource(
		string $package,
		string $source,
		string $createdBy,
		array $auxFiles = [],
		string $sourceUrl = '',
		?array &$outcome = null,
	): ObjectEntity;

	/**
	 * Publish a skill to an external hub via OpenConnector (no direct HTTP).
	 *
	 * @param string $skillId The Skill UUID.
	 * @param string $hubId The target hub identifier (an OpenConnector source/connector).
	 *
	 * @return array<string, mixed> The publish result or a structured error.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skills-marketplace/tasks.md#2-skillmarketplaceservice
	 */
	public function publishToHub(string $skillId, string $hubId): array;
}//end interface
