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

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Controller for the main Hermiq dashboard page.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Provides the kill-switch capability
 *   initial-state, which needs instance-admin + OpenRegister organisation resolution.
 *
 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
 */
class DashboardController extends Controller
{
    /**
     * Constructor for the DashboardController.
     *
     * @param IRequest           $request            The request object.
     * @param IInitialState      $initialState       Provides the kill-switch capability to the SPA (human-approval-gate-ui).
     * @param IUserSession       $userSession        Resolves the current user.
     * @param IGroupManager      $groupManager       Instance-admin check.
     * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup (tenant scope).
     * @param IAppManager        $appManager         Runtime app availability (OpenCatalogi publication seam).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IInitialState $initialState,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly OrganisationMapper $organisationMapper,
        private readonly IAppManager $appManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
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
     * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
     */
    public function page(): TemplateResponse
    {
        $this->provideKillSwitchCapability();
        return new TemplateResponse(Application::APP_ID, 'index');
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
     * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
     */
    private function provideKillSwitchCapability(): void
    {
        $organisations = [];
        $canManage     = false;

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $uid     = $user->getUID();
            $isAdmin = ($this->groupManager->isAdmin($uid) === true);

            // The tenant scope is an OpenRegister organisation (the value schedules
            // carry in `_organisation`), NOT an NC group. An instance admin governs
            // every organisation; a plain user manages only the organisations they
            // own (Organisation.owner). Members without ownership cannot halt runs.
            $orgs = [];
            try {
                if ($isAdmin === true) {
                    $orgs = $this->organisationMapper->findAll(limit: 500);
                }

                if ($isAdmin === false) {
                    $orgs = $this->organisationMapper->findByUserId($uid);
                }
            } catch (Throwable $e) {
                // A read failure degrades to "no manageable organisation" — the toggle
                // endpoint remains the real authorization boundary regardless.
                $orgs = [];
            }//end try

            foreach ($orgs as $org) {
                if ($isAdmin === false && (string) ($org->getOwner() ?? '') !== $uid) {
                    continue;
                }

                $uuid = (string) $org->getUuid();
                $name = (string) ($org->getName() ?? '');
                if ($name === '') {
                    $name = $uuid;
                }

                $organisations[] = [
                    'id'    => $uuid,
                    'label' => $name,
                ];
            }//end foreach

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
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     *
     * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-002
     */
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()
}//end class
