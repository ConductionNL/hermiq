<?php

/**
 * Hermiq TenantControlController.
 *
 * The org-admin surface for the per-organisation kill-switch (EU AI Act Art. 14 stop
 * mechanism): read the current state and engage/disengage it. Engaging halts every
 * agent run for that organisation on the next dispatch tick, synchronously.
 *
 * Security (ADR-005 / OWASP A01): `@NoAdminRequired` opens the route to any
 * authenticated user, so the method body is the guard. A toggle is admitted ONLY for
 * a Nextcloud instance admin (`IGroupManager::isAdmin`) or a sub-admin of the NC group
 * that maps to the organisation (`ISubAdmin::isSubAdminOfGroup`). A plain schedule
 * owner or a foreign-org admin is refused. Org→group mapping assumption: the tenant
 * `organisation` value equals the NC group id (OpenRegister multi-tenancy is built on
 * NC groups, ADR-001); if OpenRegister later exposes an explicit org→group resolver,
 * prefer it over this assumption.
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
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#5-kill-switch-toggle-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\TenantControlService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Org-subadmin/instance-admin-guarded kill-switch read + toggle endpoints.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#5-kill-switch-toggle-endpoint
 */
class TenantControlController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request              The request object.
     * @param TenantControlService $tenantControlService The kill-switch read/write path.
     * @param IUserSession         $userSession          Resolves the requesting user.
     * @param IGroupManager        $groupManager         Instance-admin + org-group resolution.
     * @param ISubAdmin            $subAdmin             Group sub-admin check.
     * @param LoggerInterface      $logger               PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: distinct collaborators.
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function __construct(
        IRequest $request,
        private readonly TenantControlService $tenantControlService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ISubAdmin $subAdmin,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Show the current kill-switch state for an organisation the caller administers.
     *
     * @param string $organisation The organisation identifier (== NC group id).
     *
     * @return JSONResponse The control state, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function show(string $organisation): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
            // 404 (not 403) so a non-admin cannot probe another organisation's state.
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $control = $this->tenantControlService->getForOrganisation(organisation: $organisation);
            return new JSONResponse($this->shape(organisation: $organisation, control: $control));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq kill-switch read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load kill-switch'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end show()

    /**
     * Engage or disengage the kill-switch for an organisation.
     *
     * @param string $organisation The organisation identifier (== NC group id).
     *
     * @return JSONResponse The new control state, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    public function toggle(string $organisation): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $engaged   = filter_var($this->request->getParam('engaged'), FILTER_VALIDATE_BOOLEAN);
        $reason    = $this->request->getParam('reason');
        $reasonStr = null;
        if ($reason !== null) {
            $reasonStr = (string) $reason;
        }

        try {
            $control = $this->tenantControlService->toggle(
                organisation: $organisation,
                engaged: $engaged,
                reason: $reasonStr,
                actorUid: $user->getUID()
            );
            return new JSONResponse($this->shape(organisation: $organisation, control: $control));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq kill-switch toggle failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Toggle failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end toggle()

    /**
     * Whether the user may administer the organisation's kill-switch.
     *
     * Instance admin OR sub-admin of the organisation's NC group (org == group id
     * assumption). A user who administers no matching group is refused.
     *
     * @param string $organisation The organisation identifier.
     * @param IUser  $user         The requesting user.
     *
     * @return bool
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-5-1
     */
    private function mayAdminister(string $organisation, IUser $user): bool
    {
        if ($organisation === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($user->getUID()) === true) {
            return true;
        }

        $group = $this->groupManager->get($organisation);
        if ($group === null) {
            return false;
        }

        return $this->subAdmin->isSubAdminOfGroup($user, $group);

    }//end mayAdminister()

    /**
     * Shape a TenantControl object (or its absence) into a response payload.
     *
     * @param string            $organisation The organisation identifier.
     * @param ObjectEntity|null $control      The control object, or null when none exists.
     *
     * @return array<string, mixed> The response payload.
     */
    private function shape(string $organisation, ?ObjectEntity $control): array
    {
        if ($control === null) {
            return [
                'organisation' => $organisation,
                'engaged'      => false,
                'reason'       => null,
                'engagedBy'    => null,
                'engagedAt'    => null,
            ];
        }

        $data = $control->getObject();
        return [
            'organisation' => $organisation,
            'engaged'      => (bool) ($data['engaged'] ?? false),
            'reason'       => ($data['reason'] ?? null),
            'engagedBy'    => ($data['engagedBy'] ?? null),
            'engagedAt'    => ($data['engagedAt'] ?? null),
        ];

    }//end shape()
}//end class
