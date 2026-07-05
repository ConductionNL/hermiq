<?php

/**
 * Hermiq SkillService.
 *
 * The Hermiq-owned management surface for the agent skills catalog (the agentskills.io
 * skills port). Imports/exports skills via SkillSerializer, lists them tenant-scoped, and
 * installs a skill onto an agent (an association on the skill's installedOn). All
 * persistence flows through OpenRegister ObjectService (single write-path, native tenant
 * scoping). The agent run loop that makes an installed skill available during a run is an
 * OpenRegister seam (ADR-001 Option C+), not implemented here.
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
 * @spec openspec/changes/skills-catalog/tasks.md#3-skillservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads and writes agent skills (Skill objects) via OpenRegister.
 *
 * @spec openspec/changes/skills-catalog/tasks.md#3-skillservice
 */
class SkillService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for skill objects (namespaced to avoid a cross-app slug collision).
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService   OpenRegister object read/write (single write-path).
     * @param SkillSerializer $skillSerializer The agentskills.io (de)serialiser.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SkillSerializer $skillSerializer,
    ) {
    }//end __construct()

    /**
     * Import an agentskills.io package into a new Skill object.
     *
     * @param string $package   The agentskills.io package string.
     * @param string $createdBy The importing user id.
     *
     * @return ObjectEntity The persisted Skill object.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-3-1
     */
    public function importSkill(string $package, string $createdBy): ObjectEntity
    {
        $parsed = $this->skillSerializer->fromPackage(package: $package);

        $name = $parsed['name'];
        if ($name === '') {
            $name = 'Untitled skill';
        }

        return $this->objectService->saveObject(
            object: [
                'name'        => $name,
                'description' => $parsed['description'],
                'frontmatter' => $parsed['frontmatter'],
                'body'        => $parsed['body'],
                'files'       => [],
                'state'       => 'active',
                'createdBy'   => $createdBy,
                'installedOn' => [],
            ],
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA
        );

    }//end importSkill()

    /**
     * Export a Skill back to an agentskills.io package string.
     *
     * @param string $skillId The Skill UUID.
     *
     * @return string|null The package string, or null when the skill is not found.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-3-1
     */
    public function exportSkill(string $skillId): ?string
    {
        $skill = $this->getSkill(skillId: $skillId);
        if ($skill === null) {
            return null;
        }

        return $this->skillSerializer->toPackage(skill: $skill->getObject());

    }//end exportSkill()

    /**
     * List the skills visible in the caller's tenant.
     *
     * @return array<int, ObjectEntity> The Skill objects.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-3-1
     */
    public function listSkills(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SKILL_SCHEMA)
            ->findAll(config: ['limit' => 200]);

        $out = [];
        foreach ($objects as $object) {
            if ($object instanceof ObjectEntity) {
                $out[] = $object;
            }
        }

        return $out;

    }//end listSkills()

    /**
     * Get a Skill by UUID (tenant-scoped), or null.
     *
     * @param string $skillId The Skill UUID.
     *
     * @return ObjectEntity|null The Skill object, or null.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-3-1
     */
    public function getSkill(string $skillId): ?ObjectEntity
    {
        // Fetch by UUID via find() (a uuid is metadata, not an object-property filter).
        return $this->objectService->find(
            id: $skillId,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA
        );

    }//end getSkill()

    /**
     * Install a skill onto an agent — append the agent uuid to installedOn (idempotent).
     *
     * @param string $skillId The Skill UUID.
     * @param string $agentId The agent UUID.
     *
     * @return ObjectEntity|null The updated Skill object, or null when not found.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-3-2
     */
    public function installOnAgent(string $skillId, string $agentId): ?ObjectEntity
    {
        $skill = $this->getSkill(skillId: $skillId);
        if ($skill === null) {
            return null;
        }

        $data      = $skill->getObject();
        $installed = ($data['installedOn'] ?? []);
        if (is_array($installed) === false) {
            $installed = [];
        }

        if (in_array($agentId, $installed, true) === false) {
            $installed[] = $agentId;
        }

        $data['installedOn'] = array_values($installed);

        return $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: (string) $skill->getUuid()
        );

    }//end installOnAgent()
}//end class
