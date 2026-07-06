<?php

/**
 * Hermiq AnalyticsService.
 *
 * The read-only run-analytics surface (run-analytics). It computes success rate, latency,
 * status breakdown and per-agent metrics directly from Hermiq's `action='run'`
 * OpenRegister AuditTrail entries — no separate analytics store, no ETL (ADR-004
 * governance, ADR-001 Option C+). Tenant scope is the caller's own schedules: the metrics
 * only ever aggregate run entries that belong to a schedule the caller may see, so no
 * cross-tenant run data leaks.
 *
 * LLM token usage is recorded per run when OpenRegister's ChatService reports it (run-cost
 * recording): ScheduleService copies the `usage` from the agent-run result into the run
 * audit entry, and this service sums it. When a run recorded no usage, tokens are reported
 * as unavailable rather than a fabricated zero. Tool-usage remains a follow-up. This is an
 * ADR-031 imperative read-surface service: it reads and shapes an audit list, owning no
 * schema, no write path, no store.
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
 * @spec openspec/changes/run-analytics/tasks.md#1-analyticsservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Computes run metrics from Hermiq's OpenRegister run audit entries (tenant-scoped).
 *
 * @spec openspec/changes/run-analytics/tasks.md#1-analyticsservice
 */
class AnalyticsService
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for schedule objects.
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * The audit action written per run by ScheduleService.
     *
     * @var string
     */
    private const RUN_ACTION = 'run';

    /**
     * The status value that counts as a successful run.
     *
     * @var string
     */
    private const STATUS_OK = 'ok';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object read (tenant-scoped schedule set).
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (run entries).
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AuditTrailMapper $auditTrailMapper,
    ) {
    }//end __construct()

    /**
     * Compute run analytics for the caller's tenant, optionally scoped to one agent.
     *
     * @param string|null $agentId Optional agent UUID to scope the metrics to.
     *
     * @return array<string, mixed> The metrics payload.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Single aggregation pass over the
     * audit-trail rows (status/usage/duration/agent buckets); splitting the pass would
     * iterate the same rows multiple times.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same single-pass aggregation rationale.
     *
     * @spec openspec/changes/run-analytics/tasks.md#task-1-3
     */
    public function computeAnalytics(?string $agentId=null): array
    {
        // Tenant boundary: the caller's own schedules (RBAC + multitenancy ON). The run
        // entries we aggregate are limited to these schedules, so another org's runs can
        // never be counted.
        $scheduleUuidToAgent = $this->loadScheduleUuidToAgent(agentId: $agentId);

        $totalRuns        = 0;
        $successRuns      = 0;
        $statusBreakdown  = [];
        $durations        = [];
        $perAgent         = [];
        $promptTokens     = 0;
        $completionTokens = 0;
        $tokensRecorded   = false;

        if ($scheduleUuidToAgent !== []) {
            $logs = $this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTION]);

            foreach ($logs as $log) {
                $objectUuid = (string) $log->getObjectUuid();
                if (isset($scheduleUuidToAgent[$objectUuid]) === false) {
                    continue;
                }

                $context  = ($log->getChanged() ?? []);
                $status   = (string) ($context['status'] ?? 'unknown');
                $runAgent = (string) ($context['agentId'] ?? $scheduleUuidToAgent[$objectUuid]);

                $totalRuns++;
                if ($status === self::STATUS_OK) {
                    $successRuns++;
                }

                $statusBreakdown[$status] = (($statusBreakdown[$status] ?? 0) + 1);

                if (isset($context['durationMs']) === true && is_numeric($context['durationMs']) === true) {
                    $durations[] = (int) $context['durationMs'];
                }

                // Accumulate LLM token usage when OpenRegister recorded it (run-cost).
                $usage = ($context['usage'] ?? null);
                if (is_array($usage) === true && (isset($usage['promptTokens']) === true || isset($usage['completionTokens']) === true)) {
                    $promptTokens     += (int) ($usage['promptTokens'] ?? 0);
                    $completionTokens += (int) ($usage['completionTokens'] ?? 0);
                    $tokensRecorded    = true;
                }

                if (isset($perAgent[$runAgent]) === false) {
                    $perAgent[$runAgent] = ['agentId' => $runAgent, 'runs' => 0, 'success' => 0];
                }

                $perAgent[$runAgent]['runs']++;
                if ($status === self::STATUS_OK) {
                    $perAgent[$runAgent]['success']++;
                }
            }//end foreach
        }//end if

        $scope = 'organisation';
        if ($agentId !== null && $agentId !== '') {
            $scope = 'agent';
        }

        return [
            'scope'           => $scope,
            'agentId'         => $agentId,
            'totalRuns'       => $totalRuns,
            'successRuns'     => $successRuns,
            'successRate'     => $this->rate(numerator: $successRuns, denominator: $totalRuns),
            'statusBreakdown' => $statusBreakdown,
            'latency'         => $this->latency(durations: $durations),
            'perAgent'        => array_values($perAgent),
            // LLM token usage from OpenRegister's ChatService (run-cost recording). When no
            // run recorded usage yet, availability is false rather than a fabricated zero.
            'tokens'          => [
                'available'  => $tokensRecorded,
                'prompt'     => $promptTokens,
                'completion' => $completionTokens,
                'total'      => ($promptTokens + $completionTokens),
            ],
        ];

    }//end computeAnalytics()

    /**
     * Load the caller's schedule UUIDs mapped to their agentId (tenant-scoped).
     *
     * @param string|null $agentId Optional agent UUID filter.
     *
     * @return array<string, string> Map of schedule UUID → agentId.
     */
    private function loadScheduleUuidToAgent(?string $agentId): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEDULE_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        $map = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data          = $object->getObject();
            $scheduleAgent = (string) ($data['agentId'] ?? '');

            if ($agentId !== null && $agentId !== '' && $scheduleAgent !== $agentId) {
                continue;
            }

            $map[(string) $object->getUuid()] = $scheduleAgent;
        }

        return $map;

    }//end loadScheduleUuidToAgent()

    /**
     * A percentage rate rounded to one decimal (0 when the denominator is 0).
     *
     * @param int $numerator   The numerator.
     * @param int $denominator The denominator.
     *
     * @return float The rate as a percentage.
     */
    private function rate(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0.0;
        }

        return round((($numerator / $denominator) * 100), 1);

    }//end rate()

    /**
     * Latency summary (avg/min/max ms) over the collected durations.
     *
     * @param array<int, int> $durations The per-run durations in ms.
     *
     * @return array<string, int|null> The latency summary.
     */
    private function latency(array $durations): array
    {
        if ($durations === []) {
            return ['avgMs' => null, 'minMs' => null, 'maxMs' => null];
        }

        return [
            'avgMs' => (int) round(array_sum($durations) / count($durations)),
            'minMs' => min($durations),
            'maxMs' => max($durations),
        ];

    }//end latency()
}//end class
