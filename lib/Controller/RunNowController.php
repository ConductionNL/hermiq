<?php

/**
 * Hermiq RunNowController.
 *
 * The thin "Run now" action for agent-management-ui: a single owner-guarded POST
 * that runs a schedule's bound OpenRegister agent immediately, reusing
 * ScheduleService's existing dispatch (run-one) path. Hermiq owns no agent/LLM
 * engine and no schedule CRUD — this is the one net-new backend action the UI
 * needs because OpenRegister exposes no agent-trigger endpoint (ADR-001).
 *
 * Security (ADR-005 Rule 3 / OWASP A01 IDOR): `@NoAdminRequired` means any
 * authenticated user can call it, so the method MUST NOT trust the {scheduleId}
 * path blindly. It loads the Schedule object WITH RBAC on and refuses (404)
 * unless the requesting user is the schedule owner — a non-owner can neither run
 * nor confirm the existence of another tenant's schedule (mirrors
 * RunHistoryController).
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
 * @spec openspec/changes/agent-management-ui/tasks.md#1-backend-thin-run-now-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\EngineRequiredException;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owner-scoped "Run now" endpoint that fires a schedule via ScheduleService.
 *
 * @spec openspec/changes/agent-management-ui/tasks.md#1-backend-thin-run-now-endpoint
 */
class RunNowController extends Controller {

	/**
	 * OpenRegister register slug that holds Hermiq schedule objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for schedule objects.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'schedule';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ObjectService $objectService OpenRegister object read (ownership check + status re-read).
	 * @param IUserSession $userSession Resolves the requesting user for the owner guard.
	 * @param ScheduleService $scheduleService Runs the schedule via the shared dispatch path.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @spec openspec/changes/agent-management-ui/tasks.md#task-1-2
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly ScheduleService $scheduleService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Run the given schedule's agent immediately and return its outcome.
	 *
	 * @param string $scheduleId The Schedule object UUID.
	 *
	 * @return JSONResponse The updated run status, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-management-ui/tasks.md#task-1-2
	 */
	public function run(string $scheduleId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$schedule = $this->loadOwnedSchedule(scheduleId: $scheduleId, uid: $user->getUID());
		if ($schedule === null) {
			// 404 (not 403) so a non-owner cannot even confirm the schedule exists.
			return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->scheduleService->runNow(schedule: $schedule);
		} catch (Throwable $e) {
			// Catastrophic failure (agent-turn errors are handled inside dispatch()).
			$this->logger->error(
				'Hermiq run-now failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				['error' => 'Run failed', 'message' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		// Re-read the schedule so the UI gets the just-written run status (including a
		// recorded lastStatus='error' from an OpenRegister agent-execution failure).
		$fresh = $this->loadOwnedSchedule(scheduleId: $scheduleId, uid: $user->getUID());
		$data = [];
		if ($fresh !== null) {
			$data = $fresh->getObject();
		}

		return new JSONResponse(
			[
				'scheduleId' => $scheduleId,
				'status' => ($data['lastStatus'] ?? null),
				'error' => ($data['lastError'] ?? null),
				'nextRun' => ($data['nextRun'] ?? null),
			]
		);

	}//end run()

	/**
	 * Preview the given schedule's agent run as a dry-run: side-effecting tool
	 * calls are neutralised instead of actually invoked (run-replay-and-dry-run).
	 * Same owner guard as `run()` — a non-owner gets a 404, never a 403.
	 *
	 * @param string $scheduleId The Schedule object UUID.
	 *
	 * @return JSONResponse The dry-run outcome, a governance-gate refusal (409), or
	 *                      a feature-flag-required error (422).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls
	 */
	public function dryRun(string $scheduleId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$schedule = $this->loadOwnedSchedule(scheduleId: $scheduleId, uid: $user->getUID());
		if ($schedule === null) {
			// 404 (not 403) so a non-owner cannot even confirm the schedule exists.
			return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->scheduleService->dryRunNow(schedule: $schedule);
		} catch (EngineRequiredException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 422);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq dry-run failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				['error' => 'Dry-run failed', 'message' => $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($result['status'] === 'blocked') {
			return new JSONResponse(
				['error' => 'Blocked by governance', 'gate' => ($result['gate'] ?? null)],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(
			[
				'scheduleId' => $scheduleId,
				'dryRun' => true,
				'status' => $result['status'],
				'error' => ($result['error'] ?? null),
				'steps' => ($result['steps'] ?? []),
				'summary' => ($result['summary'] ?? null),
			]
		);

	}//end dryRun()

	/**
	 * Load the schedule only if the given user owns it (IDOR guard).
	 *
	 * Fetches WITH RBAC enabled and additionally asserts owner identity, so neither
	 * a cross-tenant object nor another user's owned schedule is ever returned or run.
	 *
	 * @param string $scheduleId The Schedule object UUID.
	 * @param string $uid The requesting user's UID.
	 *
	 * @return ObjectEntity|null The owned schedule, or null when absent/not owned.
	 *
	 * @spec openspec/changes/agent-management-ui/tasks.md#task-1-2
	 */
	private function loadOwnedSchedule(string $scheduleId, string $uid): ?ObjectEntity {
		try {
			$schedule = $this->objectService->find(
				id: $scheduleId,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG
			);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent, and
			// `run()`/`dryRun()` call this helper OUTSIDE their own try block —
			// so the throw would escape as a framework 500 with a stack trace on
			// a #[NoAdminRequired] route. A schedule that cannot be loaded is, to
			// a caller, not owned; null already carries that meaning.
			$this->logger->warning(
				'Hermiq schedule lookup failed for ' . $scheduleId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}//end try

		if (($schedule instanceof ObjectEntity) === false) {
			return null;
		}

		if ((string)($schedule->getOwner() ?? '') !== $uid) {
			return null;
		}

		return $schedule;
	}//end loadOwnedSchedule()
}//end class
