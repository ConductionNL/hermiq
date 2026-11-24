<?php

/**
 * Hermiq RunAuditScope.
 *
 * Reads the run audit entries of a set of agents, and only those. The audit trail has
 * no column for the agent a run belongs to: `agentId` lives inside the `changed` JSON,
 * and the object an entry hangs on differs per writer (a schedule, a skill draft, a
 * flow's triggering object, the agent). So the read is narrowed per agent through the
 * mapper's `search` term, a LIKE on `changed`: OpenRegister hands back only rows whose
 * context mentions that agent's UUID, and another organisation's runs never enter this
 * process. With no agent there is no query at all.
 *
 * The LIKE is a superset (the UUID may also appear elsewhere in a context), so a caller
 * still checks `changed.agentId` per row; entries are de-duplicated here, because a run
 * whose context names two of the agents comes back from both queries.
 *
 * No `limit`: a truncating limit would turn every metric built on this read into a floor,
 * silently indistinguishable from a total. The bound is the agent set itself.
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
 * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * The run audit entries of a set of agents, read with a tenant bound.
 *
 * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
 */
class RunAuditScope
{

    /**
     * The audit actions that represent an agent run, as ONE comma-joined filter value
     * (the mapper turns it into a SQL `IN`).
     *
     * @var string
     */
    public const RUN_ACTIONS = 'run,agent-run,manual-run';

    /**
     * Constructor.
     *
     * @param AuditTrailMapper $auditTrailMapper OpenRegister audit read (run entries).
     *
     * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
    ) {

    }//end __construct()

    /**
     * The run entries whose context mentions one of the agents, each once, newest first.
     *
     * @param array<int, int|string> $agentIds The agent UUIDs the caller may see.
     *
     * @return array<int, AuditTrail> The entries, newest first.
     *
     * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
     */
    public function logsOf(array $agentIds): array
    {
        $logs = [];
        foreach ($agentIds as $agentId) {
            $agentId = trim((string) $agentId);
            if ($agentId === '') {
                continue;
            }

            $found = $this->auditTrailMapper->findAll(
                filters: ['action' => self::RUN_ACTIONS],
                search: $agentId
            );
            foreach ($found as $log) {
                $logs[self::logKey(log: $log)] = $log;
            }
        }

        $logs = array_values($logs);
        usort(
            $logs,
            static fn (AuditTrail $left, AuditTrail $right): int => (self::createdEpoch(log: $right) <=> self::createdEpoch(log: $left))
        );

        return $logs;

    }//end logsOf()

    /**
     * The identity an entry is de-duplicated on: its UUID, else the instance.
     *
     * @param AuditTrail $log The entry.
     *
     * @return string The key.
     *
     * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
     */
    private static function logKey(AuditTrail $log): string
    {
        $uuid = (string) ($log->getUuid() ?? '');
        if ($uuid !== '') {
            return 'uuid:'.$uuid;
        }

        return 'object:'.spl_object_id($log);

    }//end logKey()

    /**
     * When an entry was written, as a Unix timestamp; 0 when it carries no date.
     *
     * @param AuditTrail $log The entry.
     *
     * @return int The timestamp.
     *
     * @spec openspec/changes/archive/2026-11-24-analytics-unbounded-audit-scan/tasks.md#1-backend-bound-the-audit-trail-read
     */
    private static function createdEpoch(AuditTrail $log): int
    {
        $created = $log->getCreated();
        if ($created instanceof DateTimeInterface) {
            return $created->getTimestamp();
        }

        return 0;

    }//end createdEpoch()
}//end class
