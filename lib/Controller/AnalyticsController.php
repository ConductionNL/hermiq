<?php

/**
 * Hermiq AnalyticsController.
 *
 * The read-only run-analytics endpoint (run-analytics): returns success rate, latency,
 * status breakdown and per-agent metrics for the caller's tenant, optionally scoped to one
 * agent. All aggregation is tenant-scoped inside AnalyticsService (only the caller's own
 * schedules' run entries are counted), so no cross-tenant run data leaks. `@NoAdminRequired`
 * opens the route to any authenticated user; tenancy is the guard.
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
 * @spec openspec/changes/run-analytics/tasks.md#2-controller-route
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped run-analytics read endpoint.
 *
 * @spec openspec/changes/run-analytics/tasks.md#2-controller-route
 */
class AnalyticsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AnalyticsService $analyticsService The run-analytics read service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @spec openspec/changes/run-analytics/tasks.md#task-2-1
	 */
	public function __construct(
		IRequest $request,
		private readonly AnalyticsService $analyticsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return run analytics for the caller's tenant (optionally scoped to one agent).
	 *
	 * @param string $agentId Optional agent UUID to scope the metrics to.
	 *
	 * @return JSONResponse The metrics payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/run-analytics/tasks.md#task-2-1
	 */
	public function index(string $agentId = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$scopedAgent = null;
		if (trim($agentId) !== '') {
			$scopedAgent = $agentId;
		}

		try {
			return new JSONResponse($this->analyticsService->computeAnalytics(agentId: $scopedAgent));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq analytics failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not compute analytics'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()
}//end class
