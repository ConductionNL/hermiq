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
use Psr\Log\LoggerInterface;
use Throwable;

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
     * Schema slug for agent objects (agent-capability-profile: installOnAgent() keeps
     * Agent.skillInstalls in sync with Skill.installedOn).
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService   OpenRegister object read/write (single write-path).
     * @param SkillSerializer $skillSerializer The agentskills.io (de)serialiser.
     * @param LoggerInterface $logger          Logger (best-effort agent-side sync warnings).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SkillSerializer $skillSerializer,
        private readonly LoggerInterface $logger,
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
     * @spec openspec/changes/skills-catalog/tasks.md#task-3-2
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
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

        $updated = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SKILL_SCHEMA,
            uuid: (string) $skill->getUuid()
        );

        $this->syncAgentSkillInstalls(agentId: $agentId, skillId: $skillId);

        return $updated;

    }//end installOnAgent()

    /**
     * Append a skill uuid to the target agent's `skillInstalls` (idempotent,
     * best-effort). A missing/unreadable agent does not fail the skill-side install —
     * `Skill.installedOn` remains the authoritative "installed somewhere" record;
     * `Agent.skillInstalls` is a convenience forward-ref for a future run loop.
     *
     * @param string $agentId The agent UUID.
     * @param string $skillId The Skill UUID to record.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-profile/tasks.md#task-4-1
     */
    private function syncAgentSkillInstalls(string $agentId, string $skillId): void
    {
        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
            if ($agent === null) {
                return;
            }

            $data      = $agent->getObject();
            $installed = ($data['skillInstalls'] ?? []);
            if (is_array($installed) === false) {
                $installed = [];
            }

            if (in_array($skillId, $installed, true) === true) {
                // Already in sync — no write needed.
                return;
            }

            $installed[]           = $skillId;
            $data['skillInstalls'] = array_values($installed);

            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                uuid: (string) $agent->getUuid()
            );
        } catch (Throwable $e) {
            // Best-effort: Skill.installedOn (already saved above) remains the
            // authoritative "installed somewhere" record regardless of this outcome.
            $this->logger->warning(
                sprintf(
                    'Hermiq could not sync skillInstalls onto agent %s for skill %s: %s',
                    $agentId,
                    $skillId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }//end try

    }//end syncAgentSkillInstalls()
}//end class
