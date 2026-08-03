<?php

/**
 * Test stub for OpenRegister's FlowMapper.
 *
 * Stands in for OCA\OpenRegister\Db\FlowMapper when OpenRegister is not
 * installed (standalone CI). Only the two entry points hermiq's seed step uses
 * are declared, with the real signatures: `findAllFlows()` for the idempotency
 * check and `insert()` (inherited from QBMapper on the real class) for the write.
 *
 * Deliberately NOT a full mirror. Every method declared here is one this stub
 * promises hermiq may call, so keeping it to what is actually used keeps the
 * promise small — and the seed step is the only place hermiq touches OR's flow
 * store directly at all.
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
class FlowMapper
{
    /**
     * List flows, optionally scoped by app, tenant and enabled state.
     *
     * @param string|null  $app          Restrict to one owning app id.
     * @param string|null  $organisation Restrict to one tenant.
     * @param boolean|null $enabled      Restrict to enabled or disabled flows.
     * @param integer      $limit        Page size.
     * @param integer      $offset       Page offset.
     *
     * @return array<int, Flow> The matching flows.
     */
    public function findAllFlows(
        ?string $app=null,
        ?string $organisation=null,
        ?bool $enabled=null,
        int $limit=100,
        int $offset=0
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
    public function insert(Flow $entity): Flow
    {
        return $entity;

    }//end insert()
}//end class
