<?php

/**
 * Test stub for OpenRegister AuditTrailMapper.
 *
 * Stands in for OCA\OpenRegister\Db\AuditTrailMapper when OpenRegister is not
 * installed (standalone CI). Exposes recordObjectEvent, the public
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

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCP\AppFramework\Db\Entity;

/**
 * Minimal AuditTrailMapper stub for standalone unit runs.
 */
class AuditTrailMapper {

	/**
	 * Record a non-CRUD action on one object.
	 *
	 * @param ObjectAuditEvent $event The object, action and context.
	 *
	 * @return AuditTrail
	 */
	public function recordObjectEvent(ObjectAuditEvent $event): AuditTrail {
		$entry = new AuditTrail();
		$entry->setUuid('00000000-0000-0000-0000-000000000000');
		$entry->setAction($event->action);
		$entry->setChanged($event->context);
		return $entry;
	}//end recordObjectEvent()

	/**
	 * Persist a changed audit entry.
	 *
	 * Mirrors the real mapper's `update(Entity $entity): AuditTrail`, which
	 * hermiq's retention cleanup calls to replace an expired run's payload with a
	 * tombstone. The one deliberate divergence is the parameter type: the real
	 * `AuditTrail` extends `OCP\AppFramework\Db\Entity` and this stub's does not,
	 * so the union accepts both rather than making the stub unusable with itself.
	 * Widening the type here is safe in the direction that matters — production
	 * passes a real `AuditTrail`, which is an `Entity`.
	 *
	 * @param Entity|AuditTrail $entity The entry to persist.
	 *
	 * @return AuditTrail The persisted entry.
	 */
	public function update(Entity|AuditTrail $entity): AuditTrail {
		return $entity;
	}//end update()

	/**
	 * Find audit trail entries matching the given filters.
	 *
	 * The real mapper special-cases the `object_uuid` filter as a valid column,
	 * which Hermiq's RunHistoryService relies on to read app-written run entries.
	 *
	 * @param int|null $limit Max rows.
	 * @param int|null $offset Rows to skip.
	 * @param array|null $filters Column filters (e.g. ['object_uuid' => ..., 'action' => 'run']).
	 * @param array|null $sort Sort spec (default created DESC).
	 * @param string|null $search Free-text search term.
	 *
	 * @return array<int, AuditTrail>
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $sort = ['created' => 'DESC'],
		?string $search = null,
	): array {
		return [];
	}//end findAll()
}//end class
