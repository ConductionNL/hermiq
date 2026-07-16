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
 * hermiq-github-store adds two GitHub-store endpoints on this SAME controller
 * (catalog/discovery operations, mirroring how `import`/`export` already live here):
 * `githubSearch()` + `githubInstall()`, a close port of
 * `AgentTemplateController::githubSearch()`/`::githubInstall()` scoped to
 * `GitHubTemplateCatalogService::KIND_SKILL`. `githubInstall()` is a thin adapter in
 * front of the UNCHANGED `SkillMarketplaceService::installFromSource(source: 'hub')`
 * — no new quarantine/scan logic (design.md Decision 2). The GitHub token never
 * enters Hermiq: both endpoints only ever pass a broker `credentialId` to
 * `GitHubTemplateCatalogService`.
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
 * @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\SkillMarketplaceService;
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
     * Safe GitHub owner/repo pattern (hermiq-github-store), validated before any
     * path interpolation on the search/install endpoints — mirrors
     * `AgentTemplateController::OWNER_REPO_PATTERN` exactly.
     *
     * @var string
     */
    private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    /**
     * Safe git-ref pattern (hermiq-github-store).
     *
     * @var string
     */
    private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

    /**
     * Constructor.
     *
     * @param IRequest                     $request            The request object.
     * @param SkillService                 $skillService       The skill read/write path.
     * @param IUserSession                 $userSession        Resolves the requesting user.
     * @param LoggerInterface              $logger             PSR-3 logger.
     * @param GitHubTemplateCatalogService $catalogService     GitHub search/fetch (hermiq-github-store).
     * @param SkillMarketplaceService      $marketplaceService Quarantine install path (hermiq-github-store).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-4-1
     */
    public function __construct(
        IRequest $request,
        private readonly SkillService $skillService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly GitHubTemplateCatalogService $catalogService,
        private readonly SkillMarketplaceService $marketplaceService,
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
     * Search GitHub for `topic:hermiq-skill` repos (hermiq-github-store) — a close
     * port of `AgentTemplateController::githubSearch()` scoped to
     * `GitHubTemplateCatalogService::KIND_SKILL`.
     *
     * Login-required (in-body 401 guard). Returns the normalised, kind-tagged cards
     * plus a `brokerCredentialAvailable`/`brokerUsed`/`rateLimited` hint; never
     * exposes the raw GitHub body or any token. Degrades to HTTP 200 with an empty
     * card list on a rate-limited/unreachable GitHub call — never a 5xx for a
     * third-party outage.
     *
     * @return JSONResponse 200 with `{outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited}`; 401 anonymous.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
     */
    public function githubSearch(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $query = $this->request->getParam('q');
        if (is_string($query) === false) {
            $query = null;
        }

        try {
            $result = $this->catalogService->search(
                query: $query,
                actingUserId: $user->getUID(),
                credentialId: $this->credentialParam(),
                kind: GitHubTemplateCatalogService::KIND_SKILL
            );
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill github search failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                [
                    'outcome'                   => GitHubTemplateCatalogService::OUTCOME_UNREACHABLE,
                    'cards'                     => [],
                    'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
                    'brokerUsed'                => false,
                    'rateLimited'               => false,
                ],
                Http::STATUS_OK
            );
        }

        return new JSONResponse(
            [
                'outcome'                   => $result['outcome'],
                'cards'                     => $result['cards'],
                'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
                'brokerUsed'                => $result['brokerUsed'],
                'rateLimited'               => $result['rateLimited'],
            ],
            Http::STATUS_OK
        );

    }//end githubSearch()

    /**
     * Install a discovered GitHub skill: fetch its agentskills.io package file →
     * pass it through the UNCHANGED `SkillMarketplaceService::
     * installFromSource(source: 'hub')` path (design.md Decision 2) — the resulting
     * skill lands `state: "quarantined"` + content-scanned exactly like an
     * OpenConnector hub install. No new quarantine/scan logic is introduced here.
     *
     * @return JSONResponse 201 with the created (quarantined) skill; 400/401/404 on failure.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
     */
    public function githubInstall(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $owner  = (string) ($this->request->getParam('owner') ?? '');
        $repo   = (string) ($this->request->getParam('repo') ?? '');
        $refRaw = $this->request->getParam('ref');
        $ref    = null;
        if (is_string($refRaw) === true && $refRaw !== '') {
            $ref = $refRaw;
        }

        if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
            return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
        }

        if ($ref !== null && preg_match(self::REF_PATTERN, $ref) !== 1) {
            return new JSONResponse(['error' => 'invalid_ref'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $package = $this->catalogService->fetchPackageFile(
                kind: GitHubTemplateCatalogService::KIND_SKILL,
                owner: $owner,
                repo: $repo,
                ref: $ref,
                actingUserId: $user->getUID(),
                credentialId: $this->credentialParam()
            );
            if ($package === null) {
                return new JSONResponse(['error' => GitHubTemplateCatalogService::OUTCOME_UNREACHABLE], Http::STATUS_NOT_FOUND);
            }

            $skill = $this->marketplaceService->installFromSource(package: $package, source: 'hub', createdBy: $user->getUID());
            return new JSONResponse($this->shape(object: $skill), Http::STATUS_CREATED);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill github install failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end githubInstall()

    /**
     * Read the optional `credentialId` request param (broker upgrade) for the
     * GitHub search/install endpoints — mirrors
     * `AgentTemplateController::credentialParam()`.
     *
     * @return string|null The credential UUID, or null when absent.
     */
    private function credentialParam(): ?string
    {
        $credentialId = $this->request->getParam('credentialId');
        if (is_string($credentialId) === true && $credentialId !== '') {
            return $credentialId;
        }

        return null;

    }//end credentialParam()

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
