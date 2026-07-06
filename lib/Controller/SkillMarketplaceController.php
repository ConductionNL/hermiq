<?php

/**
 * Hermiq SkillMarketplaceController.
 *
 * The marketplace surface (skills-marketplace): install a skill from an external source
 * (into quarantine), approve a quarantined skill (the review gate), and publish a skill to
 * an external hub via OpenConnector. All reads/writes run in the caller's session context
 * through SkillMarketplaceService → OpenRegister ObjectService, so OR's native RBAC denies
 * cross-tenant access. `@NoAdminRequired` opens the routes to any authenticated user;
 * tenancy is the guard.
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
 * @spec openspec/changes/skills-marketplace/tasks.md#4-controller-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped skills-marketplace endpoints (install-from-source / approve / publish).
 *
 * @spec openspec/changes/skills-marketplace/tasks.md#4-controller-routes
 */
class SkillMarketplaceController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request            The request object.
     * @param SkillMarketplaceService $marketplaceService The marketplace service.
     * @param IUserSession            $userSession        Resolves the requesting user.
     * @param LoggerInterface         $logger             PSR-3 logger.
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-4-1
     */
    public function __construct(
        IRequest $request,
        private readonly SkillMarketplaceService $marketplaceService,
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
     * Approve a quarantined skill (the review gate → active).
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The updated skill, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-4-1
     */
    public function approve(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $force = (bool) $this->request->getParam('force', false);
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
     * Publish a skill to an external hub via OpenConnector (structured error when unavailable).
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The publish result, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-marketplace/tasks.md#task-4-1
     */
    public function publish(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
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
