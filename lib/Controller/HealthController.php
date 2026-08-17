<?php

/**
 * Hermiq Health Controller
 *
 * Public health check endpoint (ADR-006).
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
 *
 * @spec openspec/specs/observability/spec.md#REQ-OBS-002
 *   (Illustrative stub per ADR-006 — every app MUST expose `GET /api/health`
 *   returning JSON, publicly accessible. Health check MUST verify OpenRegister
 *   connectivity for apps that depend on it.)
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Public health check endpoint (ADR-006).
 *
 * Verifies OpenRegister connectivity. Returns 200 when healthy, 503 otherwise.
 * Public (`@PublicPage` + `@NoCSRFRequired`) so external probes (Prometheus
 * blackbox exporter, K8s liveness/readiness) can poll without auth.
 *
 * @spec openspec/specs/observability/spec.md
 */
class HealthController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param SettingsService $settingsService For OpenRegister availability check
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec openspec/specs/observability/spec.md#REQ-OBS-002
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Health check JSON. Public endpoint.
	 *
	 * The rate limit is deliberately generous: monitoring polls this on a short
	 * interval, and a ceiling that trips on a normal probe cadence turns the
	 * health check into the outage it was meant to detect.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/observability/spec.md#REQ-OBS-002
	 */
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(): JSONResponse {
		try {
			$openRegister = $this->settingsService->isOpenRegisterAvailable();
			$status = 'degraded';
			$httpStatus = Http::STATUS_SERVICE_UNAVAILABLE;
			if ($openRegister === true) {
				$status = 'ok';
				$httpStatus = Http::STATUS_OK;
			}

			return new JSONResponse(
				[
					'status' => $status,
					'app' => Application::APP_ID,
					'version' => '0.1.0',
					'dependencies' => [
						'openregister' => $openRegister,
					],
				],
				$httpStatus
			);
		} catch (\Throwable $e) {
			$this->logger->error('Hermiq: health check failed', ['exception' => $e]);
			return new JSONResponse(
				['status' => 'error', 'message' => 'Health check failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end index()
}//end class
