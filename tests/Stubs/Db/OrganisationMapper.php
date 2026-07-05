<?php

/**
 * Test stub for OpenRegister OrganisationMapper.
 *
 * Stands in for OCA\OpenRegister\Db\OrganisationMapper when OpenRegister is not installed
 * (standalone CI). Exposes the lookups the kill-switch surface uses: findByUuid (owner
 * check in TenantControlController) and findAll / findByUserId (manageable-org list in
 * DashboardController). The real mapper ships with OpenRegister.
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
 * Minimal OrganisationMapper stub for standalone unit runs.
 */
class OrganisationMapper
{

    /**
     * Resolve an organisation by UUID.
     *
     * @param string $uuid The organisation UUID.
     *
     * @return Organisation
     */
    public function findByUuid(string $uuid): Organisation
    {
        return new Organisation();
    }//end findByUuid()

    /**
     * List all organisations.
     *
     * @param int $limit  Maximum rows.
     * @param int $offset Row offset.
     *
     * @return array<int, Organisation>
     */
    public function findAll(int $limit=50, int $offset=0): array
    {
        return [];
    }//end findAll()

    /**
     * List the organisations a user belongs to.
     *
     * @param string $userId The user id.
     *
     * @return array<int, Organisation>
     */
    public function findByUserId(string $userId): array
    {
        return [];
    }//end findByUserId()
}//end class
