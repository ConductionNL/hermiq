<?php

/**
 * Hermiq RunHistoryService.
 *
 * The read side of run-audit-log. Given a schedule UUID, it reads that Schedule
 * object's OpenRegister AuditTrail entries via AuditTrailMapper::findAll() filtered
 * by `object_uuid` (see getRunHistory() for WHY not ObjectService::getLogs()), keeps
 * the explicit per-run entries (action = 'run') written by ScheduleService, and maps
 * each into a compact run record (status, timing, agent, redacted summary, link)
 * newest-first. Hermiq owns no audit store — the trail, its hash chain and its
 * tenant scoping are all inherited from OpenRegister (ADR-004).
 *
 * This is a legitimate ADR-031 imperative read-surface service: it reads and
 * shapes an OpenRegister audit list. It owns no schema, no derived value, no
 * lifecycle.
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
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IURLGenerator;
use Throwable;

/**
 * Reads and shapes a schedule's OpenRegister run audit entries as run records.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */
class RunHistoryService
{

    /**
     * The audit action written per run by ScheduleService.
     *
     * @var string
     */
    public const RUN_ACTION = 'run';

    /**
     * Constructor.
     *
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (by object_uuid — see getRunHistory).
     * @param IURLGenerator    $urlGenerator     Builds the schedule deep link per run record.
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-1
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Return a schedule's run history newest-first as compact run records.
     *
     * Reads the Schedule object's `action='run'` audit entries, keeps them, sorts
     * newest-first, and applies offset/limit paging.
     *
     * WHY read by `object_uuid` via AuditTrailMapper::findAll() rather than
     * ObjectService::getLogs(): OpenRegister's `getLogs()` filters audit rows by the
     * object's INTEGER id (`GetObject::findLogs()` sets `$filters['object'] = getId()`),
     * but the app-writable `AuditTrailMapper::createAuditTrailEntry()` we use for the
     * per-run entry sets `object_uuid`/`register`/`schema` yet NEVER sets the integer
     * `object` column — so our run rows have `object = NULL` and `getLogs()` never
     * matches them (confirmed in the DB: auto create/update rows carry object=<id>, our
     * `run` row carries object=NULL). Reading by the string `object_uuid` (which
     * findAll() special-cases as a valid filter column) matches our entries reliably.
     * This is an upstream OR omission (createAuditTrailEntry should also setObject) —
     * see design.md Risks; the audit read is NOT tenant-filtered here, so the caller's
     * owner check (RunHistoryController) remains the security boundary.
     *
     * @param string $scheduleUuid The Schedule object UUID.
     * @param int    $limit        Max records to return.
     * @param int    $offset       Records to skip (paging).
     *
     * @return array<int, array<string, mixed>> The run records, newest-first.
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-1
     */
    public function getRunHistory(string $scheduleUuid, int $limit=20, int $offset=0): array
    {
        $logs = $this->auditTrailMapper->findAll(
            filters: [
                'object_uuid' => $scheduleUuid,
                'action'      => self::RUN_ACTION,
            ]
        );

        $runs = [];
        foreach ($logs as $log) {
            // Defensive: findAll() filters by action already, but guard anyway.
            if ($log->getAction() !== self::RUN_ACTION) {
                continue;
            }

            $runs[] = $this->toRunRecord(scheduleUuid: $scheduleUuid, log: $log);
        }

        // Newest-first by created timestamp (ms epoch key; missing → 0).
        usort(
            $runs,
            static function (array $a, array $b): int {
                return ($b['createdSort'] <=> $a['createdSort']);
            }
        );

        $paged = array_slice($runs, max(0, $offset), max(0, $limit));

        // Drop the internal sort key before returning.
        return array_map(
            static function (array $run): array {
                unset($run['createdSort']);
                return $run;
            },
            $paged
        );

    }//end getRunHistory()

