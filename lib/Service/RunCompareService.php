<?php

/**
 * Hermiq RunCompareService.
 *
 * Reads two run audit entries and compares them. The caller hands in the visible
 * agent set (AnalyticsService resolves it, the same set the run list filters on), so
 * a run of any other agent, an unknown id and a dry run all come back as `null` for
 * that side, and the controller answers all three the same way.
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
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Closure;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Compares two runs on the run list's boundary.
 *
 * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
 */
class RunCompareService
{

    /**
     * The audit actions that represent an agent run (schedule and flow).
     *
     * @var string
     */
    private const RUN_ACTIONS = 'run,agent-run';

    /**
     * Constructor.
     *
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (run entries).
     * @param RunComparator    $runComparator    Aligns the two runs' steps.
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RunComparator $runComparator=new RunComparator(),
    ) {
    }//end __construct()

    /**
     * Compare two runs, each only when its agent is in the visible set.
     *
     * @param string                $leftId        The left run's audit entry uuid.
     * @param string                $rightId       The right run's audit entry uuid.
     * @param array<string, string> $visibleAgents The caller's visible agents (uuid => name).
     * @param Closure               $toRunRow      Shapes an entry as the run list does (AnalyticsService::toRunRow).
     *
     * @return array{left: ?array, right: ?array, sameAgent: bool, comparison: ?array}
     *
     * @spec openspec/changes/observability-compare-two-runs/specs/run-replay-and-dry-run/spec.md#requirement-a-person-can-compare-any-two-runs-they-may-see-req-rcmp-001
     */
    public function compare(string $leftId, string $rightId, array $visibleAgents, Closure $toRunRow): array
    {
        $left  = $this->loadRun(runId: $leftId, visibleAgents: $visibleAgents, toRunRow: $toRunRow);
        $right = $this->loadRun(runId: $rightId, visibleAgents: $visibleAgents, toRunRow: $toRunRow);

        $result = ['left' => $left, 'right' => $right, 'sameAgent' => false, 'comparison' => null];
        if ($left === null || $right === null) {
            return $result;
        }

        $result['sameAgent']  = ($left['agentId'] === $right['agentId']);
        $result['comparison'] = $this->runComparator->compare(
            leftSteps: $left['steps'],
            rightSteps: $right['steps'],
            leftSummary: (string) ($left['summary'] ?? ''),
            rightSummary: (string) ($right['summary'] ?? '')
        );

        return $result;

    }//end compare()

    /**
     * One run for the comparison, or null when the caller may not see it.
     *
     * @param string                $runId         The run's audit entry uuid.
     * @param array<string, string> $visibleAgents The caller's visible agents (uuid => name).
     * @param Closure               $toRunRow      Shapes an entry as the run list does.
     *
     * @return array<string, mixed>|null The run row with its steps and run-time facts.
     */
    private function loadRun(string $runId, array $visibleAgents, Closure $toRunRow): ?array
    {
        $runId = trim($runId);
        if ($runId === '' || $visibleAgents === []) {
            return null;
        }

        $logs = $this->auditTrailMapper->findAll(filters: ['uuid' => $runId, 'action' => self::RUN_ACTIONS]);
        foreach ($logs as $log) {
            // Matched here as well: the filter narrows the read, this decides the answer.
            if ($log->getUuid() !== $runId) {
                continue;
            }

            $context  = ($log->getChanged() ?? []);
            $runAgent = trim((string) ($context['agentId'] ?? ''));
            if ($runAgent === '' || isset($visibleAgents[$runAgent]) === false || ($context['dryRun'] ?? false) === true) {
                return null;
            }

            $row = $toRunRow(
                log: $log,
                context: $context,
                agentId: $runAgent,
                agentName: $visibleAgents[$runAgent],
                status: (string) ($context['status'] ?? 'unknown')
            );
            unset($row['createdSort']);

            // Null means "not recorded for this run", which the view says in words.
            $row['steps']        = array_values((array) ($context['steps'] ?? []));
            $row['agentVersion'] = ($context['agentVersion'] ?? null);
            $row['provider']     = ($context['provider'] ?? null);
            $row['model']        = ($context['model'] ?? null);

            return $row;
        }//end foreach

        return null;

    }//end loadRun()
}//end class
