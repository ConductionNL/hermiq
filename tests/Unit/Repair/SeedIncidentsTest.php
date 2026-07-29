<?php

/**
 * Unit tests for the SeedIncidents repair step (agent-lifecycle-governance).
 *
 * Covers the graceful-degradation contract: a seed row is deferred (not fabricated)
 * when its named agent does not exist yet, and a re-run is idempotent (an
 * already-seeded incident, matched by description, is never duplicated).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedIncidents;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-lifecycle-governance SeedIncidents repair step.
 *
 * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
 */
class SeedIncidentsTest extends TestCase
{

    /**
     * A stateful ObjectService test double keyed by schema, capturing saveObject() calls.
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
             * @var array<int, array<string,mixed>>
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
                bool $_multitenancy=true,
                bool $silent=false,
                ?array $uploadedFiles=null,
                ?\OCP\IUser $currentUser=null
            ): ObjectEntity {
                $this->saved[] = (array) $object;
                $entity        = new ObjectEntity();
                $entity->setUuid('new-incident');
                $entity->setObject((array) $object);
                return $entity;
            }
        };

    }//end objectService()

    /**
     * An agent ObjectEntity with a name.
     *
     * @param string $uuid The agent uuid.
     * @param string $name The agent display name.
     *
     * @return ObjectEntity
     */
    private function agent(string $uuid, string $name): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid($uuid);
        $e->setObject(['name' => $name]);
        return $e;

    }//end agent()

    /**
     * A container resolving ObjectService.
     *
     * @param ObjectService $objectService The object service double.
     *
     * @return ContainerInterface
     */
    private function container(ObjectService $objectService): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $class) use ($objectService) {
                return match ($class) {
                    ObjectService::class => $objectService,
                    default => throw new \RuntimeException("Unexpected service: {$class}"),
                };
            }
        );
        return $container;

    }//end container()

    /**
     * With both named seed agents present and no pre-existing incidents, all 3
     * design.md incident rows are created, each linked to an existing agent.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
     */
    public function testSeedsAllThreeRowsWhenAgentsExist(): void
    {
        $objectService = $this->objectService(
            [
                'incident' => [],
                'agent'    => [
                    $this->agent('agent-briefing', 'Daily Briefing'),
                    $this->agent('agent-heavy', 'Heavy Tool Runner'),
                ],
            ]
        );

        $step = new SeedIncidents(
            container: $this->container($objectService),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $this->assertCount(3, $objectService->saved);
        foreach ($objectService->saved as $incident) {
            $this->assertNotEmpty($incident['linkedAgentId'], 'Every seeded incident must link to an existing agent.');
            $this->assertNotEmpty($incident['description']);
            $this->assertNotEmpty($incident['impact']);
            $this->assertNotEmpty($incident['actionsTaken']);
        }

    }//end testSeedsAllThreeRowsWhenAgentsExist()

    /**
     * When neither named agent exists yet, every row is deferred (never
     * fabricating a bogus linkedAgentId).
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
     */
    public function testDefersAllRowsWhenNoAgentsExist(): void
    {
        $objectService = $this->objectService(['incident' => [], 'agent' => []]);

        $step = new SeedIncidents(
            container: $this->container($objectService),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $this->assertCount(0, $objectService->saved);

    }//end testDefersAllRowsWhenNoAgentsExist()

    /**
     * A re-run (upgrade) does not duplicate an already-seeded incident — matched
     * by description.
     *
     * @return void
     *
     * @spec openspec/changes/agent-lifecycle-governance/tasks.md#task-1-declarative-schema-changes-incident-agent-tenantcontrol
     */
    public function testReRunIsIdempotent(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('existing-1');
        $existing->setObject(['description' => 'Agent posted a duplicate reply to the same Talk thread three times.']);

        $objectService = $this->objectService(
            [
                'incident' => [$existing],
                'agent'    => [
                    $this->agent('agent-briefing', 'Daily Briefing'),
                    $this->agent('agent-heavy', 'Heavy Tool Runner'),
                ],
            ]
        );

        $step = new SeedIncidents(
            container: $this->container($objectService),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run(output: $this->createMock(IOutput::class));

        // Only the 2 remaining (non-duplicate) rows are created.
        $this->assertCount(2, $objectService->saved);

    }//end testReRunIsIdempotent()
}//end class
