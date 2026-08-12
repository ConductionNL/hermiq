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
class OrganisationService {
	/**
	 * Resolve the caller's active organisation.
	 *
	 * @param array<int, Organisation>|null $preloadedOrgs Optional preloaded orgs.
	 *
	 * @return Organisation|null
	 */
	public function getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation {
		return null;
	}//end getActiveOrganisation()

	/**
	 * The default organisation's UUID, from app config.
	 *
	 * Mirrors the real signature. `SeedHydraTriageFlow` calls this to scope the
	 * flow it seeds — a flow written with no organisation is invisible to every
	 * tenant, because every flow read is organisation-scoped (hermiq#140).
	 *
	 * @return string|null The default organisation UUID, or null when unset.
	 */
	public function getDefaultOrganisationUuid(): ?string {
		return null;
	}//end getDefaultOrganisationUuid()

	/**
	 * Return the default organisation, creating it when none exists yet.
	 *
	 * Mirrors the real signature — self-provisioning, which is why the seed can
	 * rely on it on an instance fresh enough that nothing has needed an
	 * organisation before.
	 *
	 * @return Organisation The default organisation.
	 */
	public function ensureDefaultOrganisation(): Organisation {
		return new Organisation();
	}//end ensureDefaultOrganisation()
}//end class
