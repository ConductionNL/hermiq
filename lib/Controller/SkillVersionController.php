<?php

/**
 * Hermiq SkillVersionController.
 *
 * The skill versioning + republish endpoints (skill-self-improvement):
 *
 * - `index()` / `diff()` / `rollback()` — AuditTrail-backed version history, a
 *   field-level diff over the versioned content plane (`frontmatter`/`body`/`files`),
 *   and rollback-as-a-new-version. All OWNER-guarded (agent-evals IDOR rule: a
 *   missing skill and a non-owner are indistinguishable — both 404, never 403).
 * - `republish()` — the one-click, NEVER-automatic republish to the skill's OWN
 *   provenance-stamped repository, behind the SAME `skill.publish-hub` action
 *   authorization the skills-marketplace publish requirement defines. The target
 *   coordinates are read from the skill's stamped `githubOwner`/`githubRepo` and
 *   never from the client, so republishing to any other repository is structurally
 *   impossible; broker unavailable → 503, no token fallback. The committed selection
 *   ships `learnings.md` but strips `learning-candidates.md`.
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
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
 * Version history / diff / rollback / republish endpoints for skills.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per seam
 *   (visibility, version store, publish path, action matrix, audit) — the
 *   controller only guards and delegates.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SkillVersionController extends Controller
{

    /**
     * The publish action authorization republish REUSES (skills-marketplace:
     * "republish MUST require the same publish action authorization as first
     * publish").
     *
     * @var string
     */
    private const PUBLISH_ACTION = 'skill.publish-hub';

    /**
     * Constructor.
     *
     * @param IRequest                  $request          The request object.
     * @param SkillService              $skillService     Tenant-scoped skill reads + provenance stamp.
     * @param SkillVersionService       $versionService   AuditTrail-backed version store.
     * @param GitHubTemplatePushService $pushService      Fail-closed broker-mediated GitHub push.
     * @param ActionAuthService         $actionAuth       ADR-023 action authorization.
     * @param AuditTrailMapper          $auditTrailMapper Rollback/republish audit entries.
     * @param SeedCustodyService        $seedCustody      Owner-or-seed-custodian check.
     * @param IUserSession              $userSession      Resolves the requesting user.
     * @param LoggerInterface           $logger           PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        IRequest $request,
        private readonly SkillService $skillService,
        private readonly SkillVersionService $versionService,
        private readonly GitHubTemplatePushService $pushService,
        private readonly ActionAuthService $actionAuth,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly SeedCustodyService $seedCustody,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The skill's version history, newest-first (owner-guarded).
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The versions, or 404.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function index(string $id): JSONResponse
    {
        $guarded = $this->loadOwnedSkillOr404(skillId: $id);
        if (($guarded instanceof JSONResponse) === true) {
            return $guarded;
        }

        try {
            return new JSONResponse(['results' => $this->versionService->listVersions(skillUuid: $id)]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill versions failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Versions failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end index()

    /**
     * Field-level diff between two versions, limited to the versioned content plane
     * (`frontmatter`/`body`/`files`) — a `state` or provenance change never appears.
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The diff, or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function diff(string $id): JSONResponse
    {
        $guarded = $this->loadOwnedSkillOr404(skillId: $id);
        if (($guarded instanceof JSONResponse) === true) {
            return $guarded;
        }

        $from = (string) $this->request->getParam('from', '');
        $to   = (string) $this->request->getParam('to', '');
        if ($from === '' || $to === '') {
            return new JSONResponse(['error' => 'Both from and to version ids are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse(['diff' => $this->versionService->diff(skillUuid: $id, fromId: $from, toId: $to)]);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => 'Version not found'], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill diff failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Diff failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end diff()

    /**
     * Roll the skill back to a previous version's content — as a brand-NEW version
     * (history never mutated; identity/lifecycle/provenance/maturity fields keep
     * their CURRENT values). Always an explicit human action — the regression
     * watch's suggestion is advisory only. Audited.
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The new version id, or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
     */
    public function rollback(string $id): JSONResponse
    {
        $guarded = $this->loadOwnedSkillOr404(skillId: $id);
        if (($guarded instanceof JSONResponse) === true) {
            return $guarded;
        }

        $versionId = (string) $this->request->getParam('versionId', '');
        if ($versionId === '') {
            return new JSONResponse(['error' => 'A versionId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $updated = $this->versionService->rollback(skillUuid: $id, versionId: $versionId);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => 'Version not found'], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill rollback failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Rollback failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $newVersionId = (string) ($this->versionService->currentVersionId(skillUuid: $id) ?? '');

        $this->writeAudit(
            skill: $updated,
            context: [
                'transition'      => 'rollback',
                'targetVersionId' => $versionId,
                'newVersionId'    => $newVersionId,
            ]
        );

        return new JSONResponse(
            [
                'status'    => 'rolled-back',
                'versionId' => $newVersionId,
            ]
        );

    }//end rollback()

    /**
     * One-click republish to the skill's OWN provenance repo (the exactly-one
     * carve-out from "refuse to overwrite an existing repository"): target
     * coordinates come from the skill's stamped `githubOwner`/`githubRepo` — never
     * from the client — behind the SAME publish action authorization as first
     * publish. NEVER automatic: reachable only through this explicit user action.
     * Ships `learnings.md`, strips `learning-candidates.md`, restamps `publishedAt`
     * on success (clearing the behind-badge). Audited.
     *
     * @param string $id The Skill UUID.
     *
     * @return JSONResponse The push result, or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    public function republish(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Visibility first (404 before the action check, matching template publish).
        $skill = $this->loadVisibleSkill(skillId: $id);
        if ($skill === null) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: self::PUBLISH_ACTION);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        $data  = $skill->getObject();
        $owner = (string) ($data['githubOwner'] ?? '');
        $repo  = (string) ($data['githubRepo'] ?? '');
        if ($owner === '' || $repo === '') {
            // Never published — there is nothing to republish and no carve-out:
            // first publish goes through the normal refuse-existing publish path.
            return new JSONResponse(['error' => 'not_published'], Http::STATUS_BAD_REQUEST);
        }

        $credentialId = (string) ($this->request->getParam('credentialId') ?? '');
        if ($credentialId === '') {
            return new JSONResponse(['error' => 'A broker credentialId is required to republish'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        if ($this->pushService->isBrokerAvailable() === false) {
            // Fail closed — no token-bearing fallback exists.
            return new JSONResponse(['error' => 'The GitHub credential broker is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $package = $this->skillService->exportSkill(skillId: $id);
        if ($package === null) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->pushService->pushUpdate(
                package: $package,
                owner: $owner,
                repo: $repo,
                credentialId: $credentialId,
                actingUserId: $user->getUID(),
                kind: GitHubTemplatePushService::KIND_SKILL,
                auxFiles: ($this->skillService->publishFileSelection(skillId: $id) ?? [])
            );
        } catch (RuntimeException $e) {
            $this->logger->warning('Hermiq skill republish refused: '.$e->getMessage());
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq skill republish failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Republish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        // Restamp publishedAt — this clears the behind-badge client-side.
        $this->skillService->stampGithubPublish(
            skillId: $id,
            owner: $owner,
            repo: $repo,
            publishedAt: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')
        );

        $this->writeAudit(
            skill: $skill,
            context: [
                'transition' => 'republish',
                'owner'      => $owner,
                'repo'       => $repo,
                'commitSha'  => (string) ($result['commitSha'] ?? ''),
            ]
        );

        return new JSONResponse($result, Http::STATUS_CREATED);

    }//end republish()

    /**
     * Owner guard shared by the version endpoints: the caller must OWN the skill; a
     * missing skill and a non-owner both 404 (never 403), mirroring
     * `SkillMaturityController::loadOwnedSkill()`. An instance admin acts as
     * custodian-owner of system-seeded skills (owner `__system__`; see
     * `SeedCustodyService`).
     *
     * @param string $skillId The Skill UUID.
     *
     * @return JSONResponse|ObjectEntity The 401/404 response, or the owned skill.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    private function loadOwnedSkillOr404(string $skillId): JSONResponse|ObjectEntity
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $skill = $this->loadVisibleSkill(skillId: $skillId);
        if ($skill === null || $this->seedCustody->actsAsOwner(owner: $skill->getOwner(), uid: $user->getUID()) === false) {
            return new JSONResponse(['error' => 'Skill not found'], Http::STATUS_NOT_FOUND);
        }

        return $skill;

    }//end loadOwnedSkillOr404()

    /**
     * Load the skill within the caller's RBAC visibility.
     *
     * @param string $skillId The Skill UUID.
     *
     * @return ObjectEntity|null The visible skill, or null when absent/invisible.
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    private function loadVisibleSkill(string $skillId): ?ObjectEntity
    {
        try {
            return $this->skillService->getSkill(skillId: $skillId);
        } catch (Throwable $e) {
            return null;
        }

    }//end loadVisibleSkill()

    /**
     * Write the rollback/republish AuditTrail entry (run-audit-log seam) — never
     * fatal to the operation it records.
     *
     * @param ObjectEntity         $skill   The skill the operation acted on.
     * @param array<string, mixed> $context The operation evidence.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-every-draft-state-transition-is-audited
     */
    private function writeAudit(ObjectEntity $skill, array $context): void
    {
        try {
            $context['at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $skill,
                action: 'skill-version',
                context: $context
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not write skill-version audit for '
                .((string) $skill->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end writeAudit()
}//end class
