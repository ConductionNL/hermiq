<?php

/**
 * Unit tests for RunHistoryService (run-audit-log).
 *
 * Verifies that a schedule's OpenRegister audit entries are read via getLogs(),
 * filtered to the explicit per-run action, mapped into compact run records, and
 * returned newest-first with paging.
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
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\RunHistoryService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the run-audit-log RunHistoryService.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */
class RunHistoryServiceTest extends TestCase
{

    /**
     * Build an AuditTrail entry.
     *
     * @param string              $action  The audit action.
     * @param array<string,mixed> $context The changed/context payload.
     * @param string              $created A parseable created timestamp.
     * @param string              $uuid    The entry UUID.
     *
     * @return AuditTrail
     */
    private function entry(string $action, array $context, string $created, string $uuid): AuditTrail
    {
        $entry = new AuditTrail();
        $entry->setUuid($uuid);
        $entry->setAction($action);
        $entry->setUser('alice');
        $entry->setChanged($context);
        $entry->setCreated(new \DateTime($created));
        return $entry;

    }//end entry()

    /**
     * getRunHistory keeps only run entries, newest-first, mapping the context.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testReturnsRunRecordsNewestFirst(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok', 'agentId' => 'a1'], '2026-01-01T10:00:00+00:00', 'run-old'),
            $this->entry('update', ['foo' => 'bar'], '2026-01-01T11:00:00+00:00', 'upd-1'),
            $this->entry('run', ['status' => 'error', 'agentId' => 'a1'], '2026-01-01T12:00:00+00:00', 'run-new'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturn('https://nc/index.php/apps/hermiq/schedules/sched-1');

        $service = new RunHistoryService($mapper, $url);
        $runs    = $service->getRunHistory('sched-1');

        $this->assertCount(2, $runs, 'Only run entries are returned (the update is filtered out).');
        $this->assertSame('run-new', $runs[0]['id'], 'Newest run must come first.');
        $this->assertSame('error', $runs[0]['status']);
        $this->assertSame('run-old', $runs[1]['id']);
        $this->assertSame('ok', $runs[1]['status']);
        $this->assertSame('sched-1', $runs[0]['scheduleId']);
        $this->assertArrayNotHasKey('createdSort', $runs[0], 'Internal sort key must not leak.');

    }//end testReturnsRunRecordsNewestFirst()

    /**
     * Paging applies offset and limit after the newest-first sort.
     *
     * @return void
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-4
     */
    public function testPagingAppliesOffsetAndLimit(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok'], '2026-01-01T10:00:00+00:00', 'r1'),
            $this->entry('run', ['status' => 'ok'], '2026-01-01T11:00:00+00:00', 'r2'),
            $this->entry('run', ['status' => 'ok'], '2026-01-01T12:00:00+00:00', 'r3'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturn('https://nc/x');

        $service = new RunHistoryService($mapper, $url);
        $runs    = $service->getRunHistory('sched-1', 1, 1);

        $this->assertCount(1, $runs, 'Limit 1 must return a single record.');
        $this->assertSame('r2', $runs[0]['id'], 'Offset 1 into newest-first (r3,r2,r1) is r2.');

    }//end testPagingAppliesOffsetAndLimit()
}//end class
