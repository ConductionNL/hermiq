<?php

/**
 * Test stub for OpenRegister's FlowMapper.
 *
 * Stands in for OCA\OpenRegister\Db\FlowMapper when OpenRegister is not
 * installed (standalone CI). Only the entry points hermiq's seed step uses
 * are declared, with the real signatures: `findAllFlows()` for the idempotency
 * check, `insert()` for the write, and `update()` (both inherited from QBMapper
 * on the real class) for the applicationSlug backfill onto an existing row.
 *
 * Deliberately NOT a full mirror. Every method declared here is one this stub
 * promises hermiq may call, so keeping it to what is actually used keeps the
 * promise small. The callers are the seed step and, since
 * schedules-onto-engine-triggers, the ScheduleFlowBridge and the schedule
 * dispatch node (`findByUuid()` and `delete()`, both with the real
 * signatures: findByUuid throws DoesNotExistException, delete comes from
 * QBMapper).
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
 * Minimal FlowMapper stub for standalone static analysis.
 */
class FlowMapper {
	/**
	 * List flows. Same signature as OpenRegister's FlowMapper::findAllFlows().
	 *
	 * @param \OCA\OpenRegister\Db\Flow\FlowSelection   $selection Which flows.
	 * @param \OCA\OpenRegister\Kernel\Contracts\ListQuery $query     The page.
	 *
	 * @return array<int, Flow> The matching flows.
	 */
	public function findAllFlows(
		\OCA\OpenRegister\Db\Flow\FlowSelection $selection=new \OCA\OpenRegister\Db\Flow\FlowSelection(),
		\OCA\OpenRegister\Kernel\Contracts\ListQuery $query=new \OCA\OpenRegister\Kernel\Contracts\ListQuery(limit: 100, offset: 0),
	): array {
		return [];
	}//end findAllFlows()

	/**
	 * Insert a flow. Provided by QBMapper on the real class.
	 *
	 * @param Flow $entity The flow to insert.
	 *
	 * @return Flow The inserted flow.
	 */
	public function insert(Flow $entity): Flow {
		return $entity;
	}//end insert()

	/**
	 * Update a flow. Provided by QBMapper on the real class.
	 *
	 * @param Flow $entity The flow to update.
	 *
	 * @return Flow The updated flow.
	 */
	public function update(Flow $entity): Flow {
		return $entity;
	}//end update()

	/**
	 * Find a flow by its public uuid.
	 *
	 * Faithful to the real class: an unknown uuid THROWS rather than answers
	 * an empty flow, so a test exercising the missing-mirror path sees the
	 * same shape production does.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return Flow The flow.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException Always, on the stub.
	 */
	public function findByUuid(string $uuid): Flow {
		throw new \OCP\AppFramework\Db\DoesNotExistException('stub: no flow ' . $uuid);
	}//end findByUuid()

	/**
	 * Delete a flow. Provided by QBMapper on the real class.
	 *
	 * @param Flow $entity The flow to delete.
	 *
	 * @return Flow The deleted flow.
	 */
	public function delete(Flow $entity): Flow {
		return $entity;
	}//end delete()
}//end class
