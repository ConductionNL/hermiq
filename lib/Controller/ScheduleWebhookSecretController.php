<?php

/**
 * Hermiq ScheduleWebhookSecretController.
 *
 * Session-authenticated, owner-guarded CRUD for a schedule's OUTBOUND webhook
 * signing secret (delivery-channels): mint/rotate/revoke/status. Mirrors
 * `RunNowController`'s and the inbound `AgentWebhookController`'s IDOR guard
 * shape exactly — every method loads the target Schedule WITH RBAC and
 * additionally asserts owner identity, returning 404 (never 403) for a
 * non-owner so they cannot even confirm the schedule's existence.
 *
 * Distinct from `AgentWebhookController`, which manages the per-AGENT INBOUND
 * trigger secret — this controller manages the per-SCHEDULE OUTBOUND delivery
 * secret consumed by `DeliveryService::deliverWebhook()`.
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
 * @spec openspec/changes/delivery-channels/tasks.md#task-3-schedulewebhooksecretcontroller-owner-guarded-crud
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Owner-scoped webhook-secret lifecycle endpoints for one schedule.
 *
 * @spec openspec/changes/delivery-channels/tasks.md#task-3-schedulewebhooksecretcontroller-owner-guarded-crud
 *
 * @SuppressWarnings(PHPMD.LongVariable) The promoted constructor property
 *   `$scheduleWebhookSecretService` mirrors its collaborator class name
 *   (ScheduleWebhookSecretService) — the length IS the clarity.
 */
class ScheduleWebhookSecretController extends Controller
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
     * @param IRequest                     $request                      The request object.
     * @param ObjectService                $objectService                OpenRegister object read (schedule ownership check).
     * @param IUserSession                 $userSession                  Resolves the requesting user for the owner guard.
     * @param ScheduleWebhookSecretService $scheduleWebhookSecretService The webhook secret lifecycle service.
     * @param LoggerInterface              $logger                       PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly ScheduleWebhookSecretService $scheduleWebhookSecretService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Mint a new webhook signing secret for the given schedule. The plaintext
     * secret is returned ONLY in this response body.
     *
     * @param string $id The schedule UUID.
     *
     * @return JSONResponse 201 with the plaintext secret, 404 for a
     *                      non-owner/unknown schedule, or 409 when a secret
     *                      already exists.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function create(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $schedule = $this->loadOwnedSchedule(scheduleId: $id, uid: $user->getUID());
        if ($schedule === null) {
            return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->scheduleWebhookSecretService->mint(schedule: $schedule);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq schedule webhook-secret mint failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not mint webhook secret'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            [
                'secret'     => $result['secret'],
                'configured' => true,
                'rotatedAt'  => $result['rotatedAt'],
            ],
            Http::STATUS_CREATED
        );

    }//end create()

    /**
     * Rotate the schedule's webhook signing secret, invalidating the previous
     * one immediately.
     *
     * @param string $id The schedule UUID.
     *
     * @return JSONResponse 200 with the new plaintext secret, or 404 for a
     *                      non-owner/unknown schedule/unconfigured secret.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function rotate(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $schedule = $this->loadOwnedSchedule(scheduleId: $id, uid: $user->getUID());
        if ($schedule === null) {
            return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->scheduleWebhookSecretService->rotate(schedule: $schedule);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq schedule webhook-secret rotate failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not rotate webhook secret'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(
            [
                'secret'     => $result['secret'],
                'configured' => true,
                'rotatedAt'  => $result['rotatedAt'],
            ]
        );

    }//end rotate()

    /**
     * Revoke the schedule's webhook signing secret — idempotent, never a
     * secret in the response.
     *
     * @param string $id The schedule UUID.
     *
     * @return JSONResponse The status payload (never a secret), or 404 for a
     *                      non-owner/unknown schedule.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function revoke(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $schedule = $this->loadOwnedSchedule(scheduleId: $id, uid: $user->getUID());
        if ($schedule === null) {
            return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $updated = $this->scheduleWebhookSecretService->revoke(schedule: $schedule);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq schedule webhook-secret revoke failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not revoke webhook secret'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($this->scheduleWebhookSecretService->status(schedule: $updated));

    }//end revoke()

    /**
     * Read the schedule's webhook-secret status. NEVER includes the plaintext secret.
     *
     * @param string $id The schedule UUID.
     *
     * @return JSONResponse The status payload, or 404 for a non-owner/unknown schedule.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function show(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $schedule = $this->loadOwnedSchedule(scheduleId: $id, uid: $user->getUID());
        if ($schedule === null) {
            return new JSONResponse(['error' => 'Schedule not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($this->scheduleWebhookSecretService->status(schedule: $schedule));

    }//end show()

    /**
     * Load the schedule only if the given user owns it (IDOR guard).
     *
     * Fetches WITH RBAC enabled and additionally asserts owner identity, so
     * neither a cross-tenant object nor another user's owned schedule is ever
     * returned — mirrors `RunNowController::loadOwnedSchedule()` exactly.
     *
     * @param string $scheduleId The Schedule object UUID.
     * @param string $uid        The requesting user's UID.
     *
     * @return ObjectEntity|null The owned schedule, or null when absent/not owned.
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    private function loadOwnedSchedule(string $scheduleId, string $uid): ?ObjectEntity
    {
        $schedule = $this->objectService->find(
            id: $scheduleId,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG
        );

        if (($schedule instanceof ObjectEntity) === false) {
            return null;
        }

        if ((string) ($schedule->getOwner() ?? '') !== $uid) {
            return null;
        }

        return $schedule;

    }//end loadOwnedSchedule()
}//end class