    /**
     * Return one run's full, ordered step timeline (run-trace-observability).
     *
     * Reads the SAME `action='run'` entries `getRunHistory()` reads (by
     * `object_uuid`, never tenant-filtered here — the caller's owner check is the
     * security boundary, same as `getRunHistory()`), locates the entry whose UUID
     * matches `$runId`, and — when it is immediately preceded (no gap, no other
     * status in between) by one or more `awaiting_approval`/`skipped_killswitch`
     * entries for the SAME schedule — reconstructs a leading `gate_wait` step
     * spanning from the first such entry's `created` timestamp to this run's
     * actual `startedAt`. Never guesses a gate-wait across a gap or a different
     * schedule's entries.
     *
     * A `$runId` that does not belong to `$scheduleUuid` simply never appears in
     * the `object_uuid`-filtered result set, so it naturally returns null rather
     * than another schedule's run — no separate ownership check is needed here
     * (`RunHistoryController` still owns the caller-facing IDOR guard).
     *
     * @param string $scheduleUuid The Schedule object UUID.
     * @param string $runId        The run's AuditTrail entry UUID.
     *
     * @return array<string, mixed>|null The run's trace record, or null when not found.
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-runhistoryservicegetruntrace-trace-read-gate-wait-reconstruction
     */
    public function getRunTrace(string $scheduleUuid, string $runId): ?array
    {
        $logs = $this->auditTrailMapper->findAll(
            filters: [
                'object_uuid' => $scheduleUuid,
                'action'      => self::RUN_ACTION,
            ]
        );

        // Oldest-first, so a target's IMMEDIATELY PRECEDING entries are simply the
        // ones at index-1, index-2, ... in this same list.
        usort(
            $logs,
            static function (AuditTrail $a, AuditTrail $b): int {
                $aTime = ($a->getCreated()?->getTimestamp() ?? 0);
                $bTime = ($b->getCreated()?->getTimestamp() ?? 0);
                return ($aTime <=> $bTime);
            }
        );

        $targetIndex = null;
        foreach ($logs as $index => $log) {
            if ($log->getUuid() === $runId) {
                $targetIndex = $index;
                break;
            }
        }

        if ($targetIndex === null) {
            return null;
        }

        $target  = $logs[$targetIndex];
        $context = ($target->getChanged() ?? []);

        $steps = [];
        if (is_array($context['steps'] ?? null) === true) {
            $steps = array_values($context['steps']);
        }

        $gateWait = $this->reconstructGateWait(logs: $logs, targetIndex: $targetIndex, targetContext: $context);
        if ($gateWait !== null) {
            array_unshift($steps, $gateWait);
        }

        $steps = $this->renumberSteps(steps: $steps);

        $created    = $target->getCreated();
        $createdIso = null;
        if ($created !== null) {
            $createdIso = $created->format('c');
        }

        return [
            'id'                 => $target->getUuid(),
            'scheduleId'         => $scheduleUuid,
            'status'             => ($context['status'] ?? null),
            'agentId'            => ($context['agentId'] ?? null),
            'startedAt'          => ($context['startedAt'] ?? null),
            'endedAt'            => ($context['endedAt'] ?? null),
            'durationMs'         => ($context['durationMs'] ?? null),
            'toolStepsAvailable' => ($context['toolStepsAvailable'] ?? $this->hasToolStep(steps: $steps)),
            'steps'              => $steps,
            'summary'            => ($context['summary'] ?? null),
            'user'               => $target->getUser(),
            'created'            => $createdIso,
        ];

    }//end getRunTrace()

