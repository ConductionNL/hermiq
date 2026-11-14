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
use OCA\Hermiq\Service\DashboardPageState;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Controller for the main Hermiq dashboard page.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-4-1
 */
class DashboardController extends Controller {

	/**
	 * Constructor for the DashboardController.
	 *
	 * @param IRequest           $request   The request object.
	 * @param DashboardPageState $pageState Provides the page's capabilities to the SPA.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly DashboardPageState $pageState,
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
		$this->pageState->provide();
		return new TemplateResponse(AppIdentity::APP_ID, 'index');
	}//end page()

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
