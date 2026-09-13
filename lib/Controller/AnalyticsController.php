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

	/**
	 * List the caller's runs across every agent, newest first.
	 *
	 * Sits beside `index()` rather than on `RunHistoryController` on purpose. That
	 * controller is addressed as `/api/schedules/{scheduleId}/runs` and its guard is
	 * ownership of one schedule, which cannot express "every run I may see" and cannot
	 * see a flow-triggered run at all. This endpoint answers the cross-agent question,
	 * and shares its tenant boundary with the KPIs directly above it so the list and
	 * the numbers can never disagree about what the caller may see.
	 *
	 * @param string $agentId Optional agent UUID to scope the list to.
	 * @param string $status  Optional run status to filter on.
	 * @param int    $limit   Max rows to return.
	 * @param int    $offset  Rows to skip.
	 *
	 * @return JSONResponse The page of runs, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/run-analytics/spec.md#requirement-a-cross-agent-run-list-on-the-same-tenant-boundary-as-the-metrics
	 */
	public function runs(
		string $agentId = '',
		string $status = '',
		int $limit = 50,
		int $offset = 0,
	): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$scopedAgent = null;
		if (trim($agentId) !== '') {
			$scopedAgent = $agentId;
		}

		$scopedStatus = null;
		if (trim($status) !== '') {
			$scopedStatus = $status;
		}

		// Clamped rather than trusted. `limit` reaches this from a query string, and an
		// unbounded one turns a list endpoint into a way to pull every audit row the
		// caller can see in a single request.
		$safeLimit = max(1, min(200, $limit));
		$safeOffset = max(0, $offset);

		try {
			return new JSONResponse(
				$this->analyticsService->listRuns(
					agentId: $scopedAgent,
					status: $scopedStatus,
					limit: $safeLimit,
					offset: $safeOffset
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq run list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load runs'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end runs()
}//end class
