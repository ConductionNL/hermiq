<?php

/**
 * Hermiq Dashboard Controller
 *
 * Controller for the main Hermiq dashboard page.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Tenant\ManageableOrganisations;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the main Hermiq dashboard page.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Provides the kill-switch capability
 *   initial-state, which needs instance-admin + OpenRegister organisation resolution.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-4-1
 */
class DashboardController extends Controller {

	/**
	 * The governance action that names an agent's acting user. A literal, not
	 * AgentActingUserGuardListener::ACTION: this default route stays loadable
	 * without OpenRegister (ADR-083 rule 3), and that listener names its events.
	 *
	 * @var string
	 */
	private const SET_ACTING_USER = 'agent.set-acting-user';

	/**
	 * Constructor for the DashboardController.
	 *
	 * @param IRequest $request The request object.
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
		IRequest $request,
		private readonly IInitialState $initialState,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ManageableOrganisations $organisations,
		private readonly IAppManager $appManager,
		private readonly ActionAuthServiceInterface $actions,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the main dashboard page.
	 *
	 * Provides the kill-switch capability to the SPA via IInitialState (never a DOM
	 * data-attribute read, ADR-004): `can_manage_killswitch` and the list of
	 * `managed_organisations` (every OpenRegister organisation for an instance admin, the
	 * organisations the user owns otherwise) so KillSwitchToggle renders for an org owner
	 * / instance admin and gives them a real tenant to target. The endpoints remain the
	 * real authorization boundary; this flag is UX gating only.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-4-1
	 */
	public function page(): TemplateResponse {
		$this->provideKillSwitchCapability();
		$this->provideActingUserCapability();
		return new TemplateResponse(AppIdentity::APP_ID, 'index');
	}//end page()

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

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-002
	 */
	public function catchAll(): TemplateResponse {
		return $this->page();
	}//end catchAll()
}//end class