    /**
     * Reconstruct a leading `gate_wait` step from the run's immediately preceding,
     * unbroken run of `awaiting_approval`/`skipped_killswitch` entries.
     *
     * Walks backward from `$targetIndex` while each preceding entry's status is a
     * gate-skip status; stops (and keeps the earliest gate-skip index found so
     * far) at the first entry that is not one, or at the start of the list. No
     * step is synthesised when there is no such entry immediately before the run.
     *
     * @param array<int, AuditTrail> $logs          All of the schedule's run entries, oldest-first.
     * @param int                    $targetIndex   The target run's index in `$logs`.
     * @param array<string, mixed>   $targetContext The target run's `changed` context.
     *
     * @return array<string, mixed>|null The synthesised step, or null when none applies.
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    private function reconstructGateWait(array $logs, int $targetIndex, array $targetContext): ?array
    {
        $gateStatuses   = ['awaiting_approval', 'skipped_killswitch'];
        $firstGateIndex = null;

        for ($i = ($targetIndex - 1); $i >= 0; $i--) {
            $candidateContext = ($logs[$i]->getChanged() ?? []);
            $status           = ($candidateContext['status'] ?? null);
            if (in_array($status, $gateStatuses, true) === false) {
                break;
            }

            $firstGateIndex = $i;
        }

        if ($firstGateIndex === null) {
            return null;
        }

        $startedAt = $logs[$firstGateIndex]->getCreated();
        if ($startedAt === null) {
            return null;
        }

        $runStartedAtRaw = ($targetContext['startedAt'] ?? null);
        if (is_string($runStartedAtRaw) === false || $runStartedAtRaw === '') {
            return null;
        }

        try {
            $runStartedAt = new DateTimeImmutable($runStartedAtRaw);
        } catch (Exception $e) {
            return null;
        }

        // $startedAt is OpenRegister's \DateTime (never \DateTimeImmutable) — both
        // implement DateTimeInterface's format()/getTimestamp(), so no conversion
        // is needed here.
        $durationMs = (($runStartedAt->getTimestamp() - $startedAt->getTimestamp()) * 1000);
        if ($durationMs < 0) {
            $durationMs = 0;
        }

        return [
            'type'       => 'gate_wait',
            'name'       => 'Awaiting approval',
            'startedAt'  => $startedAt->format('c'),
            'endedAt'    => $runStartedAt->format('c'),
            'durationMs' => $durationMs,
            'outcome'    => 'approved',
        ];

    }//end reconstructGateWait()

    /**
     * Renumber a step list's `seq` fields 0..n-1, in array order.
     *
     * The write-time `seq` values (from `RunTraceCollector`/the coarse-step
     * builder) are only valid within that run's OWN written array; once a
     * reconstructed `gate_wait` step is unshifted onto the front, every step
     * must be renumbered to stay a contiguous 0..n-1 sequence.
     *
     * @param array<int, array<string, mixed>> $steps The steps to renumber.
     *
     * @return array<int, array<string, mixed>> The renumbered steps.
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    private function renumberSteps(array $steps): array
    {
        $seq = 0;
        return array_map(
            static function (array $step) use (&$seq): array {
                $step['seq'] = $seq++;
                return $step;
            },
            $steps
        );

    }//end renumberSteps()

    /**
     * Whether a step timeline includes any `tool`-type step (fallback for a
     * run written before `toolStepsAvailable` was persisted directly).
     *
     * @param array<int, array<string, mixed>> $steps The step timeline.
     *
     * @return bool True when at least one step has `type === 'tool'`.
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-4-1
     */
    private function hasToolStep(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'tool') {
                return true;
            }
        }

        return false;

    }//end hasToolStep()

    /**
     * Map one AuditTrail run entry into a compact run record.
     *
     * The redacted per-run context (agentId, status, timing, summary) lives in the
     * entry's `changed` payload, written by ScheduleService BEFORE persistence.
     *
     * @param string     $scheduleUuid The owning Schedule UUID.
     * @param AuditTrail $log          The run audit entry.
     *
     * @return array<string, mixed> The run record.
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-1
     * @spec openspec/changes/run-reliability/specs/run-audit-log/spec.md#requirement-run-history-surfaces-retry-attempts-and-dead-lettercircuit-breaker-outcomes-mvp
     */
    private function toRunRecord(string $scheduleUuid, AuditTrail $log): array
    {
        $context = ($log->getChanged() ?? []);
        $created = $log->getCreated();

        $createdIso  = null;
        $createdSort = 0;
        if ($created !== null) {
            $createdIso  = $created->format('c');
            $createdSort = (int) $created->format('U');
        }

        return [
            'id'           => $log->getUuid(),
            'scheduleId'   => $scheduleUuid,
            'status'       => ($context['status'] ?? null),
            'agentId'      => ($context['agentId'] ?? null),
            'startedAt'    => ($context['startedAt'] ?? null),
            'endedAt'      => ($context['endedAt'] ?? null),
            'durationMs'   => ($context['durationMs'] ?? null),
            'summary'      => ($context['summary'] ?? null),
            // Run-reliability: the attempt number within this occurrence's retry
            // sequence (1 = first attempt; absent on a pre-run-reliability entry).
            'attempt'      => ($context['attempt'] ?? null),
            // Agent-versioning: the agent version pinned at run start (null on a
            // pre-agent-versioning entry — never an error).
            'agentVersion' => ($context['agentVersion'] ?? null),
            'user'         => $log->getUser(),
            'created'      => $createdIso,
            'link'         => $this->buildScheduleLink(uuid: $scheduleUuid),
            'createdSort'  => $createdSort,
        ];

    }//end toRunRecord()

    /**
     * Build an absolute deep link to the schedule for a run record.
     *
     * @param string $uuid The schedule UUID.
     *
     * @return string The absolute URL.
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-1
     */
    private function buildScheduleLink(string $uuid): string
    {
        try {
            return $this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/schedules/'.$uuid);
        } catch (Throwable $e) {
            return '';
        }

    }//end buildScheduleLink()
}//end class
