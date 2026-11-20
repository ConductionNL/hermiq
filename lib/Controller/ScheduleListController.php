<?php

/**
 * Hermiq ScheduleListController.
 *
 * `GET /api/schedules`: the rows of the Schedules page
 * (scheduling-overview-and-start-choice D1), the schedules the caller may see,
 * filterable by agent, enabled and a failed last result. Which rows the caller
 * may see is decided per row in ScheduleListService.
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
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\Schedule\ScheduleListService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the schedules the caller may see.
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
 */
class ScheduleListController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param IUserSession        $userSession The caller.
	 * @param ScheduleListService $schedules   The visible rows.
	 * @param IL10N               $l10n        The caller's language, for the readable "when".
	 * @param LoggerInterface     $logger      Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ScheduleListService $schedules,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The schedules the caller may see.
	 *
	 * @param string $agentId Only this agent's schedules ('' for all).
	 * @param string $enabled `true` or `false` to filter on enabled ('' for both).
	 * @param string $failed  `true` for a failed last result only, `false` for the rest ('' for both).
	 *
	 * @return JSONResponse 200 `{results, total}`; 401 without a session; 500 when the list cannot be read.
	 *
	 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-every-schedule-a-person-may-see-is-on-one-page-req-sched-001
	 */
	#[NoAdminRequired]
	public function index(string $agentId = '', string $enabled = '', string $failed = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$filters = [
			'agentId' => trim($agentId),
			'enabled' => $this->flag(value: $enabled),
			'failed' => $this->flag(value: $failed),
		];

		$lang = 'en';
		if (str_starts_with(strtolower($this->l10n->getLanguageCode()), 'nl') === true) {
			$lang = 'nl';
		}

		try {
			$rows = $this->schedules->list(user: $user, filters: $filters, lang: $lang);
		} catch (Throwable $e) {
			$this->logger->error(message: 'Hermiq schedule list failed: ' . $e->getMessage(), context: ['exception' => $e]);
			return new JSONResponse(data: ['error' => 'Could not load schedules'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: ['results' => $rows, 'total' => count($rows)]);
	}//end index()

	/**
	 * A query flag: true, false or not set.
	 *
	 * @param string $value The query value.
	 *
	 * @return bool|null
	 */
	private function flag(string $value): ?bool {
		return match (strtolower(trim($value))) {
			'true', '1' => true,
			'false', '0' => false,
			default => null,
		};
	}//end flag()
}//end class
