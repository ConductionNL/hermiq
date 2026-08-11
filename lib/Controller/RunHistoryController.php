<?php

/**
 * Hermiq RunHistoryController.
 *
 * The owner-scoped read surface for run-audit-log. Exposes one authenticated GET
 * that returns a schedule's run history — the explicit per-run OpenRegister
 * AuditTrail entries written by ScheduleService — as compact run records.
 *
 * Security (ADR-005 Rule 3 / OWASP A01 IDOR): `@NoAdminRequired` means any
 * authenticated user can call it, so the method MUST NOT trust the {scheduleId}
 * path blindly. It loads the Schedule object WITH RBAC on and refuses (404) unless
 * the requesting user is the schedule owner — a non-owner never sees another
 * tenant's audit trail.
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
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\EngineRequiredException;
use OCA\Hermiq\Service\RunHistoryService;
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
 * Owner-scoped run-history read endpoint over OpenRegister's AuditTrail.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#3-run-history-read-surface
 * @spec openspec/changes/run-trace-observability/tasks.md#task-5-runhistorycontrollertrace-route
 */
class RunHistoryController extends Controller
{

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
     * @param IRequest          $request           The request object.
     * @param ObjectService     $objectService     OpenRegister object read (ownership check).
     * @param IUserSession      $userSession       Resolves the requesting user for the owner guard.
     * @param RunHistoryService $runHistoryService Reads and shapes the schedule's run records.
     * @param ScheduleService   $scheduleService   Executes the replay run + gate checks
     *                                             (run-replay-and-dry-run).
     * @param LoggerInterface   $logger            PSR-3 logger.
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-2
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly RunHistoryService $runHistoryService,
        private readonly ScheduleService $scheduleService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the run history for a schedule the caller owns.
     *
     * @param string $scheduleId The Schedule object UUID.
     *
     * @return JSONResponse The run records (newest-first) or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-2
     */
    public function index(string $scheduleId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $limit  = (int) ($this->request->getParam('limit', 20));
        $offset = (int) ($this->request->getParam('offset', 0));

        try {
            $schedule = $this->loadOwnedSchedule(scheduleId: $scheduleId, uid: $user->getUID());
            if ($schedule === null) {
                // 404 (not 403) so a non-owner cannot even confirm the schedule exists.
                return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
            }

            $runs = $this->runHistoryService->getRunHistory(
                scheduleUuid: $scheduleId,
                limit: $limit,
                offset: $offset
            );

            return new JSONResponse(['results' => $runs, 'total' => count($runs)]);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq run-history read failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(['error' => 'Could not load run history'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end index()

    /**
     * Return one run's full, redacted step timeline for a schedule the caller owns
     * (run-trace-observability).
     *
     * Reuses the IDENTICAL owner guard `index()` already uses: 404 (not 403) for
     * both a non-existent schedule and a schedule the caller does not own, so a
     * non-owner cannot even confirm the schedule — let alone the run — exists.
     *
     * @param string $scheduleId The Schedule object UUID.
     * @param string $runId      The run's AuditTrail entry UUID.
     *
     * @return JSONResponse The run's trace, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/run-trace-observability/tasks.md#task-5-runhistorycontrollertrace-route
     */
    public function trace(string $scheduleId, string $runId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $schedule = $this->loadOwnedSchedule(scheduleId: $scheduleId, uid: $user->getUID());
            if ($schedule === null) {
                // 404 (not 403) so a non-owner cannot even confirm the schedule exists.
                return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
            }

            $trace = $this->runHistoryService->getRunTrace(scheduleUuid: $scheduleId, runId: $runId);
            if ($trace === null) {
                return new JSONResponse(['error' => 'Run not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($trace);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hermiq run-trace read failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(['error' => 'Could not load run trace'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end trace()

    /**
     * Replay a past run's exact recorded prompt as a fresh dry-run and return a
     * step-by-step diff against what actually happened (run-replay-and-dry-run).
     * Reuses the IDENTICAL owner guard `trace()` already uses: 404 (not 403)
     * for both a non-existent schedule and a schedule the caller does not own,
     * AND for a run that predates prompt persistence (never available for
     * replay) — a non-owner cannot even confirm the schedule/run exists.
     *
     * @param string $scheduleId The Schedule object UUID.
     * @param string $runId      The run's AuditTrail entry UUID to replay.
     *
     * @return JSONResponse The replay outcome, a governance-gate refusal (409), or
     *                      a feature-flag-required error (422).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome
     */
    public function replay(string $scheduleId, string $runId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $schedule = $this->loadOwnedSchedule(scheduleId: $scheduleId, uid: $user->getUID());
        if ($schedule === null) {
            // 404 (not 403) so a non-owner cannot even confirm the schedule exists.
            return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
        }

        $originalTrace = $this->runHistoryService->getRunTrace(scheduleUuid: $scheduleId, runId: $runId);
        if ($originalTrace === null) {
            return new JSONResponse(['error' => 'Run not found'], Http::STATUS_NOT_FOUND);
        }

        $originalPrompt = ($originalTrace['prompt'] ?? null);
        if (is_string($originalPrompt) === false || $originalPrompt === '') {
            return new JSONResponse(
                ['error' => 'This run is not available for replay — it was recorded before replay support shipped.'],
                Http::STATUS_NOT_FOUND
            );
        }

        try {
            $result = $this->scheduleService->replayRun(schedule: $schedule, runId: $runId, originalTrace: $originalTrace);
        } catch (EngineRequiredException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq replay failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not replay this run'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($result['status'] === 'blocked') {
            return new JSONResponse(
                ['error' => 'Blocked by governance', 'gate' => ($result['gate'] ?? null)],
                Http::STATUS_CONFLICT
            );
        }

        return new JSONResponse($result);

    }//end replay()

    /**
     * Load the schedule only if the given user owns it (IDOR guard).
     *
     * Fetches WITH RBAC enabled and additionally asserts owner identity, so neither
     * a cross-tenant object nor another user's owned schedule is ever returned.
     *
     * @param string $scheduleId The Schedule object UUID.
     * @param string $uid        The requesting user's UID.
     *
     * @return ObjectEntity|null The owned schedule, or null when absent/not owned.
     *
     * @spec openspec/changes/run-audit-log/tasks.md#task-3-2
     */
    private function loadOwnedSchedule(string $scheduleId, string $uid): ?ObjectEntity
    {
        try {
            $schedule = $this->objectService->find(
                id: $scheduleId,
                register: self::REGISTER_SLUG,
                schema: self::SCHEMA_SLUG
            );
        } catch (Throwable $e) {
            // `ObjectService::find()` throws when the object is absent, and
            // `replay()` calls this helper OUTSIDE its own try block — so the
            // throw would escape as a framework 500 with a stack trace on a
            // #[NoAdminRequired] route. A schedule that cannot be loaded is, to a
            // caller, not owned; null already carries that meaning.
            $this->logger->warning(
                'Hermiq schedule lookup failed for '.$scheduleId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }//end try

        if (($schedule instanceof ObjectEntity) === false) {
            return null;
        }

        if ((string) ($schedule->getOwner() ?? '') !== $uid) {
            return null;
        }

        return $schedule;

    }//end loadOwnedSchedule()
}//end class
