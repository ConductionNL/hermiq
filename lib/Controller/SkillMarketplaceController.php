<?php

/**
 * Hermiq SkillMarketplaceController.
 *
 * The marketplace surface (skills-marketplace): install a skill from an external source
 * (into quarantine), approve a quarantined skill (the review gate), and publish a skill to
 * an external hub via OpenConnector. All reads/writes run in the caller's session context
 * through SkillMarketplaceService → OpenRegister ObjectService, so OR's native RBAC denies
 * cross-tenant access.
 *
 * Security (ADR-005 Rule 3 / OWASP A01): `@NoAdminRequired` opens the routes to any
 * authenticated user, so the two privileged mutations are NOT open to every caller — each
 * gates on ActionAuthService::requireAction() (ADR-023), mirroring AiFeatureController.
 * `approve()` requires `skill.approve-quarantined`; when the caller passes `force=true` it
 * additionally requires the stricter `skill.override-scan-verdict` (a caller who can approve
 * a clean scan cannot necessarily override a dangerous one). `publish()` requires
 * `skill.publish-hub`. All three actions seed to admin-only; an admin may broaden them via
 * the action matrix. A refused caller gets 403; an unauthenticated caller 401.
 * `installFromSource()` only ever produces `quarantined` output (never `active`), so it
 * remains open to any authenticated tenant member.
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
 * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#2-controller
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped skills-marketplace endpoints (install-from-source / approve / publish).
 *
 * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#2-controller
 */
class SkillMarketplaceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request            The request object.
     * @param SkillMarketplaceService $marketplaceService The marketplace service.
     * @param ActionAuthService       $actionAuth         The ADR-023 action-authorization service.
     * @param IUserSession            $userSession        Resolves the requesting user.
     * @param LoggerInterface         $logger             PSR-3 logger.
     *
     * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#task-2-1
     */
    public function __construct(
        IRequest $request,
        private readonly SkillMarketplaceService $marketplaceService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Install a skill from an external source into quarantine.
     *
     * @return JSONResponse The quarantined skill, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-4-1
     */
    public function installFromSource(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $package = (string) $this->request->getParam('package', '');
        $source  = (string) $this->request->getParam('source', 'hub');
        if (trim($package) === '') {
            return new JSONResponse(['error' => 'A non-empty package is required'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($source, ['org', 'hub'], true) === false) {
            $source = 'hub';
        }

        try {
            $skill = $this->marketplaceService->installFromSource(package: $package, source: $source, createdBy: $user->getUID());
            return new JSONResponse($this->shape(object: $skill));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq install-from-source failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end installFromSource()

    /**
     * Approve a quarantined skill (the review gate → active; action-auth-gated).
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The updated skill, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/fix-skill-marketplace-action-auth/specs/skills-marketplace/spec.md#requirement-approving-a-quarantined-skill-requires-action-authorization
     * @spec openspec/changes/fix-skill-marketplace-action-auth/specs/skills-marketplace/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action
     */
    public function approve(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $force = (bool) $this->request->getParam('force', false);

        try {
            $this->actionAuth->requireAction(user: $user, action: 'skill.approve-quarantined');
            if ($force === true) {
                // A caller who can approve a clean scan cannot necessarily override a
                // dangerous verdict — gate BEFORE calling the service with force: true.
                $this->actionAuth->requireAction(user: $user, action: 'skill.override-scan-verdict');
            }
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $skill = $this->marketplaceService->approveQuarantined(skillId: $id, force: $force);
            if ($skill === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            $shaped = $this->shape(object: $skill);

            // A non-forced approve leaves the skill quarantined ONLY when the content scanner
            // blocked it (a dangerous verdict). Signal 409 so the UI can present the findings
            // (from the recorded scanReport, and always the quarantineReason) and offer an
            // explicit override. State-based detection is robust even if the store drops the
            // scanReport object.
            if (($shaped['state'] ?? '') === 'quarantined' && $force === false) {
                return new JSONResponse(
                    [
                        'error'            => 'Approval blocked: content scan flagged dangerous patterns.',
                        'scanReport'       => ($shaped['scanReport'] ?? []),
                        'quarantineReason' => ($shaped['quarantineReason'] ?? ''),
                        'skill'            => $shaped,
                    ],
                    Http::STATUS_CONFLICT
                );
            }

            return new JSONResponse($shaped);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill approve failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Approve failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end approve()

    /**
     * Publish a skill to an external hub via OpenConnector (action-auth-gated; structured
     * error when the hub is unavailable).
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The publish result, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/fix-skill-marketplace-action-auth/specs/skills-marketplace/spec.md#requirement-publishing-a-skill-to-a-hub-requires-action-authorization
     */
    public function publish(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'skill.publish-hub');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        $hubId = (string) $this->request->getParam('hubId', 'default');

        try {
            return new JSONResponse($this->marketplaceService->publishToHub(skillId: $id, hubId: $hubId));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill publish failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end publish()

    /**
     * Shape a Skill ObjectEntity into a UUID + payload response map.
     *
     * @param ObjectEntity $object The skill object.
     *
     * @return array<string, mixed> The response payload.
     */
    private function shape(ObjectEntity $object): array
    {
        $data         = $object->getObject();
        $data['uuid'] = (string) $object->getUuid();
        return $data;

    }//end shape()
}//end class
