<?php

/**
 * Test stub for OpenRegister AgentMapper.
 *
 * Stands in for OCA\OpenRegister\Db\AgentMapper when OpenRegister is not
 * installed (standalone CI). Exposes only findByUuid, used by ScheduleService to
 * resolve a schedule's agentId. The real mapper ships with OpenRegister.
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
 * Minimal AgentMapper stub for standalone unit runs.
 */
class AgentMapper
{

    /**
     * Resolve an agent by UUID.
     *
     * @param string $uuid The agent UUID.
     *
     * @return Agent
     */
    public function findByUuid(string $uuid): Agent
    {
        return new Agent();
    }//end findByUuid()
}//end class
