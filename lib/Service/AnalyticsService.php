<?php

/**
 * Hermiq AnalyticsService.
 *
 * The read-only run-analytics surface (run-analytics). It computes success rate, latency,
 * status breakdown and per-agent metrics directly from Hermiq's run-shaped OpenRegister
 * AuditTrail entries — no separate analytics store, no ETL (ADR-004 governance, ADR-001
 * Option C+).
 *
 * WHAT COUNTS AS A RUN (analytics-run-scope). Hermiq invokes an agent through TWO
 * channels, and each writes its own audit action against a DIFFERENT object:
 *
 *   - `action='run'`       — ScheduleService::writeRunAudit(), written on the Schedule.
 *   - `action='agent-run'` — FlowAgentRunService::writeRunAudit(), written on the object
 *                            whose flow triggered the agent. That object routinely lives
 *                            in ANOTHER register than hermiq's.
 *
 * Both channels are counted. The predecessor read only `action='run'` AND keyed the whole
 * aggregation on the caller's LIVE Schedule set — every run entry whose `object_uuid` was
 * not a currently-visible, not-soft-deleted Schedule was dropped, and when that set was
 * empty the aggregation was skipped wholesale and every metric returned a confident zero.
 * That made the surface wrong in two directions at once: flow-driven runs were invisible
 * no matter what, and DELETING A SCHEDULE silently erased its runs from history even
 * though the append-only audit entries were all still there. Observed 2026-08-13 on the
 * dev instance: 36 real `run` entries on disk, all six Schedules soft-deleted, dashboard
 * reporting `totalRuns: 0`.
 *
 * TENANT SCOPE is therefore keyed on the AGENT, not on the schedule. Every run entry from
 * both channels records `agentId` in its context; an entry is counted only when that agent
 * is in the caller's own visible agent set, which is resolved through the SAME ObjectService
 * RBAC + multitenancy the rest of this service reads under. An agent the caller may not see
 * contributes nothing, so no cross-tenant run data leaks — and the boundary now matches what
 * the number actually claims to be ("runs my agents did") instead of standing in for it.
 * An entry carrying no `agentId` at all is not a run (ScheduleService::writeOffboardingAudit()
 * writes one such `action='run'` entry per offboarding pause) and is skipped.
 *
 * KNOWN GAP, deliberately not papered over: the visible set ASKS for soft-deleted agents
 * and OpenRegister does not return them on any read path this service can use — see
 * loadVisibleAgents() for the two levers that were measured and found ineffective. So a
 * run whose agent has since been deleted is still not counted. Deleting a SCHEDULE no
 * longer erases history; deleting an AGENT still does.
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
     * Agent schema slug — the tenant boundary AND the `perAgent` labels.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * The audit actions that represent an agent run, as ONE comma-joined filter value.
     *
     * AuditTrailMapper::findAll() turns a comma-bearing filter value into a SQL `IN`
     * (see its filter loop), so both run channels come back from a single query rather
     * than two passes that would then need merging and re-sorting.
     *
     * @var string
     */
    private const RUN_ACTIONS = 'run,agent-run';

    /**
     * Page size for the visible-agent scan (paged to exhaustion, never a cap).
     *
     * @var integer
     */
    private const AGENT_PAGE_SIZE = 500;

    /**
     * The status value that counts as a successful run.
     *
     * @var string
     */
    private const STATUS_OK = 'ok';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OpenRegister object read (tenant-scoped agent set).
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
        // Tenant boundary AND `perAgent` labels in one read: agent UUID → display name,
        // RBAC + multitenancy ON (see loadVisibleAgents()). A run whose agent is absent
        // from this map is not the caller's to see and is never counted.
        $visibleAgents = $this->loadVisibleAgents(agentId: $agentId);

        $totalRuns        = 0;
        $successRuns      = 0;
        $statusBreakdown  = [];
        $durations        = [];
        $perAgent         = [];
        $promptTokens     = 0;
        $completionTokens = 0;
        $tokensRecorded   = false;

        if ($visibleAgents !== []) {
            // Deliberately unlimited: a truncating limit would turn every metric on this
            // page into a floor silently indistinguishable from a total. The filter is
            // already narrow (two actions), and the per-row tenant check below is what
            // bounds the result to the caller.
            $logs = $this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTIONS]);

            foreach ($logs as $log) {
                $context = ($log->getChanged() ?? []);

                // TENANT BOUNDARY. The run's agent — not the object the entry hangs on —
                // decides whether this run is the caller's to count. `agent-run` entries
                // hang on the flow's triggering object, which routinely lives in another
                // register entirely, so `object_uuid` cannot serve as the scope key for
                // both channels; `agentId` is recorded by both writers and can.
                $runAgent = trim((string) ($context['agentId'] ?? ''));
                if ($runAgent === '' || isset($visibleAgents[$runAgent]) === false) {
                    continue;
                }

                // Run-replay-and-dry-run: a dry-run/replay preview must never inflate the
                // agent's real status/success-rate breakdown (or this aggregate's own
                // token/latency figures, computed on the same pass) — it is excluded here
                // entirely. Its token usage still counts toward BudgetService's spend total
                // (a wholly separate service/read path, unaffected by this skip) because a
                // real LLM call was made; only THIS analytics surface excludes it.
                if (($context['dryRun'] ?? false) === true) {
                    continue;
                }

                $status = (string) ($context['status'] ?? 'unknown');

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
                    // Fall back to the UUID only when the agent carries no name — a bar
                    // chart labelled with UUIDs is unreadable, but a blank label is worse.
                    $label = $visibleAgents[$runAgent];
                    if ($label === '') {
                        $label = $runAgent;
                    }

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
     * Load the caller's visible agent UUIDs mapped to their display name.
     *
     * This map is BOTH the tenant boundary and the `perAgent` labelling. It is read
     * through ObjectService with RBAC and multitenancy left ON, so an agent the caller
     * may not see never enters the map and its runs are therefore never counted.
     *
     * WHY searchObjectsPaginated() rather than the findAll() the rest of this service
     * used: findAll() has no way to include soft-deleted objects — it hands the get
     * handler no deleted flag at all — while searchObjectsPaginated() takes an explicit
     * `deleted` parameter that reaches the query handler. Soft-deleted agents MUST be in
     * the map: their runs are real, recorded history, and deleting the agent afterwards
     * should no more erase them than deleting a schedule should (which is the bug this
     * whole method replaces).
     *
     * setRegister()/setSchema() resolve the slug REGISTER-SCOPED (ObjectService::setSchema
     * tries findBySlugInIds() against the current register before the global lookup), which
     * matters here: `agent` is a slug hermiq shares with openbuild on a shared instance, and
     * a global resolution would scope this boundary to the wrong app's schema entirely.
     *
     * @param string|null $agentId Optional agent UUID to narrow the map to a single agent.
     *
     * @return array<string, string> Map of agent UUID → display name (name may be '').
     */
    private function loadVisibleAgents(?string $agentId): array
    {
        $scoped = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::AGENT_SCHEMA);

        $agents = [];
        $offset = 0;

        // PAGED TO EXHAUSTION, not capped. A fixed limit here would silently shrink the
        // tenant boundary as an org's agent count grew, and a run dropped for being past
        // page one is indistinguishable from a run that never happened. The loop reads the
        // reported `total` and stops when it has seen every row (or when a page comes back
        // empty, which also terminates a backend that under-reports the total).
        do {
            $result = $scoped->searchObjectsPaginated(
                query: [
                    '_limit'  => self::AGENT_PAGE_SIZE,
                    '_offset' => $offset,
                    // ASKS for soft-deleted agents; at HEAD, OpenRegister does not
                    // deliver them on this path. Both levers were measured on the dev
                    // instance 2026-08-13 and BOTH are ineffective here:
                    //
                    //   - the `deleted:` parameter of searchObjectsPaginated() is
                    //     decorative — it is threaded down two layers and then never
                    //     handed to the mapper, surviving only as an echo in
                    //     `$result['@self']['deleted']`.
                    //   - `_includeDeleted` DOES reach MagicSearchHandler, but only the
                    //     COUNT query honours it: on hermiq/agent it moved `total` from
                    //     22 to 106 while `results` stayed the same 22 live rows, and no
                    //     soft-deleted agent came back at any limit.
                    //
                    // CONSEQUENCE, stated rather than hidden: a run whose agent has since
                    // been deleted is NOT counted, because its agent cannot enter the
                    // visibility map. 8 of the 36 run entries on the dev instance are in
                    // that state. Deleting a SCHEDULE no longer erases history (the bug
                    // this method replaces); deleting an AGENT still does, and will keep
                    // doing so until the OpenRegister read path returns soft-deleted rows.
                    // The flag stays so this scan widens by itself once OR is fixed.
                    '_includeDeleted' => true,
                ]
            );

            $page = ($result['results'] ?? []);
            foreach ($page as $object) {
                if (($object instanceof ObjectEntity) === false) {
                    continue;
                }

                $uuid = (string) $object->getUuid();

                // Narrowing to one agent happens HERE, against the visible set, rather
                // than by trusting the caller-supplied id: an agentId the caller may not
                // see simply finds no match and yields an empty map, so the agent-scoped
                // detail page cannot be used to read another tenant's run metrics.
                if ($agentId !== null && $agentId !== '' && $uuid !== $agentId) {
                    continue;
                }

                $data          = $object->getObject();
                $agents[$uuid] = trim((string) ($data['name'] ?? ''));
            }

            $offset += self::AGENT_PAGE_SIZE;
            $total   = (int) ($result['total'] ?? 0);
        } while ($page !== [] && $offset < $total);

        return $agents;

    }//end loadVisibleAgents()

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
