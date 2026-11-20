<?php

/**
 * Hermiq ReportSubscriptionController.
 *
 * Subscribe and cancel on each card of the Reports page (reports-on-a-schedule
 * 1.2): `GET /api/report-subscriptions` answers the session user's
 * subscriptions as the cards' state, `POST /api/report-subscriptions/{report}`
 * sends a report weekly or monthly, and `DELETE` stops it.
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
 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\ReportSubscriptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The session user's report subscriptions.
 *
 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
 */
class ReportSubscriptionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request       The request.
	 * @param ReportSubscriptions $subscriptions The subscriptions.
	 * @param IUserSession        $userSession   The session.
	 */
	public function __construct(
		IRequest $request,
		private readonly ReportSubscriptions $subscriptions,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The session user's subscriptions, one row per report.
	 *
	 * @return JSONResponse A list of `{id, report, frequency, status}`, or 401.
	 *
	 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(call: fn (): array => $this->subscriptions->mine(person: $uid));
	}//end index()

	/**
	 * Send a report to the session user, `frequency` weekly or monthly.
	 *
	 * @param string $report The report, by its card id.
	 *
	 * @return JSONResponse The subscription, or 400, 401 or 500.
	 *
	 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
	 */
	#[NoAdminRequired]
	public function subscribe(string $report): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$frequency = (string)$this->request->getParam('frequency', '');

		return $this->answer(call: fn (): array => $this->subscriptions->subscribe(person: $uid, report: $report, frequency: $frequency));
	}//end subscribe()

	/**
	 * Stop sending a report to the session user.
	 *
	 * @param string $report The report, by its card id.
	 *
	 * @return JSONResponse `{cancelled}`, or 401 or 500.
	 *
	 * @spec openspec/changes/archive/2026-11-20-reports-on-a-schedule/tasks.md#1.2
	 */
	#[NoAdminRequired]
	public function cancel(string $report): JSONResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->answer(call: fn (): array => ['cancelled' => $this->subscriptions->cancel(person: $uid, report: $report)]);
	}//end cancel()

	/**
	 * The session user's id, or an empty string.
	 *
	 * @return string
	 */
	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * Run a call and answer it: 400 for a refused argument, 500 without detail otherwise.
	 *
	 * @param callable(): array<mixed> $call The call.
	 *
	 * @return JSONResponse
	 */
	private function answer(callable $call): JSONResponse {
		try {
			return new JSONResponse($call());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'The report subscription could not be changed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end answer()
}//end class
