<?php

/**
 * Hermiq OrganisationAdministrators.
 *
 * Who may administer an organisation's budgets: BudgetController guards its
 * write endpoints with the same rule OrganisationControlController::mayAdminister()
 * applies to the kill-switch:
 *
 * - no organisation is administered by anybody, not even an instance admin;
 * - an instance admin (`IGroupManager::isAdmin`) administers every organisation;
 * - the owner of an OpenRegister organisation (`Organisation::getOwner`)
 *   administers that organisation; an organisation that cannot be found is
 *   owned by nobody.
 *
 * Built by the controller from its own injected collaborators; the owner lookup
 * is OrganisationPolicyGate::ownsOrganisation().
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Decides whether a user administers an organisation: instance admin or owner.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
 */
final class OrganisationAdministrators {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager      $groupManager       Resolves instance-admin membership.
	 * @param OrganisationMapper $organisationMapper Resolves an organisation's owner.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
	) {
	}//end __construct()

	/**
	 * Whether the user may administer the organisation. An empty organisation is
	 * refused before any lookup; an instance admin passes without the owner
	 * lookup being made.
	 *
	 * @param string $organisation The organisation identifier (OpenRegister org UUID).
	 * @param IUser  $user         The requesting user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function mayAdminister(string $organisation, IUser $user): bool {
		if ($organisation === '') {
			return false;
		}

		$gate = new OrganisationPolicyGate(groupManager: $this->groupManager, organisationMapper: $this->organisationMapper);

		return $this->groupManager->isAdmin($user->getUID()) === true
			|| $gate->ownsOrganisation(organisation: $organisation, uid: $user->getUID());
	}//end mayAdminister()
}//end class
