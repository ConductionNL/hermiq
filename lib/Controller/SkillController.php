<?php

/**
 * Hermiq SkillController.
 *
 * Browse the tenant-scoped skills catalog, import/export agentskills.io packages, and
 * install a skill onto an agent. All reads/writes run in the caller's session context
 * through SkillService → OpenRegister ObjectService, so OR's native RBAC denies
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
 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped skills catalog endpoints.
 *
 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
 */
class SkillController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request      The request object.
     * @param SkillService    $skillService The skill read/write path.
     * @param IUserSession    $userSession  Resolves the requesting user.
     * @param LoggerInterface $logger       PSR-3 logger.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-4-1
     */
    public function __construct(
        IRequest $request,
        private readonly SkillService $skillService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the tenant's skills.
     *
     * @return JSONResponse The skills list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-4-1
     */
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $skills = array_map([$this, 'shape'], $this->skillService->listSkills());
            return new JSONResponse(['results' => $skills]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skills list failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load skills'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end index()

    /**
     * Import an agentskills.io package into a new Skill.
     *
     * @return JSONResponse The imported skill, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-4-1
     */
    public function import(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $package = (string) $this->request->getParam('package', '');
        if (trim($package) === '') {
            return new JSONResponse(['error' => 'A non-empty package is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $skill = $this->skillService->importSkill(package: $package, createdBy: $user->getUID());
            return new JSONResponse($this->shape(object: $skill));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill import failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Import failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end import()

    /**
     * Export a Skill back to an agentskills.io package.
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The package string, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-4-1
     */
    public function export(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $package = $this->skillService->exportSkill(skillId: $id);
            if ($package === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['package' => $package]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill export failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end export()

    /**
     * Install a Skill onto an agent (records the agent on installedOn).
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The updated skill, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-4-1
     */
    public function install(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agentId = trim((string) $this->request->getParam('agentId', ''));
        if ($agentId === '') {
            return new JSONResponse(['error' => 'An agentId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $skill = $this->skillService->installOnAgent(skillId: $id, agentId: $agentId);
            if ($skill === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $skill));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill install failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end install()

    /**
     * Detach a Skill from an agent (removes the agent from installedOn).
     *
     * @param string $id      The Skill UUID.
     * @param string $agentId The agent UUID (route param).
     *
     * @return JSONResponse The updated skill, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
     */
    public function uninstall(string $id, string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (trim($agentId) === '') {
            return new JSONResponse(['error' => 'An agentId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $skill = $this->skillService->uninstallFromAgent(skillId: $id, agentId: $agentId);
            if ($skill === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $skill));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill uninstall failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Uninstall failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end uninstall()

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
