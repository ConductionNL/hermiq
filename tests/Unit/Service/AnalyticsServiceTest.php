<?php

/**
 * Unit tests for AnalyticsService (run-analytics).
 *
 * Covers the aggregation (success rate, status breakdown, latency, per-agent) over a fixed
 * set of run audit entries, and the tenant boundary: run entries belonging to a schedule
 * outside the caller's set are excluded.
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
 * @spec openspec/changes/run-analytics/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AnalyticsService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the run-analytics AnalyticsService.
 *
 * @spec openspec/changes/run-analytics/tasks.md#task-4-1
 */
class AnalyticsServiceTest extends TestCase
{

    /**
     * A schedule ObjectEntity with a uuid + agentId.
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
     * A run AuditTrail entry.
     *
     * @param string $objectUuid The owning schedule uuid.
     * @param string $status     The run status.
     * @param int    $durationMs The run duration in ms.
     * @param string $agentId    The agent uuid.
     *
     * @return AuditTrail
     */
    private function runEntry(string $objectUuid, string $status, int $durationMs, string $agentId): AuditTrail
    {
        $a = new AuditTrail();
        $a->setAction('run');
        $a->setObjectUuid($objectUuid);
        $a->setChanged(['status' => $status, 'durationMs' => $durationMs, 'agentId' => $agentId]);
        return $a;

    }//end runEntry()

    /**
     * Build the service with fixed schedules + run entries.
     *
     * @param array<int, ObjectEntity> $schedules The caller's schedules.
     * @param array<int, AuditTrail>   $runs      All run audit entries.
     *
     * @return AnalyticsService
     */
    private function service(array $schedules, array $runs): AnalyticsService
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturn($schedules);

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($runs);

        return new AnalyticsService($objectService, $mapper);

    }//end service()

    /**
     * Aggregation computes success rate, status breakdown, latency and per-agent counts,
     * and excludes runs outside the caller's schedule set.
     *
     * @return void
     *
     * @spec openspec/changes/run-analytics/tasks.md#task-1-3
     */
    public function testAggregatesAndScopesToCallersSchedules(): void
    {
        $schedules = [
            $this->schedule('s1', 'agentA'),
            $this->schedule('s2', 'agentB'),
        ];
        $runs = [
            $this->runEntry('s1', 'ok', 100, 'agentA'),
            $this->runEntry('s1', 'ok', 300, 'agentA'),
            $this->runEntry('s1', 'error', 200, 'agentA'),
            $this->runEntry('s2', 'ok', 400, 'agentB'),
            // Belongs to a schedule NOT in the caller's set — MUST be excluded.
            $this->runEntry('s3-foreign', 'ok', 999, 'agentX'),
        ];

        $m = $this->service($schedules, $runs)->computeAnalytics();

        $this->assertSame(4, $m['totalRuns']);
        $this->assertSame(3, $m['successRuns']);
        $this->assertSame(75.0, $m['successRate']);
        $this->assertSame(['ok' => 3, 'error' => 1], $m['statusBreakdown']);
        // Latency avg over 100,300,200,400 = 250.
        $this->assertSame(250, $m['latency']['avgMs']);
        $this->assertSame(100, $m['latency']['minMs']);
        $this->assertSame(400, $m['latency']['maxMs']);

        $perAgent = [];
        foreach ($m['perAgent'] as $row) {
            $perAgent[$row['agentId']] = $row;
        }

        $this->assertSame(3, $perAgent['agentA']['runs']);
        $this->assertSame(2, $perAgent['agentA']['success']);
        $this->assertSame(1, $perAgent['agentB']['runs']);
        $this->assertArrayNotHasKey('agentX', $perAgent);

        $this->assertFalse($m['cost']['available']);

    }//end testAggregatesAndScopesToCallersSchedules()

    /**
     * Scoping to one agent limits the schedule set (and thus the runs) to that agent.
     *
     * @return void
     *
     * @spec openspec/changes/run-analytics/tasks.md#task-1-1
     */
    public function testAgentScopeFiltersSchedules(): void
    {
        $schedules = [
            $this->schedule('s1', 'agentA'),
            $this->schedule('s2', 'agentB'),
        ];
        $runs = [
            $this->runEntry('s1', 'ok', 100, 'agentA'),
            $this->runEntry('s2', 'ok', 100, 'agentB'),
        ];

        $m = $this->service($schedules, $runs)->computeAnalytics(agentId: 'agentA');

        $this->assertSame('agent', $m['scope']);
        $this->assertSame('agentA', $m['agentId']);
        // Only s1 (agentA) is in scope → only its one run counts.
        $this->assertSame(1, $m['totalRuns']);

    }//end testAgentScopeFiltersSchedules()

    /**
     * No schedules → zeroed metrics, not an error.
     *
     * @return void
     *
     * @spec openspec/changes/run-analytics/tasks.md#task-1-3
     */
    public function testNoSchedulesYieldsZeroMetrics(): void
    {
        $m = $this->service([], [])->computeAnalytics();

        $this->assertSame(0, $m['totalRuns']);
        $this->assertSame(0.0, $m['successRate']);
        $this->assertNull($m['latency']['avgMs']);

    }//end testNoSchedulesYieldsZeroMetrics()
}//end class
