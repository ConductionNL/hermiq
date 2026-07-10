<?php

/**
 * Test stub for OpenRegister OrganisationService.
 *
 * Stands in for OCA\OpenRegister\Service\OrganisationService when OpenRegister is
 * not installed (standalone CI). Exposes only getActiveOrganisation, used by
 * HermiqToolProvider::listAgents to resolve the caller's tenant scope (mirroring
 * OpenRegister's AgentsController::index). The real service ships with OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Organisation;

/**
 * Minimal OrganisationService stub for standalone unit runs.
 */
class OrganisationService
{
    /**
     * Resolve the caller's active organisation.
     *
     * @param array<int, Organisation>|null $preloadedOrgs Optional preloaded orgs.
     *
     * @return Organisation|null
     */
    public function getActiveOrganisation(?array $preloadedOrgs=null): ?Organisation
    {
        return null;
    }//end getActiveOrganisation()
}//end class
