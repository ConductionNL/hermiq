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
 * hermiq-github-store adds `githubPublish()` on this SAME controller — the new PRIMARY
 * publish path (a `topic:hermiq-skill` GitHub repo in agentskills.io format via the
 * generalised `GitHubTemplatePushService`), gated by the SAME `skill.publish-hub` action as
 * the existing OpenConnector `publish()` (both are "publish this skill externally"
 * mutations — design.md does not introduce a distinct action for the GitHub path).
 * Tenant-scoped via `SkillService::exportSkill()` (404, never 403, for an out-of-visibility
 * skill) exactly as `AgentTemplateController::publishGithub()` scopes template publish. On
 * success, stamps `githubOwner`/`githubRepo`/`publishedAt` via
 * `SkillService::stampGithubPublish()` — provenance only, never round-tripped through the
 * exported package.
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
 * @spec openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\FederatedStoreService;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tenant-scoped skills-marketplace endpoints (install-from-source / approve / publish).
 *
 * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#2-controller
 */
class SkillMarketplaceController extends Controller
{
    /**
     * Safe GitHub owner/repo pattern (hermiq-github-store), validated before any
     * path interpolation on the publish endpoint — mirrors
     * `AgentTemplateController::OWNER_REPO_PATTERN` exactly.
     *
     * @var string
     */
    private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    /**
     * Constructor.
     *
     * @param IRequest                $request            The request object.
     * @param SkillMarketplaceService $marketplaceService The marketplace service.
     * @param ActionAuthService       $actionAuth         The ADR-023 action-authorization service.
     * @param IUserSession            $userSession        Resolves the requesting user.
     * @param LoggerInterface         $logger             PSR-3 logger.
     * @param SkillService            $skillService       Export + provenance stamp (hermiq-github-store).
     * @param FederatedStoreService   $store              The federated store adapter (publish via the shared engine).
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list.
     *
     * @spec openspec/changes/fix-skill-marketplace-action-auth/tasks.md#task-2-1
     */
    public function __construct(
        IRequest $request,
        private readonly SkillMarketplaceService $marketplaceService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly SkillService $skillService,
        private readonly FederatedStoreService $store,
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

        // Hermiq-skill-conversational-authoring: 'local' is the honest provenance for a
        // skill authored inside this instance (the chat "Save as skill" seam) — an
        // already-valid `source` enum value, no schema change. installFromSource() still
        // ALWAYS lands the skill `quarantined` regardless of source.
        if (in_array($source, ['local', 'org', 'hub'], true) === false) {
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
     * Publish a skill to a NEW GitHub repository tagged `topic:hermiq-skill`, built
     * from `SkillSerializer::toPackage()` output via `SkillService::exportSkill()`
     * (hermiq-github-store — the new PRIMARY publish path; OpenConnector's
     * `publish()` above remains the secondary route). Gated by the SAME
     * `skill.publish-hub` action as `publish()` — both are "publish this skill
     * externally" mutations. Tenant-scoped: resolves the skill through the same
     * `SkillService::exportSkill()` path `export()` already uses, so a caller
     * cannot publish a skill outside their organisation's visibility. On success,
     * records `githubOwner`/`githubRepo`/`publishedAt` on the skill — provenance
     * only, never round-tripped through the exported package.
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse 201 with `{repoUrl, commitSha}`; 400/401/404/422/503 on failure.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/hermiq-github-store/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
     */
    public function githubPublish(string $id): JSONResponse
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

        $owner        = (string) ($this->request->getParam('owner') ?? '');
        $repo         = (string) ($this->request->getParam('repo') ?? '');
        $credentialId = (string) ($this->request->getParam('credentialId') ?? '');

        if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
            return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
        }

        if ($credentialId === '') {
            return new JSONResponse(['error' => 'A broker credentialId is required to publish'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        // Tenant-scoped read: a skill outside the caller's organisation's visibility
        // is 404, identical to export()'s existing behaviour (never a 403 that would
        // confirm existence) — mirrors AgentTemplateController::publishGithub(). The
        // package is re-serialised by the shared engine's type; this is the gate.
        $package = $this->skillService->exportSkill(skillId: $id);
        if ($package === null) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->store->isBrokerAvailable() === false) {
            return new JSONResponse(['error' => 'The GitHub credential broker is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        try {
            $result = $this->store->publish(
                kind: FederatedStoreService::KIND_SKILL,
                uuid: $id,
                owner: $owner,
                repo: $repo,
                credentialId: $credentialId
            );
        } catch (RuntimeException $e) {
            $this->logger->warning('Hermiq skill github publish refused: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill github publish failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        $this->skillService->stampGithubPublish(
            skillId: $id,
            owner: $owner,
            repo: $repo,
            publishedAt: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')
        );

        return new JSONResponse($result, Http::STATUS_CREATED);

    }//end githubPublish()

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
