<?php

/**
 * Hermiq Dashboard Page State
 *
 * The initial state the main Hermiq page hands the SPA: the kill-switch
 * capability with the manageable organisations, the admin and OpenCatalogi
 * flags, and whether the viewer may name an agent's acting user. Moved out of
 * DashboardController so the default route takes one collaborator.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Tenant\ManageableOrganisations;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Provides the dashboard page's capabilities to the SPA via IInitialState.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Provides the kill-switch capability
 *   initial-state, which needs instance-admin + OpenRegister organisation resolution.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-4-1
 */
class DashboardPageState {
	/**
	 * The governance action that names an agent's acting user. A literal, not
	 * AgentActingUserGuardListener::ACTION: this default route stays loadable
	 * without OpenRegister (ADR-083 rule 3), and that listener names its events.
	 *
	 * @var string
	 */
	private const SET_ACTING_USER = 'agent.set-acting-user';

	/**
	 * Constructor.
	 *
	 * @param IInitialState $initialState Provides the kill-switch capability to the SPA (human-approval-gate-ui).
	 * @param IUserSession $userSession Resolves the current user.
	 * @param IGroupManager $groupManager Instance-admin check.
	 * @param ManageableOrganisations $organisations Tenant-scope lookup. A hermiq
	 *   contract, NOT OpenRegister's mapper: ADR-083 rule 3 requires the default
	 *   route to stay core-only so an instance without OpenRegister still reaches
	 *   a start screen that explains itself. The OpenRegister reach lives behind
	 *   an availability guard in OpenRegisterManageableOrganisations.
	 * @param IAppManager $appManager Runtime app availability (OpenCatalogi publication seam).
	 * @param ActionAuthServiceInterface $actions The action matrix (agent.set-acting-user).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ManageableOrganisations $organisations,
		private readonly IAppManager $appManager,
		private readonly ActionAuthServiceInterface $actions,
	) {
	}//end __construct()

	/**
	 * Provide every capability of the page.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 */
	public function provide(): void {
		$this->provideKillSwitchCapability();
		$this->provideActingUserCapability();
	}//end provide()

	/**
	 * Provide the kill-switch capability + manageable organisations to the SPA.
	 *
	 * Tenant model (ADR-001 multi-tenancy): an organisation is an OpenRegister
	 * organisation identified by its UUID — the same value schedules carry in
	 * `_organisation`, so the kill-switch actually matches the runs it halts. An instance
	 * admin governs every organisation; a plain user manages only the organisations they
	 * own. The toggle endpoint re-checks this server-side.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Single defensive capability
	 * assembly (admin/ownership/fallback branches); splitting would separate the
	 * checks from the payload they guard.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-4-1
	 */
	private function provideKillSwitchCapability(): void {
		$organisations = [];
		$canManage = false;

		$user = $this->userSession->getUser();
		if ($user !== null) {
			$uid = $user->getUID();
			$isAdmin = ($this->groupManager->isAdmin($uid) === true);

			// The tenant scope is an OpenRegister organisation (the value schedules
			// carry in `_organisation`), NOT an NC group. An instance admin governs
			// every organisation; a plain user manages only the organisations they
			// own (Organisation.owner). Members without ownership cannot halt runs.
			//
			// Resolved through hermiq's own ManageableOrganisations contract so this
			// controller — the app's DEFAULT ROUTE — names no OpenRegister type and
			// stays constructable without it (ADR-083 rule 3). The implementation
			// never throws; an unavailable store answers with an empty list.
			$organisations = $this->organisations->forUser(userId: $uid, isAdmin: $isAdmin);

			if ($isAdmin === true || $organisations !== []) {
				$canManage = true;
			}//end if
		}//end if

		$this->initialState->provideInitialState('can_manage_killswitch', $canManage);
		$this->initialState->provideInitialState('managed_organisations', $organisations);

		// Algoritmeregister publication (algoritmeregister-publication): admin-only publish
		// /withdraw actions, and hidden entirely when the fleet publication leaf is absent.
		// UX gating only — the action-auth gate + runtime seam remain the real boundaries.
		$isAdminNow = ($user !== null && $this->groupManager->isAdmin($user->getUID()) === true);
		$this->initialState->provideInitialState('is_admin', $isAdminNow);
		$this->initialState->provideInitialState('opencatalogi_available', $this->appManager->isInstalled('opencatalogi'));

	}//end provideKillSwitchCapability()

	/**
	 * Tell the agent edit page whether the viewer may name the account an agent
	 * works with (`can_set_acting_user`), read through the action matrix. UX
	 * gating only: AgentActingUserGuardListener refuses the write either way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-acting-identity-picker/specs/agent-identity/spec.md#requirement-naming-a-service-account-is-a-governance-action-checked-on-every-write-req-agid-002
	 */
	private function provideActingUserCapability(): void {
		$user = $this->userSession->getUser();
		$canSet = ($user !== null && $this->actions->can(user: $user, action: self::SET_ACTING_USER) === true);
		$this->initialState->provideInitialState('can_set_acting_user', $canSet);
	}//end provideActingUserCapability()
}//end class
