<?php

/**
 * Hermiq ApprovalController.
 *
 * The reviewer-facing surface for the human-approval gate (EU AI Act Art. 14):
 * list-pending-for-me, approve, deny. Approving executes the gated run (via
 * ApprovalService, which reuses ScheduleService::runNow with the gate bypassed);
 * denying records the decision and never runs.
 *
 * Security (ADR-005 Rule 3 / OWASP A01 IDOR): `@NoAdminRequired` means any
 * authenticated user can call it, so the decision endpoints MUST NOT trust the
 * {approvalId} path blindly. Because the Approval is owned by the schedule owner but
 * decided by a DIFFERENT party (the reviewer), the object is loaded RBAC-off and the
 * caller is authorised via ApprovalService::isReviewer() — the resolved reviewer user,
 * a member of the reviewer group, or an instance admin. The schedule owner is refused
 * (404) unless owner == reviewer (separation of duties).
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
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reviewer/admin-guarded approve/deny + pending-inbox endpoints.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */
class ApprovalController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request         The request object.
     * @param ApprovalService $approvalService The approval gate write-path + reviewer guard.
     * @param IUserSession    $userSession     Resolves the requesting user.
     * @param LoggerInterface $logger          PSR-3 logger.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    public function __construct(
        IRequest $request,
        private readonly ApprovalService $approvalService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the pending approvals routed to the current user as reviewer.
     *
     * @return JSONResponse The pending-approval records, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $records = $this->approvalService->listPendingForReviewer(uid: $user->getUID());
            return new JSONResponse(['results' => $records, 'total' => count($records)]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq approval inbox read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load approvals'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end index()

    /**
     * Approve a pending approval and execute the gated run.
     *
     * @param string $approvalId The Approval object UUID.
     *
     * @return JSONResponse The decision outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
     */
    public function approve(string $approvalId): JSONResponse
    {
        $guard = $this->ensureDecidableApproval(approvalId: $approvalId);
        if (($guard instanceof ObjectEntity) === false) {
            return $guard;
        }

        try {
            $result = $this->approvalService->approve(
                approval: $guard,
                deciderUid: (string) $this->userSession->getUser()?->getUID()
            );
            return new JSONResponse(
                [
                    'approvalId' => $approvalId,
                    'status'     => $result['status'],
                    'ran'        => $result['ran'],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('Hermiq approval approve failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Approve failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end approve()

    /**
     * Deny a pending approval — the gated run never executes.
     *
     * @param string $approvalId The Approval object UUID.
     *
     * @return JSONResponse The decision outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-3
     */
    public function deny(string $approvalId): JSONResponse
    {
        $guard = $this->ensureDecidableApproval(approvalId: $approvalId);
        if (($guard instanceof ObjectEntity) === false) {
            return $guard;
        }

        $reason    = $this->request->getParam('reason');
        $reasonStr = null;
        if ($reason !== null) {
            $reasonStr = (string) $reason;
        }

        try {
            $this->approvalService->deny(
                approval: $guard,
                deciderUid: (string) $this->userSession->getUser()?->getUID(),
                reason: $reasonStr
            );
            return new JSONResponse(['approvalId' => $approvalId, 'status' => 'denied']);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq approval deny failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Deny failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end deny()

    /**
     * Load a pending approval the caller is authorised to decide (shared IDOR guard).
     *
     * Returns the ObjectEntity when the caller is the resolved reviewer (or reviewer
     * group member or instance admin) and the approval is still pending; otherwise
     * returns the JSONResponse to send: 401 (unauthenticated), 404 (absent, or
     * non-reviewer — no cross-tenant leak), or 409 (already decided).
     *
     * @param string $approvalId The Approval object UUID.
     *
     * @return ObjectEntity|JSONResponse The guarded approval, or an error response.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    private function ensureDecidableApproval(string $approvalId): ObjectEntity | JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $approval = $this->approvalService->loadApproval(uuid: $approvalId);
        if ($approval === null) {
            return new JSONResponse(['error' => 'Approval not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->approvalService->isReviewer(approval: $approval, uid: $user->getUID()) === false) {
            // 404 (not 403) so a non-reviewer cannot even confirm the approval exists.
            return new JSONResponse(['error' => 'Approval not found'], Http::STATUS_NOT_FOUND);
        }

        if ((string) ($approval->getObject()['status'] ?? '') !== 'pending') {
            return new JSONResponse(['error' => 'Approval already decided'], Http::STATUS_CONFLICT);
        }

        return $approval;

    }//end ensureDecidableApproval()
}//end class
