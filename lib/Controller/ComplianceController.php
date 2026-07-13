<?php

/**
 * Hermiq ComplianceController.
 *
 * The compliance-control-packs read surface: the org-scoped compliance dashboard,
 * the auditor's-pack export (wraps the existing Art. 12 export), and the per-agent AI
 * factsheet. All three endpoints are read-only (no state mutation).
 *
 * Security (ADR-005 Rule 3 / OWASP A01): dashboard/export aggregate cross-agent,
 * org-wide governance data, so both are gated through `ActionAuthService::requireAction()`
 * (`compliance.view-dashboard`/`compliance.export-pack`, ADR-023). The factsheet
 * endpoint takes a caller-supplied `{agentId}`, so it MUST NOT trust it blindly — it
 * loads the Agent RBAC-off and admits the caller only when they are the agent's own
 * `owner`/`actingUser`, or hold `compliance.view-factsheet`, refusing with 404 (not
 * 403) otherwise — mirroring `ApprovalController::ensureDecidableApproval()`'s
 * anti-probing posture (a non-owner cannot even confirm the agent exists).
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
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ComplianceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compliance dashboard + export (action-auth-gated) and per-agent factsheet
 * (ownership-or-action-auth-gated, 404-not-403 IDOR guard).
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
 *   distinct injected collaborator, not a logic-bearing argument list.
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
 */
class ComplianceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request            The request object.
     * @param ComplianceService  $complianceService  Computed dashboard/export/factsheet reads.
     * @param IUserSession       $userSession        Resolves the requesting user.
     * @param ActionAuthService  $actionAuth         ADR-023 action-authorization gate.
     * @param OrganisationMapper $organisationMapper Resolves the caller's active organisation
     *                                               (mirrors TenantModelPolicyController).
     * @param LoggerInterface    $logger             PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ComplianceService $complianceService,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The caller's organisation's compliance dashboard: per-framework coverage +
     * gap list (`compliance.view-dashboard`-gated).
     *
     * @return JSONResponse The dashboard payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
     */
    public function dashboard(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'compliance.view-dashboard');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $organisation = $this->resolveActiveOrganisation(uid: $user->getUID());
            return new JSONResponse($this->complianceService->dashboard(organisation: $organisation));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq compliance dashboard read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load the compliance dashboard'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end dashboard()

    /**
     * The auditor's-pack export: the unmodified Art. 12 export nested alongside
     * the compliance coverage data (`compliance.export-pack`-gated).
     *
     * @return JSONResponse The export payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
     */
    public function export(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'compliance.export-pack');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $organisation = $this->resolveActiveOrganisation(uid: $user->getUID());
            $response     = new JSONResponse($this->complianceService->auditorPack(organisation: $organisation));
            $response->addHeader('Content-Disposition', 'attachment; filename="hermiq-compliance-auditor-pack.json"');
            return $response;
        } catch (Throwable $e) {
            $this->logger->error('Hermiq compliance export failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not produce the compliance export'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end export()

    /**
     * A single agent's AI factsheet: owner/actingUser self-service, or
     * `compliance.view-factsheet` for a DPO/admin viewing someone else's agent.
     * Refuses with 404 (not 403) for both the missing-agent and
     * unauthorized-caller cases (anti-probing).
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The factsheet, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
     */
    public function factsheet(string $agentId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->complianceService->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->maySeeFactsheet(agent: $agent, user: $user) === false) {
            // 404 (not 403) so a non-owner/non-authorized caller cannot even
            // confirm the agent exists — mirrors ApprovalController.
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $factsheet = $this->complianceService->factsheet(agentId: $agentId);
            if ($factsheet === null) {
                return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($factsheet);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent factsheet read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load the agent factsheet'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end factsheet()

    /**
     * Whether the caller may view the agent's factsheet: the agent's own
     * `owner`/`actingUser` (self-service), or `compliance.view-factsheet`
     * (DPO/admin viewing someone else's agent).
     *
     * @param ObjectEntity $agent The agent object.
     * @param IUser        $user  The requesting user.
     *
     * @return bool
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
     */
    private function maySeeFactsheet(ObjectEntity $agent, IUser $user): bool
    {
        $uid = $user->getUID();

        if ($agent->getOwner() === $uid) {
            return true;
        }

        $data = $agent->getObject();
        if ((string) ($data['actingUser'] ?? '') === $uid) {
            return true;
        }

        return $this->actionAuth->can(user: $user, action: 'compliance.view-factsheet');

    }//end maySeeFactsheet()

    /**
     * Resolve the calling user's active organisation (identity from session — no
     * request parameter). Falls back to '' when the user has no active/default
     * organisation, mirroring `TenantModelPolicyController::resolveActiveOrganisation()`.
     *
     * @param string $uid The requesting user's id.
     *
     * @return string The organisation identifier, or '' when none resolves.
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
     */
    private function resolveActiveOrganisation(string $uid): string
    {
        try {
            if (method_exists($this->organisationMapper, 'getActiveOrganisationWithFallback') === true) {
                return (string) ($this->organisationMapper->getActiveOrganisationWithFallback($uid) ?? '');
            }
        } catch (Throwable $e) {
            $this->logger->warning('Hermiq could not resolve active organisation: '.$e->getMessage(), ['exception' => $e]);
        }

        return '';

    }//end resolveActiveOrganisation()
}//end class
