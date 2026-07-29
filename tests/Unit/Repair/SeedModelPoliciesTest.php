<?php

/**
 * Unit tests for the SeedModelPolicies repair step (tenant-model-policy).
 *
 * Pins the seed payload shapes: the instance default is seeded with a null
 * defaultModel, and the sample organisation policy's defaultModel is a
 * {provider, model} OBJECT (a bare model string used to be passed here, which
 * TenantModelPolicyService rejects on every boot with "defaultModel must be an
 * object with provider/model"). Also covers the graceful-degradation contract:
 * the sample org policy is deferred when no OpenRegister organisation exists,
 * and an existing policy is never overwritten.
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
 * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedModelPolicies;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the tenant-model-policy SeedModelPolicies repair step.
 *
 * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
 */
class SeedModelPoliciesTest extends TestCase
{
    /**
     * A container resolving TenantModelPolicyService/OrganisationMapper to the
     * given fixtures.
     *
     * @param TenantModelPolicyService $policyService The policy service double.
     * @param array<int, string>       $orgUuids      Organisation UUIDs findAll() returns.
     *
     * @return ContainerInterface
     */
    private function container(TenantModelPolicyService $policyService, array $orgUuids): ContainerInterface
    {
        $orgMapper = $this->createMock(originalClassName: OrganisationMapper::class);
        $orgs      = array_map(
            callback: function (string $uuid) {
                // Real entity, not a mock: the real Organisation resolves
                // getUuid() via Entity magic, unmockable under a server tree.
                $org = new Organisation();
                $org->setUuid($uuid);
                return $org;
            },
            array: $orgUuids
        );
        $orgMapper->method('findAll')->willReturn($orgs);

        $container = $this->createMock(originalClassName: ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            callback: function (string $class) use ($policyService, $orgMapper) {
                return match ($class) {
                    TenantModelPolicyService::class => $policyService,
                    OrganisationMapper::class => $orgMapper,
                    default => throw new \RuntimeException("Unexpected service: {$class}"),
                };
            }
        );

        return $container;

    }//end container()

    /**
     * With an organisation present and no existing policies, both seed rows are
     * created — and the sample org policy's defaultModel is a {provider, model}
     * object (never a bare model string, which the service rejects).
     *
     * @return void
     *
     * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
     */
    public function testSeedsBothPoliciesWithObjectShapedDefaultModel(): void
    {
        $policyService = $this->createMock(originalClassName: TenantModelPolicyService::class);
        $policyService->method('getForOrganisation')->willReturn(null);

        $upserted = [];
        $policyService->method('upsertForOrganisation')->willReturnCallback(
            callback: function (string $organisation, array $payload) use (&$upserted): array {
                $upserted[] = ['organisation' => $organisation, 'payload' => $payload];
                return $payload;
            }
        );

        $step = new SeedModelPolicies(
            container: $this->container(policyService: $policyService, orgUuids: ['org-a']),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
        );

        $step->run(output: $this->createMock(originalClassName: IOutput::class));

        $this->assertCount(2, $upserted, 'Instance default + sample org policy must both be created.');

        $this->assertSame('', $upserted[0]['organisation']);
        $this->assertNull($upserted[0]['payload']['defaultModel']);

        $this->assertSame('org-a', $upserted[1]['organisation']);
        $this->assertSame(
            ['provider' => 'ollama', 'model' => 'qwen2.5'],
            $upserted[1]['payload']['defaultModel'],
            'The sample defaultModel must be a {provider, model} object the service accepts.'
        );

    }//end testSeedsBothPoliciesWithObjectShapedDefaultModel()

    /**
     * With no OpenRegister organisation yet, only the instance default is seeded;
     * the sample org policy is deferred (never fabricated into a bogus tenant).
     *
     * @return void
     *
     * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
     */
    public function testDefersSampleOrgPolicyWhenNoOrganisationExists(): void
    {
        $policyService = $this->createMock(originalClassName: TenantModelPolicyService::class);
        $policyService->method('getForOrganisation')->willReturn(null);

        $organisations = [];
        $policyService->method('upsertForOrganisation')->willReturnCallback(
            callback: function (string $organisation, array $payload) use (&$organisations): array {
                $organisations[] = $organisation;
                return $payload;
            }
        );

        $step = new SeedModelPolicies(
            container: $this->container(policyService: $policyService, orgUuids: []),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
        );

        $step->run(output: $this->createMock(originalClassName: IOutput::class));

        $this->assertSame([''], $organisations, 'Only the instance default may be seeded.');

    }//end testDefersSampleOrgPolicyWhenNoOrganisationExists()

    /**
     * An existing policy is never overwritten — admin edits survive upgrades.
     *
     * @return void
     *
     * @spec openspec/changes/tenant-model-policy/tasks.md#task-9-seed-data
     */
    public function testNeverOverwritesAnExistingPolicy(): void
    {
        $policyService = $this->createMock(originalClassName: TenantModelPolicyService::class);
        $policyService->method('getForOrganisation')->willReturn(new ObjectEntity());
        $policyService->expects($this->never())->method('upsertForOrganisation');

        $step = new SeedModelPolicies(
            container: $this->container(policyService: $policyService, orgUuids: ['org-a']),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
        );

        $step->run(output: $this->createMock(originalClassName: IOutput::class));

    }//end testNeverOverwritesAnExistingPolicy()
}//end class
