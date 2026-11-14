<?php

/**
 * Hermiq TenantRegistrar
 *
 * Bind the organisation contract to its implementation.
 *
 * @category Tenant
 * @package  OCA\Hermiq\Tenant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tenant;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The Tenant component binds its own contract, so the bootstrap does not name the implementation.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
 */
final class TenantRegistrar {

	/**
	 * Bind the organisation contract to its implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-schedule-dispatcher/tasks.md#task-1-2
	 */
	public function register(IRegistrationContext $context): void {
		// ADR-083 rule 3: the dashboard is the app's DEFAULT ROUTE, so it depends on
		// hermiq's own ManageableOrganisations contract instead of OpenRegister's
		// OrganisationMapper. The binding names the implementation here, at the
		// composition root; the implementation itself takes only core services and
		// reaches OpenRegister behind an isInstalled() guard, so the container can
		// build the controller — and the start screen can render — on an instance
		// where OpenRegister is absent.
		$context->registerServiceAlias(
			ManageableOrganisations::class,
			OpenRegisterManageableOrganisations::class
		);
	}//end register()
}//end class
