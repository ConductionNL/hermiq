<?php

/**
 * Unit tests for the SeedSkillCreator repair step (hermiq-skill-conversational-authoring).
 *
 * Covers the fresh-install seed (one `skill-creator` Skill, active/local, non-empty
 * frontmatter + body) and idempotency: a re-run does not duplicate an already-seeded skill
 * (matched by `name`), preserving an admin's edit. Mirrors SeedAgentTemplatesTest's
 * ObjectService test double exactly.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedSkillCreator;
use OCA\Hermiq\Service\SeedFreshnessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the hermiq-skill-conversational-authoring SeedSkillCreator repair step.
 *
 * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-seedskillcreator-repair-step-infoxml-registration
 */
class SeedSkillCreatorTest extends TestCase
{

    /**
     * A stateful ObjectService test double keyed by schema, recording every
     * saveObject() call (mirrors SeedAgentTemplatesTest's precedent).
     *
     * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
     *
     * @return ObjectService
     */
    private function objectService(array $bySchema): ObjectService
    {
        return new class ($bySchema) extends ObjectService {
            private ?string $schema = null;

            /**
             * @var array<int, array{schema: string, object: array}>
             */
            public array $saved = [];

            /**
             * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → objects.
             */
            public function __construct(private array $bySchema)
            {
            }

            public function setRegister(mixed $register): static
            {
                return $this;
            }

            public function setSchema(mixed $schema): static
            {
                $this->schema = (string) $schema;
                return $this;
            }

            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                return ($this->bySchema[$this->schema] ?? []);
            }

            public function saveObject(
                array | ObjectEntity $object,
                ?array $extend=[],
                mixed $register=null,
                mixed $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true
            ): ObjectEntity {
                $payload = is_array($object) ? $object : $object->getObject();
                $this->saved[] = ['schema' => (string) $schema, 'object' => $payload];

                $entity = new ObjectEntity();
                $entity->setUuid('new-'.count($this->saved));
                $entity->setObject($payload);
                return $entity;
            }
        };

    }//end objectService()

    /**
     * An object with the given payload.
     *
     * @param string               $uuid    The uuid.
     * @param array<string, mixed> $payload The payload.
     *
     * @return ObjectEntity
     */
    private function object(string $uuid, array $payload): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid($uuid);
        $e->setObject($payload);
        return $e;

    }//end object()

    /**
     * A container resolving ObjectService to the given double.
     *
     * @param ObjectService $objectService The object service double.
     *
     * @return ContainerInterface
     */
    private function container(ObjectService $objectService): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class) => match ($class) {
                ObjectService::class => $objectService,
                default => throw new RuntimeException("Unexpected service: {$class}"),
            }
        );

        return $container;

    }//end container()

    /**
     * A fresh install seeds exactly one skill-creator Skill, active/local, with
     * non-empty frontmatter + body.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-1
     */
    public function testFreshInstallSeedsSkillCreator(): void
    {
        $objectService = $this->objectService(['agentskill' => []]);

        $step = new SeedSkillCreator(
            container: $this->container(objectService: $objectService),
            logger: $this->createMock(LoggerInterface::class),
            freshness: new SeedFreshnessService(),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $this->assertCount(1, $objectService->saved);

        $seeded = $objectService->saved[0]['object'];
        $this->assertSame('agentskill', $objectService->saved[0]['schema']);
        $this->assertSame('skill-creator', $seeded['name']);
        $this->assertSame('active', $seeded['state']);
        // Seed freshness: creation stamps lastActivityAt so the Curator's staleness
        // clock starts at seed time (a missing value reads as "older than everything").
        $this->assertNotSame('', (string) ($seeded['lastActivityAt'] ?? ''));
        $this->assertSame('local', $seeded['source']);
        $this->assertSame('', $seeded['createdBy']);
        $this->assertSame([], $seeded['installedOn']);
        $this->assertSame([], $seeded['files']);
        $this->assertNotSame('', trim((string) $seeded['frontmatter']));
        $this->assertNotSame('', trim((string) $seeded['body']));
        $this->assertStringContainsString('skill-creator', $seeded['frontmatter']);
        $this->assertStringContainsString('Skill Creator', $seeded['body']);

    }//end testFreshInstallSeedsSkillCreator()

    /**
     * A re-run does not duplicate a HUMAN-owned skill-creator (matched by name) and
     * never touches it — a human-created skill's lifecycle belongs to its owner.
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-skill-conversational-authoring/tasks.md#task-1-1
     */
    public function testReRunIsIdempotentAndPreservesEdits(): void
    {
        $edited = $this->object(
            'existing-1',
            ['name' => 'skill-creator', 'body' => 'An admin edited this body.', 'state' => 'active', 'source' => 'local']
        );
        $edited->setOwner('alice');

        $objectService = $this->objectService(['agentskill' => [$edited]]);

        $step = new SeedSkillCreator(
            container: $this->container(objectService: $objectService),
            logger: $this->createMock(LoggerInterface::class),
            freshness: new SeedFreshnessService(),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $this->assertCount(0, $objectService->saved);

    }//end testReRunIsIdempotentAndPreservesEdits()

    /**
     * A re-run refreshes a stale `__system__`-owned seed back to active with a fresh
     * `lastActivityAt` — content untouched — so the Curator's age-staleness never
     * empties the seed catalog on a longer-lived instance.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function testReRunRefreshesStaleSystemSeedBackToActive(): void
    {
        $stale = $this->object(
            'existing-1',
            [
                'name'           => 'skill-creator',
                'body'           => 'Seeded body.',
                'state'          => 'stale',
                'source'         => 'local',
                'lastActivityAt' => '2025-01-01 00:00:00',
                'staleSince'     => '2025-06-01 00:00:00',
            ]
        );
        $stale->setOwner('__system__');

        $objectService = $this->objectService(['agentskill' => [$stale]]);

        $step = new SeedSkillCreator(
            container: $this->container(objectService: $objectService),
            logger: $this->createMock(LoggerInterface::class),
            freshness: new SeedFreshnessService(),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $this->assertCount(1, $objectService->saved);

        $saved = $objectService->saved[0]['object'];
        $this->assertSame('active', $saved['state']);
        $this->assertSame('Seeded body.', $saved['body']);
        $this->assertArrayNotHasKey('staleSince', $saved);
        $this->assertNotSame('2025-01-01 00:00:00', (string) ($saved['lastActivityAt'] ?? ''));
        $this->assertNotSame('', (string) ($saved['lastActivityAt'] ?? ''));

    }//end testReRunRefreshesStaleSystemSeedBackToActive()

    /**
     * A re-run NEVER touches an archived `__system__` seed — archiving is a curator
     * decision and it wins over seed freshness.
     *
     * @return void
     *
     * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
     */
    public function testReRunNeverTouchesArchivedSeed(): void
    {
        $archived = $this->object(
            'existing-1',
            ['name' => 'skill-creator', 'state' => 'archived', 'source' => 'local', 'archivedAt' => '2025-06-01 00:00:00']
        );
        $archived->setOwner('__system__');

        $objectService = $this->objectService(['agentskill' => [$archived]]);

        $step = new SeedSkillCreator(
            container: $this->container(objectService: $objectService),
            logger: $this->createMock(LoggerInterface::class),
            freshness: new SeedFreshnessService(),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $this->assertCount(0, $objectService->saved);

    }//end testReRunNeverTouchesArchivedSeed()

    /**
     * The step no-ops gracefully (never throws) when OpenRegister is not available.
     *
     * @return void
     */
    public function testNoopsWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

        $step = new SeedSkillCreator(
            container: $container,
            logger: $this->createMock(LoggerInterface::class),
            freshness: new SeedFreshnessService(),
        );

        $output = $this->createMock(IOutput::class);
        $output->expects($this->once())->method('warning');

        $step->run(output: $output);

        $this->addToAssertionCount(1);

    }//end testNoopsWhenOpenRegisterUnavailable()
}//end class
