<?php

/**
 * Hermiq AgentTemplateController.
 *
 * The agent-template-gallery surface: browse/CRUD the tenant-scoped AgentTemplate catalog,
 * export an existing Agent to a portable package, import a package (quarantined + content-
 * scanned when externally-sourced), approve a quarantined template (the review gate), and
 * "Use this template" instantiate into a real Agent. All reads/writes run in the caller's
 * session context through AgentTemplateService → OpenRegister ObjectService, so OR's native
 * RBAC scopes them to the caller's organisation — mirrors SkillController/
 * SkillMarketplaceController exactly.
 *
 * Security (ADR-005 Rule 3 / OWASP A01): `@NoAdminRequired` opens index/show/create/update/
 * destroy/export/import/instantiate to any authenticated user — tenancy (OR RBAC) is the
 * guard, exactly as SkillController's routes are. `approve()` is the one privileged mutation:
 * it gates on `ActionAuthService::requireAction()` (ADR-023), requiring
 * `agenttemplate.approve-quarantined`, and — when the caller passes `force=true` — additionally
 * `agenttemplate.override-scan-verdict` (a caller trusted to wave through a clean scan is not
 * automatically trusted to override a dangerous one). Both actions seed to admin-only.
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
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped agent-template-gallery endpoints.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
 *   distinct injected collaborator, not a logic-bearing argument list.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   A CRUD + gallery controller mirroring
 *   SkillController's (index/export/install) + SkillMarketplaceController's
 *   (installFromSource/approve) combined route surface, one method per HTTP endpoint.
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
 */
