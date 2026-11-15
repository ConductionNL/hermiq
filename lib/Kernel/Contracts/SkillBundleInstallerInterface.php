<?php

/**
 * Contract of SkillBundleInstaller
 *
 * The public surface of SkillBundleInstaller that other components call, so they depend
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
 * Contract of SkillBundleInstaller.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillBundleInstallerInterface {

	/**
	 * Install every agent of an already-parsed bundle.
	 *
	 * An agent is a reasoning persona with real operational authority (tools,
	 * approval gates) — unlike a skill, this installer never OVERWRITES one that
	 * already exists by name: a live-tuned agent's prompt/tools are exactly the
	 * kind of hand edit a silent re-import must not clobber. An existing match
	 * is reported `unchanged`; only a genuinely new name is created.
	 *
	 * @param array<int,array<string,mixed>> $parsed The parsed bundle agents.
	 *
	 * @return array{outcomes:array<int,array<string,mixed>>,counts:array<string,int>}
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
	 */
	public function installAgents(array $parsed): array;

	/**
	 * Install every skill of an already-parsed bundle.
	 *
	 * Per-skill best effort: a skill that cannot be persisted is recorded as
	 * failed and the remaining skills are still installed.
	 *
	 * @param array<int,array<string,mixed>> $parsed The parsed bundle skills.
	 * @param string $createdBy The owning user id.
	 * @param string $owner Repo owner, for skill identity.
	 * @param string $repo Repo name, for skill identity.
	 *
	 * @return array{outcomes:array<int,array<string,mixed>>,counts:array<string,int>}
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
	 */
	public function installParsed(array $parsed, string $createdBy, string $owner = '', string $repo = ''): array;
}//end interface
