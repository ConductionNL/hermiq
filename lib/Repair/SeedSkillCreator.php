<?php

/**
 * Hermiq Seed Skill Creator Repair Step.
 *
 * Idempotently seeds ONE `skill-creator` agentskills.io Skill (hermiq-skill-conversational-authoring)
 * on install/upgrade: a SKILL.md whose body teaches an agent how to interview a user and
 * draft a well-formed agentskills.io package. Written through OpenRegister's ObjectService
 * single write-path (ADR-001/ADR-004), system-context (`_rbac: false, _multitenancy: false`)
 * exactly like `SeedAgentTemplates`, matched by its seeded `name` so a re-run never
 * duplicates it or overwrites an admin's edit.
 *
 * The seed writes DIRECTLY via ObjectService (never through
 * `SkillMarketplaceService::installFromSource`): it is first-party trusted content, must
 * land `active` immediately, and must never be content-scanned or quarantined.
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-seedskillcreator-repair-step-infoxml-registration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed the `skill-creator` Skill object via ObjectService (idempotent, by name).
 *
 * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-seedskillcreator-repair-step-infoxml-registration
 */
class SeedSkillCreator implements IRepairStep
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for Skill objects (namespaced to avoid a cross-app slug collision).
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * The seeded skill's name (also the idempotency key).
     *
     * @var string
     */
    private const SKILL_NAME = 'skill-creator';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container Server container for lazy ObjectService resolution
     *                                      (OpenRegister may not be installed yet).
     * @param LoggerInterface    $logger    PSR-3 logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-seedskillcreator-repair-step-infoxml-registration
     */
    public function getName(): string
    {
        return 'Seed skill-creator skill (hermiq-skill-conversational-authoring)';

    }//end getName()

    /**
     * Seed the `skill-creator` Skill if it does not already exist (matched by name); an
     * existing seeded skill — including one an admin has since edited — is left untouched.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-seedskillcreator-repair-step-infoxml-registration
     */
    public function run(IOutput $output): void
    {
        try {
            $objectService = $this->container->get(ObjectService::class);
        } catch (Throwable $e) {
            $output->warning('OpenRegister not available — skipping skill-creator seed.');
            $this->logger->warning('[hermiq] skill-creator seed skipped: '.$e->getMessage());
            return;
        }

        try {
            if ($this->nameExists(objectService: $objectService) === true) {
                $output->info('skill-creator seed already present — skipped.');
                return;
            }

            // Explicit — never rely on the JSON-schema `default` being applied by whatever
            // OpenRegister/ObjectService version is running; a seed step must be correct on
            // its own. Written DIRECTLY (never via installFromSource): first-party trusted
            // content, never scanned/quarantined.
            $objectService->saveObject(
                object: [
                    'name'             => self::SKILL_NAME,
                    'description'      => 'Guides you through authoring a new agent skill in the agentskills.io '
                        .'format — interviews you about the capability, then drafts a clean SKILL.md (frontmatter '
                        .'+ body) you can save to your catalog.',
                    'frontmatter'      => $this->seedFrontmatter(),
                    'body'             => $this->seedBody(),
                    'files'            => [],
                    'state'            => 'active',
                    'source'           => 'local',
                    'quarantineReason' => null,
                    'scanReport'       => null,
                    'createdBy'        => '',
                    'installedOn'      => [],
                ],
                register: self::REGISTER_SLUG,
                schema: self::SKILL_SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
            $output->info('skill-creator seed complete.');
        } catch (Throwable $e) {
            $output->warning('Could not seed skill-creator skill: '.$e->getMessage());
            $this->logger->error('[hermiq] skill-creator seed failed: '.$e->getMessage());
        }//end try

    }//end run()

    /**
     * The seeded skill's raw agentskills.io YAML frontmatter block (stored verbatim — the
     * `SkillSerializer` round-trip preserves it byte-for-byte).
     *
     * @return string
     */
    private function seedFrontmatter(): string
    {
        return <<<'YAML'
        name: skill-creator
        description: Guides you through authoring a new agent skill — interviews you, then drafts a clean SKILL.md.
        version: 0.1.0
        YAML;

    }//end seedFrontmatter()

    /**
     * The seeded skill's SKILL.md body — a real, sensible skill-authoring instruction; safe
     * placeholders only, no shell/exfiltration example patterns.
     *
     * @return string
     */
    private function seedBody(): string
    {
        return <<<'MARKDOWN'
        # Skill Creator

        You help the user author a new **agent skill** in the agentskills.io format. A skill is a
        Markdown document (SKILL.md) with a YAML frontmatter header (`name`, `description`, optional
        `version`) followed by a body that tells an agent HOW to perform one capability well.

        ## How to work with the user

        1. Ask what single capability the new skill should give an agent. Keep it to ONE clear job.
        2. Ask for the trigger: when should an agent reach for this skill? Capture a one-line
           description — it becomes the frontmatter `description` and drives discovery.
        3. Draft the body: a short title, a "How to work" section with numbered steps, any rules or
           guardrails ("never fabricate a figure"), and one worked example using safe placeholders
           (e.g. `YOUR_VALUE_HERE`) — never real secrets, tokens, or personal data.
        4. Show the user the full SKILL.md (frontmatter fence + body) and ask them to confirm or
           refine. Iterate until they are happy.

        ## Output format

        Emit the finished skill as a fenced agentskills.io package:

        ```
        ---
        name: <kebab-case-name>
        description: <one line — what the skill does and when to use it>
        version: 0.1.0
        ---
        # <Title>

        <body: how-to steps, rules, one safe example>
        ```

        ## Rules

        - One capability per skill. If the user describes two jobs, propose two skills.
        - The `description` MUST be specific enough that an agent knows when to trigger the skill.
        - Never include a real credential, token, or personal datum in the body — use placeholders.
        - Keep the body focused and skimmable; an agent reads it as instructions, not prose.
        MARKDOWN;

    }//end seedBody()

    /**
     * Whether a Skill with the seeded name already exists (system context, no RBAC).
     *
     * @param ObjectService $objectService The OpenRegister object service.
     *
     * @return bool True when a matching object exists.
     */
    private function nameExists(ObjectService $objectService): bool
    {
        $objects = $objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SKILL_SCHEMA)
            ->findAll(
                config: ['filters' => ['name' => self::SKILL_NAME], 'limit' => 50],
                _rbac: false,
                _multitenancy: false
            );

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($object->getObject()['name'] ?? '') === self::SKILL_NAME) {
                return true;
            }
        }

        return false;

    }//end nameExists()
}//end class
