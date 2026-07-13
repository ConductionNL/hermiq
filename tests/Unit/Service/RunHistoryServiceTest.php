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

    /**
     * run-reliability: a dead-lettered occurrence's full retry sequence (one
     * failure + two retries) is visible newest-first, each with its own attempt
     * number, and the last entry shows status=dead_letter.
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp
     */
    public function testRetrySequenceAttemptsAreVisibleNewestFirst(): void
    {
        $logs = [
            $this->withAttempt(
                $this->entry('run', ['status' => 'retry_pending'], '2026-01-01T10:00:00+00:00', 'attempt-1'),
                1
            ),
            $this->withAttempt(
                $this->entry('run', ['status' => 'retry_pending'], '2026-01-01T10:01:00+00:00', 'attempt-2'),
                2
            ),
            $this->withAttempt(
                $this->entry('run', ['status' => 'dead_letter'], '2026-01-01T10:02:00+00:00', 'attempt-3'),
                3
            ),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturn('https://nc/x');

        $service = new RunHistoryService($mapper, $url);
        $runs    = $service->getRunHistory('sched-1');

        $this->assertCount(3, $runs);
        $this->assertSame('dead_letter', $runs[0]['status'], 'The newest entry must be the dead-letter outcome.');
        $this->assertSame(3, $runs[0]['attempt']);
        $this->assertSame(2, $runs[1]['attempt']);
        $this->assertSame(1, $runs[2]['attempt']);

    }//end testRetrySequenceAttemptsAreVisibleNewestFirst()

    /**
     * A pre-run-reliability audit entry with no `attempt` key surfaces as null
     * (backward-compatible — never a fatal missing-key error).
     *
     * @return void
     *
     * @spec openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp
     */
    public function testMissingAttemptSurfacesAsNull(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok'], '2026-01-01T10:00:00+00:00', 'legacy-run'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturn('https://nc/x');

        $service = new RunHistoryService($mapper, $url);
        $runs    = $service->getRunHistory('sched-1');

        $this->assertNull($runs[0]['attempt']);

    }//end testMissingAttemptSurfacesAsNull()

    /**
     * agent-versioning: a run record surfaces its pinned agent version when
     * the audit entry carries one.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-run-history-surfaces-the-pinned-agent-version
     */
    public function testRunRecordSurfacesPinnedAgentVersion(): void
    {
        $logs = [
            $this->entry(
                'run',
                ['status' => 'ok', 'agentId' => 'a1', 'agentVersion' => 'version-uuid-1'],
                '2026-01-01T10:00:00+00:00',
                'run-1'
            ),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturn('https://nc/x');

        $service = new RunHistoryService($mapper, $url);
        $runs    = $service->getRunHistory('sched-1');

        $this->assertSame('version-uuid-1', $runs[0]['agentVersion']);

    }//end testRunRecordSurfacesPinnedAgentVersion()

    /**
     * agent-versioning: a pre-existing run with no pinned agentVersion
     * degrades gracefully to null rather than causing an error.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-run-history-surfaces-the-pinned-agent-version
     */
    public function testRunRecordWithoutPinnedAgentVersionIsNull(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok', 'agentId' => 'a1'], '2026-01-01T10:00:00+00:00', 'legacy-run'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturn('https://nc/x');

        $service = new RunHistoryService($mapper, $url);
        $runs    = $service->getRunHistory('sched-1');

        $this->assertNull($runs[0]['agentVersion']);

    }//end testRunRecordWithoutPinnedAgentVersionIsNull()

    /**
     * Merge an `attempt` number into an existing entry's context (test helper).
     *
     * @param AuditTrail $entry   The base entry.
     * @param int        $attempt The attempt number to add.
     *
     * @return AuditTrail The entry with `attempt` merged into its context.
     */
    private function withAttempt(AuditTrail $entry, int $attempt): AuditTrail
    {
        $context            = ($entry->getChanged() ?? []);
        $context['attempt'] = $attempt;
        $entry->setChanged($context);
        return $entry;

    }//end withAttempt()

    /**
     * getRunTrace() returns the target run's persisted step timeline verbatim
     * (run-trace-observability), including the persisted `toolStepsAvailable` flag.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    public function testGetRunTraceReturnsPersistedSteps(): void
    {
        $steps = [
            ['seq' => 0, 'type' => 'context', 'name' => 'Context retrieval', 'startedAt' => '2026-01-01T10:00:00+00:00', 'endedAt' => '2026-01-01T10:00:00+00:00', 'durationMs' => 100, 'outcome' => 'ok'],
            ['seq' => 1, 'type' => 'tool', 'name' => 'openregister.searchObjects', 'startedAt' => '2026-01-01T10:00:00+00:00', 'endedAt' => '2026-01-01T10:00:01+00:00', 'durationMs' => 900, 'outcome' => 'ok'],
        ];

        $logs = [
            $this->entry(
                'run',
                [
                    'status'             => 'ok',
                    'agentId'            => 'a1',
                    'startedAt'          => '2026-01-01T10:00:00+00:00',
                    'steps'              => $steps,
                    'toolStepsAvailable' => true,
                ],
                '2026-01-01T10:00:02+00:00',
                'run-1'
            ),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);

        $service = new RunHistoryService($mapper, $url);
        $trace   = $service->getRunTrace('sched-1', 'run-1');

        $this->assertNotNull($trace);
        $this->assertSame('run-1', $trace['id']);
        $this->assertSame('sched-1', $trace['scheduleId']);
        $this->assertTrue($trace['toolStepsAvailable']);
        $this->assertSame(['context', 'tool'], array_column($trace['steps'], 'type'));
        $this->assertSame([0, 1], array_column($trace['steps'], 'seq'));

    }//end testGetRunTraceReturnsPersistedSteps()

    /**
     * A run id that does not belong to the given schedule returns null — never
     * another schedule's run.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    public function testGetRunTraceReturnsNullForUnknownRunId(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok', 'startedAt' => '2026-01-01T10:00:00+00:00'], '2026-01-01T10:00:02+00:00', 'run-1'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);

        $service = new RunHistoryService($mapper, $url);

        $this->assertNull($service->getRunTrace('sched-1', 'does-not-exist'));

    }//end testGetRunTraceReturnsNullForUnknownRunId()

    /**
     * A run immediately preceded by an unbroken run of `awaiting_approval`
     * entries gets a reconstructed leading `gate_wait` step spanning from the
     * first such entry's timestamp to the run's actual `startedAt`.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    public function testGetRunTraceReconstructsGateWaitFromAdjacentSkips(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'awaiting_approval'], '2026-01-01T09:00:00+00:00', 'skip-1'),
            $this->entry('run', ['status' => 'awaiting_approval'], '2026-01-01T09:30:00+00:00', 'skip-2'),
            $this->entry(
                'run',
                [
                    'status'    => 'ok',
                    'startedAt' => '2026-01-01T10:00:00+00:00',
                    'steps'     => [['seq' => 0, 'type' => 'llm', 'name' => 'LLM generation', 'startedAt' => '2026-01-01T10:00:00+00:00', 'endedAt' => '2026-01-01T10:00:01+00:00', 'durationMs' => 1000, 'outcome' => 'ok']],
                ],
                '2026-01-01T10:00:01+00:00',
                'run-1'
            ),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);

        $service = new RunHistoryService($mapper, $url);
        $trace   = $service->getRunTrace('sched-1', 'run-1');

        $this->assertNotNull($trace);
        $this->assertSame(['gate_wait', 'llm'], array_column($trace['steps'], 'type'));
        $this->assertSame([0, 1], array_column($trace['steps'], 'seq'), 'The full list must be renumbered once gate_wait is prepended.');

        $gateWait = $trace['steps'][0];
        $this->assertSame('2026-01-01T09:00:00+00:00', $gateWait['startedAt'], 'Must span from the FIRST (earliest) gate-skip entry.');
        $this->assertSame('2026-01-01T10:00:00+00:00', $gateWait['endedAt'], "Must end at the run's actual startedAt, not the audit write time.");
        $this->assertSame('approved', $gateWait['outcome']);

    }//end testGetRunTraceReconstructsGateWaitFromAdjacentSkips()

    /**
     * A run with NO adjacent gate-skip entry immediately before it gets no
     * `gate_wait` step — never guessed across a gap or a different status.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    public function testGetRunTraceOmitsGateWaitWithoutAdjacentSkip(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok'], '2026-01-01T08:00:00+00:00', 'earlier-ok-run'),
            $this->entry(
                'run',
                [
                    'status'    => 'ok',
                    'startedAt' => '2026-01-01T10:00:00+00:00',
                    'steps'     => [['seq' => 0, 'type' => 'llm', 'name' => 'LLM generation', 'startedAt' => '2026-01-01T10:00:00+00:00', 'endedAt' => '2026-01-01T10:00:01+00:00', 'durationMs' => 1000, 'outcome' => 'ok']],
                ],
                '2026-01-01T10:00:01+00:00',
                'run-1'
            ),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);

        $service = new RunHistoryService($mapper, $url);
        $trace   = $service->getRunTrace('sched-1', 'run-1');

        $this->assertNotNull($trace);
        $this->assertSame(['llm'], array_column($trace['steps'], 'type'));

    }//end testGetRunTraceOmitsGateWaitWithoutAdjacentSkip()

    /**
     * A run with no persisted `toolStepsAvailable` (pre-run-trace-observability
     * entry) falls back to deriving it from the steps actually present.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    public function testGetRunTraceDerivesToolStepsAvailableWhenNotPersisted(): void
    {
        $logs = [
            $this->entry(
                'run',
                [
                    'status'    => 'ok',
                    'startedAt' => '2026-01-01T10:00:00+00:00',
                    'steps'     => [['seq' => 0, 'type' => 'tool', 'name' => 'a.tool', 'startedAt' => '2026-01-01T10:00:00+00:00', 'endedAt' => '2026-01-01T10:00:01+00:00', 'durationMs' => 1000, 'outcome' => 'ok']],
                ],
                '2026-01-01T10:00:01+00:00',
                'run-1'
            ),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);

        $service = new RunHistoryService($mapper, $url);
        $trace   = $service->getRunTrace('sched-1', 'run-1');

        $this->assertTrue($trace['toolStepsAvailable']);

    }//end testGetRunTraceDerivesToolStepsAvailableWhenNotPersisted()

    /**
     * A run with no `steps` key at all (pre-run-trace-observability entry)
     * returns an empty step list, never a fatal error.
     *
     * @return void
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    public function testGetRunTraceHandlesMissingStepsGracefully(): void
    {
        $logs = [
            $this->entry('run', ['status' => 'ok', 'startedAt' => '2026-01-01T10:00:00+00:00'], '2026-01-01T10:00:01+00:00', 'run-1'),
        ];

        $mapper = $this->createMock(AuditTrailMapper::class);
        $mapper->method('findAll')->willReturn($logs);
        $url = $this->createMock(IURLGenerator::class);

        $service = new RunHistoryService($mapper, $url);
        $trace   = $service->getRunTrace('sched-1', 'run-1');

        $this->assertSame([], $trace['steps']);
        $this->assertFalse($trace['toolStepsAvailable']);

    }//end testGetRunTraceHandlesMissingStepsGracefully()
}//end class
