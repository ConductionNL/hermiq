<?php

/**
 * Hermiq TenantOpsController.
 *
 * The org-operations surface (multi-tenant-ops): report the caller's per-organisation quota
 * usage and produce a per-tenant EU AI Act audit export. Both are tenant-scoped inside
 * TenantOpsService (they only ever read the caller's own Hermiq objects and their audit),
 * so no cross-tenant data leaks. `@NoAdminRequired` opens the routes to any authenticated
 * user; tenancy is the guard (the UI additionally gates visibility to org owners/admins).
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
 * @spec openspec/changes/multi-tenant-ops/tasks.md#2-controller-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\TenantOpsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped quota + AI Act audit-export endpoints.
 *
 * @spec openspec/changes/multi-tenant-ops/tasks.md#2-controller-routes
 */
class TenantOpsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request object.
     * @param TenantOpsService $tenantOpsService The tenant-ops read service.
     * @param IUserSession     $userSession      Resolves the requesting user.
     * @param LoggerInterface  $logger           PSR-3 logger.
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-2-1
     */
    public function __construct(
        IRequest $request,
        private readonly TenantOpsService $tenantOpsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Report the caller's organisation quota usage against the configured limits.
     *
     * @return JSONResponse The quota status, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-2-1
     */
    public function quota(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            return new JSONResponse($this->tenantOpsService->quotaStatus());
        } catch (Throwable $e) {
            $this->logger->error('Hermiq quota status failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load quota'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end quota()

    /**
     * Produce a per-tenant EU AI Act audit export (JSON) for the caller's organisation.
     *
     * @return JSONResponse The audit export, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/multi-tenant-ops/tasks.md#task-2-1
     */
    public function auditExport(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $response = new JSONResponse($this->tenantOpsService->exportAuditTrail());
            $response->addHeader('Content-Disposition', 'attachment; filename="hermiq-ai-act-audit.json"');
            return $response;
        } catch (Throwable $e) {
            $this->logger->error('Hermiq audit export failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not produce export'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end auditExport()
}//end class
