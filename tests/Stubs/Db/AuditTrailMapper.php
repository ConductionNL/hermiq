<?php

/**
 * Test stub for OpenRegister AuditTrailMapper.
 *
 * Stands in for OCA\OpenRegister\Db\AuditTrailMapper when OpenRegister is not
 * installed (standalone CI). Exposes only createAuditTrailEntry, the public
 * app-writable audit seam Hermiq's ScheduleService calls to record an explicit
 * per-run entry. The real mapper ships with OpenRegister and chains the entry
 * into the hash-verified trail.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal AuditTrailMapper stub for standalone unit runs.
 */
class AuditTrailMapper
{

    /**
     * Create an explicit audit trail entry for an object.
     *
     * @param ObjectEntity        $object  The object the entry is about.
     * @param string              $action  The audit action (e.g. 'run').
     * @param array<string,mixed> $context The changed/context payload.
     *
     * @return AuditTrail
     */
    public function createAuditTrailEntry(
        ObjectEntity $object,
        string $action,
        array $context=[]
    ): AuditTrail {
        $entry = new AuditTrail();
        $entry->setUuid('00000000-0000-0000-0000-000000000000');
        $entry->setAction($action);
        $entry->setChanged($context);
        return $entry;
    }//end createAuditTrailEntry()

    /**
     * Find audit trail entries matching the given filters.
     *
     * The real mapper special-cases the `object_uuid` filter as a valid column,
     * which Hermiq's RunHistoryService relies on to read app-written run entries.
     *
     * @param int|null    $limit   Max rows.
     * @param int|null    $offset  Rows to skip.
     * @param array|null  $filters Column filters (e.g. ['object_uuid' => ..., 'action' => 'run']).
     * @param array|null  $sort    Sort spec (default created DESC).
     * @param string|null $search  Free-text search term.
     *
     * @return array<int, AuditTrail>
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $sort=['created' => 'DESC'],
        ?string $search=null
    ): array {
        return [];
    }//end findAll()
}//end class