class AgentTemplateController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request            The request object.
     * @param AgentTemplateService $templateService    The template read/write/gallery path.
     * @param ActionAuthService    $actionAuth         The ADR-023 action-authorization service.
     * @param IUserSession         $userSession        Resolves the requesting user.
     * @param OrganisationMapper   $organisationMapper OpenRegister organisation lookup (instantiate's org resolution).
     * @param LoggerInterface      $logger             PSR-3 logger.
     */
    public function __construct(
        IRequest $request,
        private readonly AgentTemplateService $templateService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly OrganisationMapper $organisationMapper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the tenant's templates.
     *
     * @return JSONResponse The templates list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-1
     */
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $templates = array_map([$this, 'shape'], $this->templateService->list());
            return new JSONResponse(['results' => $templates]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-templates list failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load agent templates'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end index()

    /**
     * Get a single template.
     *
     * @param string $id The AgentTemplate UUID.
     *
     * @return JSONResponse The template, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-1
     */
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $template = $this->templateService->get(templateId: $id);
            if ($template === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $template));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template show failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load the agent template'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end show()

    /**
     * Author a new template directly — always `active`/`local`, never scanned.
     *
     * @return JSONResponse The created template, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-1
     */
    public function create(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $template = $this->templateService->create(payload: $this->request->getParams(), createdBy: $user->getUID());
            return new JSONResponse($this->shape(object: $template), Http::STATUS_CREATED);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template create failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not create the agent template: '.$e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end create()

    /**
     * Update an existing template's fields.
     *
     * @param string $id The AgentTemplate UUID.
     *
     * @return JSONResponse The updated template, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-1
     */
    public function update(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $template = $this->templateService->update(templateId: $id, payload: $this->request->getParams());
            if ($template === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($this->shape(object: $template));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template update failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not update the agent template: '.$e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end update()

    /**
     * Delete a template.
     *
     * @param string $id The AgentTemplate UUID.
     *
     * @return JSONResponse An empty success body, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-1
     */
    public function destroy(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->templateService->delete(templateId: $id);
            return new JSONResponse([]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template delete failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not delete the agent template'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end destroy()

    /**
     * Export an existing Agent to a portable template package.
     *
     * @param string $agentId The Agent UUID.
     *
     * @return JSONResponse The package string, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function export(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $package = $this->templateService->exportFromAgent(agentId: $agentId);
            if ($package === null) {
                return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['package' => $package]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template export failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end export()

    /**
     * Export a template's own portable fields to a shareable JSON package.
     *
     * @param string $id The AgentTemplate UUID.
     *
     * @return JSONResponse The package string, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
     */
    public function exportPackage(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $package = $this->templateService->exportTemplate(templateId: $id);
            if ($package === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['package' => $package]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template export-package failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end exportPackage()

    /**
     * Import a package into a new template (quarantined + content-scanned when
     * `source` is `org`/`hub`; `active` and unscanned when `local`).
     *
     * @return JSONResponse The imported template, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned
     */
    public function import(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $package = (string) $this->request->getParam('package', '');
        $source  = (string) $this->request->getParam('source', 'org');
        if (trim($package) === '') {
            return new JSONResponse(['error' => 'A non-empty package is required'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($source, ['local', 'org', 'hub'], true) === false) {
            $source = 'org';
        }

        try {
            $template = $this->templateService->importPackage(package: $package, source: $source, createdBy: $user->getUID());
            return new JSONResponse($this->shape(object: $template), Http::STATUS_CREATED);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template import failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Import failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end import()

    /**
     * Approve a quarantined template (the review gate → active; action-auth-gated).
     *
     * @param string $id The AgentTemplate UUID.
     *
     * @return JSONResponse The updated template, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action
     */
    public function approve(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $force = (bool) $this->request->getParam('force', false);

        try {
            $this->actionAuth->requireAction(user: $user, action: 'agenttemplate.approve-quarantined');
            if ($force === true) {
                // A caller who can approve a clean scan cannot necessarily override a
                // dangerous verdict — gate BEFORE calling the service with force: true.
                $this->actionAuth->requireAction(user: $user, action: 'agenttemplate.override-scan-verdict');
            }
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            $template = $this->templateService->approveQuarantined(templateId: $id, force: $force);
            if ($template === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            $shaped = $this->shape(object: $template);

            // A non-forced approve leaves the template quarantined ONLY when the content
            // scanner blocked it (a dangerous verdict) — signal 409 so the UI can present
            // the findings and offer an explicit override (mirrors SkillMarketplaceController).
            if (($shaped['state'] ?? '') === 'quarantined' && $force === false) {
                return new JSONResponse(
                    [
                        'error'            => 'Approval blocked: content scan flagged dangerous patterns.',
                        'scanReport'       => ($shaped['scanReport'] ?? []),
                        'quarantineReason' => ($shaped['quarantineReason'] ?? ''),
                        'template'         => $shaped,
                    ],
                    Http::STATUS_CONFLICT
                );
            }

            return new JSONResponse($shaped);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template approve failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Approve failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end approve()

    /**
     * "Use this template" — instantiate a template into a real Agent in the caller's
     * organisation (model coerced into the caller's effective ModelPolicy; skill refs
     * resolved best-effort; no Schedule is ever created).
     *
     * @param string $id The AgentTemplate UUID.
     *
     * @return JSONResponse The instantiate result, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-template-gallery/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-callers-model-policy
     */
    public function instantiate(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $overrides = (array) $this->request->getParam('overrides', []);

        try {
            $organisation = $this->resolveActiveOrganisation(uid: $user->getUID());
            $result       = $this->templateService->instantiate(templateId: $id, organisation: $organisation, overrides: $overrides);
            if ($result === null) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse($result, Http::STATUS_CREATED);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq agent-template instantiate failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not create an agent from this template: '.$e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

    }//end instantiate()

    /**
     * Shape an AgentTemplate ObjectEntity into a UUID + payload response map.
     *
     * @param ObjectEntity $object The template object.
     *
     * @return array<string, mixed> The response payload.
     */
    private function shape(ObjectEntity $object): array
    {
        $data         = $object->getObject();
        $data['uuid'] = (string) $object->getUuid();
        return $data;

    }//end shape()

    /**
     * Resolve the calling user's active organisation for instantiate (identity from
     * session — no request parameter accepted, so a caller can never target another
     * organisation's ModelPolicy). Falls back to '' (the instance-wide default scope)
     * when the user has no active/default organisation (mirrors
     * GuardrailPolicyController::resolveActiveOrganisation()).
     *
     * @param string $uid The requesting user's id.
     *
     * @return string The organisation identifier, or '' when none resolves.
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
