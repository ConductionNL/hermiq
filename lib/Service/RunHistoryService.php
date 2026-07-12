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
            'id'          => $log->getUuid(),
            'scheduleId'  => $scheduleUuid,
            'status'      => ($context['status'] ?? null),
            'agentId'     => ($context['agentId'] ?? null),
            'startedAt'   => ($context['startedAt'] ?? null),
            'endedAt'     => ($context['endedAt'] ?? null),
            'durationMs'  => ($context['durationMs'] ?? null),
            'summary'     => ($context['summary'] ?? null),
            // Run-reliability: the attempt number within this occurrence's retry
            // sequence (1 = first attempt; absent on a pre-run-reliability entry).
            'attempt'     => ($context['attempt'] ?? null),
            'user'        => $log->getUser(),
            'created'     => $createdIso,
            'link'        => $this->buildScheduleLink(uuid: $scheduleUuid),
            'createdSort' => $createdSort,
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
