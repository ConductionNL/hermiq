<?php

/**
 * Unit tests for the SeedComplianceControls repair step (compliance-control-packs).
 *
 * Covers the fresh-install seed (3 ControlFramework rows + their Control rows) and
 * idempotency: a re-run does not duplicate an already-seeded framework (matched by
 * `slug`) or control (matched by `frameworkSlug`+`controlId`).
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
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedComplianceControls;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the compliance-control-packs SeedComplianceControls repair step.
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
 */
class SeedComplianceControlsTest extends TestCase
{

    /**
     * A stateful ObjectService test double keyed by schema, recording every
     * saveObject() call (mirrors SeedBudgetsTest/SeedAiFeatures's precedent).
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
                bool $_multitenancy=true,
                bool $silent=false,
                ?array $uploadedFiles=null,
                ?\OCP\IUser $currentUser=null,
                // openregister#2211 (insert-only saves) added this. A double that
                // drifts from the real signature is a FATAL, not a failed
                // assertion: PHP refuses to declare the class and the whole
                // suite dies before it runs.
                bool $failIfExists=false
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
                default => throw new \RuntimeException("Unexpected service: {$class}"),
            }
        );

        return $container;

    }//end container()

    /**
     * A fresh install seeds all 3 ControlFramework rows and all 10 Control rows.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
     */
    public function testFreshInstallSeedsFrameworksAndControls(): void
    {
        $objectService = $this->objectService(['agentcontrolframework' => [], 'agentcompliancecontrol' => []]);

        $step = new SeedComplianceControls(
            container: $this->container(objectService: $objectService),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $frameworks = array_filter($objectService->saved, static fn (array $s) => $s['schema'] === 'agentcontrolframework');
        $controls   = array_filter($objectService->saved, static fn (array $s) => $s['schema'] === 'agentcompliancecontrol');

        $this->assertCount(3, $frameworks, 'EU AI Act, ISO/IEC 42001, and NIST AI RMF must all be seeded.');
        $this->assertCount(10, $controls, 'All 10 seeded controls must be created.');

        $slugs = array_map(static fn (array $s) => $s['object']['slug'], $frameworks);
        $this->assertSame(['eu-ai-act', 'iso-42001', 'nist-ai-rmf'], array_values($slugs));

    }//end testFreshInstallSeedsFrameworksAndControls()

    /**
     * A re-run does not duplicate an already-seeded framework (matched by slug) or
     * control (matched by frameworkSlug+controlId).
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-2-seed-the-catalogue-idempotently
     */
    public function testReRunIsIdempotent(): void
    {
        $existingFramework = $this->object('fw-eu', ['slug' => 'eu-ai-act', 'name' => 'EU AI Act']);
        $existingControl   = $this->object('ctrl-1', ['frameworkSlug' => 'eu-ai-act', 'controlId' => 'art.12']);

        $objectService = $this->objectService(
            [
                'agentcontrolframework'  => [$existingFramework],
                'agentcompliancecontrol' => [$existingControl],
            ]
        );

        $step = new SeedComplianceControls(
            container: $this->container(objectService: $objectService),
            logger: $this->createMock(LoggerInterface::class),
        );

        $step->run(output: $this->createMock(IOutput::class));

        $frameworks = array_filter($objectService->saved, static fn (array $s) => $s['schema'] === 'agentcontrolframework');
        $controls   = array_filter($objectService->saved, static fn (array $s) => $s['schema'] === 'agentcompliancecontrol');

        // The eu-ai-act framework and its art.12 control are skipped (already exist);
        // the remaining 2 frameworks and 9 controls are still created.
        $this->assertCount(2, $frameworks);
        $this->assertCount(9, $controls);

        $newFrameworkSlugs = array_map(static fn (array $s) => $s['object']['slug'], $frameworks);
        $this->assertNotContains('eu-ai-act', $newFrameworkSlugs);

        $newControlIds = array_map(static fn (array $s) => $s['object']['controlId'], $controls);
        $this->assertNotContains('art.12', $newControlIds);

    }//end testReRunIsIdempotent()
}//end class
