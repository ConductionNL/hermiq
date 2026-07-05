<?php

/**
 * Unit tests for TenantOpsService (multi-tenant-ops).
 *
 * Covers the quota math (schedule count, distinct agents-in-use, configured limits +
 * atLimit) and the AI Act audit export scoping (records come only from the caller's own
 * loaded objects).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\TenantOpsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the multi-tenant-ops TenantOpsService.
 *
 * @spec openspec/changes/multi-tenant-ops/tasks.md#task-4-1
 */
class TenantOpsServiceTest extends TestCase
{

    /**
     * A schedule ObjectEntity bound to an agent.
     *
     * @param string $uuid    The schedule uuid.
     * @param string $agentId The bound agent uuid.
     *
     * @return ObjectEntity
     */
    private function schedule(string $uuid, string $agentId): ObjectEntity
    {
        $e = new ObjectEntity();
        $e->setUuid($uuid);
        $e->setObject(['agentId' => $agentId]);
        return $e;

    }//end schedule()

    /**
     * An IAppConfig returning the given integer quota limits.
     *
     * @param int $scheduleQuota The schedule limit.
     * @param int $agentQuota    The agent limit.
     *
     * @return IAppConfig
     */
    private function appConfig(int $scheduleQuota, int $agentQuota): IAppConfig
    {
        $cfg = $this->createMock(IAppConfig::class);
        $cfg->method('getValueInt')->willReturnCallback(
            static function (string $app, string $key, int $default=0) use ($scheduleQuota, $agentQuota): int {
                if ($key === 'scheduleQuota') {
                    return $scheduleQuota;
                }
                if ($key === 'agentQuota') {
                    return $agentQuota;
                }
                return $default;
            }
        );
        return $cfg;

    }//end appConfig()

    /**
     * quotaStatus counts schedules, de-duplicates agents, and flags the limits.
     *
     * @return void
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-1-1
     */
    public function testQuotaStatus(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturn(
            [
                $this->schedule('s1', 'agentA'),
                $this->schedule('s2', 'agentA'),
                $this->schedule('s3', 'agentB'),
            ]
        );

        $service = new TenantOpsService(
            $objectService,
            $this->createMock(AuditTrailMapper::class),
            $this->appConfig(scheduleQuota: 3, agentQuota: 50)
        );

        $q = $service->quotaStatus();

        $this->assertSame(3, $q['schedules']['count']);
        $this->assertSame(3, $q['schedules']['limit']);
        $this->assertTrue($q['schedules']['atLimit']);

        // agentA + agentB = 2 distinct agents in use.
        $this->assertSame(2, $q['agents']['count']);
        $this->assertSame(50, $q['agents']['limit']);
        $this->assertFalse($q['agents']['atLimit']);

    }//end testQuotaStatus()

    /**
     * The audit export records come only from the caller's own loaded objects.
     *
     * @return void
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-1-2
     */
    public function testAuditExportScopedToCallerObjects(): void
    {
        // The service loads schedules then approvals; return one object each.
        $schemaState   = ['schema' => ''];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnCallback(
            static function (string $schema) use (&$schemaState, $objectService) {
                $schemaState['schema'] = $schema;
                return $objectService;
            }
        );
        $objectService->method('findAll')->willReturnCallback(
            function () use (&$schemaState): array {
                if ($schemaState['schema'] === 'schedule') {
                    $s = new ObjectEntity();
                    $s->setUuid('sched-1');
                    $s->setObject(['agentId' => 'agentA']);
                    return [$s];
                }
                $a = new ObjectEntity();
                $a->setUuid('appr-1');
                $a->setObject([]);
                return [$a];
            }
        );

        $entry = new AuditTrail();
        $entry->setAction('run');
        $entry->setUser('alice');
        $entry->setChanged(['status' => 'ok']);

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn([$entry]);

        $service = new TenantOpsService($objectService, $mapper, $this->appConfig(100, 50));

        $export = $service->exportAuditTrail();

        $this->assertSame('eu-ai-act-audit', $export['export']);
        // One schedule + one approval, each with one audit entry → 2 records.
        $this->assertSame(2, $export['recordCount']);
        $types = array_column($export['records'], 'objectType');
        $this->assertContains('schedule', $types);
        $this->assertContains('approval', $types);

    }//end testAuditExportScopedToCallerObjects()
}//end class
