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
     * Agent schema slug — read only to label the `perAgent` aggregate.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

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

        // Display names for the `perAgent` aggregate (see loadAgentNames()).
        $agentNames = $this->loadAgentNames();

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

                $context = ($log->getChanged() ?? []);

                // Run-replay-and-dry-run: a dry-run/replay preview must never inflate the
                // agent's real status/success-rate breakdown (or this aggregate's own
                // token/latency figures, computed on the same pass) — it is excluded here
                // entirely. Its token usage still counts toward BudgetService's spend total
                // (a wholly separate service/read path, unaffected by this skip) because a
                // real LLM call was made; only THIS analytics surface excludes it.
                if (($context['dryRun'] ?? false) === true) {
                    continue;
                }

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
                    $label = ($agentNames[$runAgent] ?? $runAgent);
                    $perAgent[$runAgent] = ['agentId' => $runAgent, 'name' => $label, 'runs' => 0, 'success' => 0];
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
            'tokens'          => $this->tokens(
                recorded: $tokensRecorded,
                prompt: $promptTokens,
                completion: $completionTokens
            ),
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
     * Load agent UUID → display name for the caller's tenant.
     *
     * Read-only labelling for the `perAgent` aggregate: the run AuditTrail records an
     * agentId and nothing else, and a bar chart labelled with UUIDs is unreadable. The
     * lookup is tenant-scoped by the same ObjectService RBAC as every other read here,
     * so an agent the caller cannot see simply does not appear in the map and its runs
     * fall back to the id.
     *
     * @return array<string, string> Map of agent UUID → name.
     */
    private function loadAgentNames(): array
    {
        $objects = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::AGENT_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        $names = [];
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $object->getObject();
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $names[(string) $object->getUuid()] = $name;
        }

        return $names;

    }//end loadAgentNames()

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
     * LLM token usage from OpenRegister's ChatService (run-cost recording).
     *
     * When no run recorded usage yet, availability is false rather than a
     * fabricated zero — and `total` is NULL, not 0. A consumer that renders the
     * total without also reading `available` — which is exactly what a
     * declarative KPI tile does — would otherwise print a confident "0 tokens"
     * for "nobody recorded any usage". Null renders as an em-dash, which is the
     * truth. `prompt` / `completion` stay numeric: they are only ever read
     * behind an `available` check.
     *
     * @param boolean $recorded   Whether any run reported usage.
     * @param integer $prompt     Prompt tokens summed across the runs.
     * @param integer $completion Completion tokens summed across the runs.
     *
     * @return array<string, mixed> The tokens block.
     *
     * @spec openspec/changes/run-analytics/tasks.md#task-1-3
     */
    private function tokens(bool $recorded, int $prompt, int $completion): array
    {
        $total = null;
        if ($recorded === true) {
            $total = ($prompt + $completion);
        }

        return [
            'available'  => $recorded,
            'prompt'     => $prompt,
            'completion' => $completion,
            'total'      => $total,
        ];

    }//end tokens()

    /**
     * Latency summary (avg/min/max ms, plus the average in seconds) over the
     * collected durations.
     *
     * `avgSeconds` exists because the Dashboard's latency KPI is a declarative
     * `type:"stat"` tile: a tile formats and suffixes ONE number, it cannot
     * switch units at a threshold the way the hand-written widget did (ms below
     * a second, seconds above). Agent runs are seconds-scale work, so seconds
     * is the honest single unit — and `avgMs` stays for callers that want the
     * raw figure.
     *
     * @param array<int, int> $durations The per-run durations in ms.
     *
     * @return array<string, int|float|null> The latency summary.
     */
    private function latency(array $durations): array
    {
        if ($durations === []) {
            return [
                'avgMs'      => null,
                'avgSeconds' => null,
                'minMs'      => null,
                'maxMs'      => null,
            ];
        }

        $avgMs = (int) round(array_sum($durations) / count($durations));

        return [
            'avgMs'      => $avgMs,
            'avgSeconds' => round(($avgMs / 1000), 1),
            'minMs'      => min($durations),
            'maxMs'      => max($durations),
        ];

    }//end latency()
}//end class
