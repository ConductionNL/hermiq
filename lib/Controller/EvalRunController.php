<?php

/**
 * Hermiq EvalRunController.
 *
 * The thin "run this dataset against this agent now" action (agent-evals): OpenRegister
 * exposes no agent-trigger endpoint, so this is the one net-new backend endpoint the UI
 * needs — EvalDataset/EvalRun CRUD themselves go through the generic
 * `/apps/openregister/api/objects/hermiq/{evaldataset|evalrun}` path via createObjectStore,
 * exactly like Schedule/Agent (ADR-001/ADR-022).
 *
 * Security (ADR-005 Rule 3 / OWASP A01 IDOR): `@NoAdminRequired` means any authenticated
 * user can call it, so the method MUST NOT trust the {datasetId} path or the request body's
 * agentId blindly. It loads BOTH the EvalDataset and the target Agent WITH RBAC on and
 * refuses (404) unless the requesting user owns each — a non-owner can neither run nor
 * confirm the existence of another tenant's dataset or agent (mirrors RunNowController).
 * skill-evals widens the guard for `baseline: true`: the caller must ALSO own EVERY skill
 * referenced by the dataset's `skillRefs` (the paired run writes l5 evidence onto those
 * skills); any missing/invisible/non-owned linked skill is the same indistinguishable 404.
 * Ownership is decided by `SeedCustodyService::actsAsOwner()`: the stored owner passes,
 * and an instance ADMIN passes for system-seeded (`__system__`) objects only — without
 * that, the seeded example dataset/agent/skills would 404 for EVERYONE forever (repair
 * steps stamp no human owner). A human-owned object is never opened to admins here.
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
 * @spec openspec/changes/archive/2026-07-14-agent-evals/tasks.md#task-7-evalruncontroller-route
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owner-scoped "run this EvalDataset against this Agent" endpoint.
 *
 * @spec openspec/changes/archive/2026-07-14-agent-evals/tasks.md#task-7-evalruncontroller-route
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One injected collaborator per seam
 *   (object service, agent mapper, eval-run service, seed custody, user session,
 *   logger) plus the OpenRegister entity and HTTP response types the guard uses.
 */
class EvalRunController extends Controller
{

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for EvalDataset objects.
     *
     * @var string
     */
    private const DATASET_SCHEMA = 'evaldataset';

    /**
     * OpenRegister schema slug for Skill objects (widened baseline owner guard;
     * namespaced to avoid a cross-app slug collision).
     *
     * @var string
     */
    private const SKILL_SCHEMA = 'agentskill';

    /**
     * Constructor.
     *
     * @param IRequest           $request        The request object.
     * @param ObjectService      $objectService  OpenRegister object read (dataset ownership check).
     * @param AgentMapper        $agentMapper    Resolves + ownership-checks the target Agent.
     * @param IUserSession       $userSession    Resolves the requesting user for the owner guard.
     * @param EvalRunService     $evalRunService Executes the gated, scored eval run.
     * @param SeedCustodyService $seedCustody    Owner-or-seed-custodian check (an instance
     *                                           admin acts as owner of `__system__`-seeded
     *                                           objects only).
     * @param LoggerInterface    $logger         PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly AgentMapper $agentMapper,
        private readonly IUserSession $userSession,
        private readonly EvalRunService $evalRunService,
        private readonly SeedCustodyService $seedCustody,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Run the given EvalDataset against the agent named in the request body.
     *
     * @param string $datasetId The EvalDataset object UUID.
     *
     * @return JSONResponse The run outcome, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor
     * @spec openspec/specs/agent-evals/spec.md#requirement-the-paired-trigger-owner-guard-covers-dataset-agent-and-every-linked-skill
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Spec'd IDOR guards (dataset, agent,
     *   every linked skill in baseline mode) plus optional-param parsing each add a
     *   branch on one linear run path.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning: independent
     *   early-return guards multiply paths without nested logic.
     */
    public function run(string $datasetId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dataset = $this->loadOwnedDataset(datasetId: $datasetId, uid: $user->getUID());
        if ($dataset === null) {
            // 404 (not 403) so a non-owner cannot even confirm the dataset exists.
            return new JSONResponse(['error' => 'Dataset not found'], Http::STATUS_NOT_FOUND);
        }

        $agentId = (string) $this->request->getParam('agentId', '');
        $agent   = $this->loadOwnedAgent(agentId: $agentId, uid: $user->getUID());
        if ($agent === null) {
            // 404 (not 403) so a non-owner cannot even confirm the agent exists.
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        // Skill-evals: optional paired-baseline mode. Default false — the existing
        // agent-scoped behaviour is byte-identical for every pre-existing caller.
        $baseline = filter_var($this->request->getParam('baseline', false), FILTER_VALIDATE_BOOLEAN);
        if ($baseline === true) {
            $skillRefs = $this->linkedSkillRefs(dataset: $dataset);
            if ($skillRefs === []) {
                return new JSONResponse(
                    ['error' => 'Baseline mode requires a dataset with linked skills'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            // Widened owner guard: the paired run writes l5 evidence onto every
            // linked skill, so the caller must own each — any missing, invisible,
            // or non-owned skill is 404, never 403 (no existence oracle for any
            // of the three object kinds).
            if ($this->ownsEverySkill(skillRefs: $skillRefs, uid: $user->getUID()) === false) {
                return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
            }
        }

        $agentVersionId = $this->request->getParam('agentVersionId', null);
        if ($agentVersionId !== null) {
            $agentVersionId = (string) $agentVersionId;
        }

        $thresholdOverride = null;
        $thresholdRaw      = $this->request->getParam('regressionThresholdPercent', null);
        if ($thresholdRaw !== null && is_numeric($thresholdRaw) === true) {
            $thresholdOverride = (int) $thresholdRaw;
        }

        try {
            $result = $this->evalRunService->run(
                dataset: $dataset,
                agent: $agent,
                agentVersionId: $agentVersionId,
                regressionThresholdOverride: $thresholdOverride,
                baseline: $baseline
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            // NOTE: no ModelPolicyViolationException / ProviderUnavailableException
            // catches here — EvalRunService::run() swallows per-case engine failures
            // (each failed case is recorded as a failed case, never rethrown), so
            // those exceptions cannot escape it (phpstan dead-catch).
        } catch (Throwable $e) {
            $this->logger->error('Hermiq eval run failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                ['error' => 'Eval run failed', 'message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse($result);

    }//end run()

    /**
     * Load the dataset only if the given user may act as its owner (IDOR guard).
     * Fetches WITH RBAC enabled and additionally asserts ownership via
     * `SeedCustodyService::actsAsOwner()` — the stored owner passes, and an
     * instance admin passes for a `__system__`-seeded dataset only (seed
     * custodianship; a human-owned dataset stays closed to admins). Neither a
     * cross-tenant object nor another user's owned dataset is ever returned or run.
     *
     * @param string $datasetId The EvalDataset object UUID.
     * @param string $uid       The requesting user's UID.
     *
     * @return ObjectEntity|null The owned dataset, or null when absent/not owned.
     */
    private function loadOwnedDataset(string $datasetId, string $uid): ?ObjectEntity
    {
        try {
            $dataset = $this->objectService->find(
                id: $datasetId,
                register: self::REGISTER_SLUG,
                schema: self::DATASET_SCHEMA
            );
        } catch (Throwable $e) {
            // `ObjectService::find()` throws when the object is absent, and
            // `run()` calls this helper OUTSIDE its own try block — so the throw
            // would escape as a framework 500 with a stack trace on a
            // #[NoAdminRequired] route. A dataset that cannot be loaded is, to a
            // caller, not owned; null already carries that meaning.
            $this->logger->warning(
                'Hermiq eval dataset lookup failed for '.$datasetId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }//end try

        if (($dataset instanceof ObjectEntity) === false) {
            return null;
        }

        if ($this->seedCustody->actsAsOwner(owner: $dataset->getOwner(), uid: $uid) === false) {
            return null;
        }

        return $dataset;

    }//end loadOwnedDataset()

    /**
     * Load the target agent only if the given user may act as its owner (IDOR
     * guard), mirroring `loadOwnedDataset()` — including the seed-custodian rule
     * for `__system__`-seeded agents.
     *
     * @param string $agentId The Agent UUID.
     * @param string $uid     The requesting user's UID.
     *
     * @return Agent|null The owned agent, or null when absent/not owned/unresolvable.
     */
    private function loadOwnedAgent(string $agentId, string $uid): ?Agent
    {
        if ($agentId === '') {
            return null;
        }

        try {
            $agent = $this->agentMapper->findByUuid($agentId);
        } catch (Throwable $e) {
            return null;
        }

        if ($this->seedCustody->actsAsOwner(owner: $agent->getOwner(), uid: $uid) === false) {
            return null;
        }

        return $agent;

    }//end loadOwnedAgent()

    /**
     * The dataset's linked skill uuids (`skillRefs`), filtered to non-empty strings.
     *
     * @param ObjectEntity $dataset The (already owner-guarded) EvalDataset.
     *
     * @return array<int, string> The linked skill uuids (deduplicated, reindexed).
     */
    private function linkedSkillRefs(ObjectEntity $dataset): array
    {
        $refs = ($dataset->getObject()['skillRefs'] ?? []);
        if (is_array($refs) === false) {
            return [];
        }

        $ids = array_filter($refs, static fn ($ref): bool => is_string($ref) === true && $ref !== '');

        return array_values(array_unique($ids));

    }//end linkedSkillRefs()

    /**
     * Whether the given user may act as owner of EVERY referenced skill (widened
     * baseline IDOR guard). Fetches WITH RBAC enabled and additionally asserts
     * ownership via `SeedCustodyService::actsAsOwner()` (seed custodianship
     * included, so an admin can baseline-run the seeded example set) — a missing,
     * cross-tenant, or merely-visible-but-not-owned skill all fail identically,
     * so the caller learns nothing about any single skill.
     *
     * @param array<int, string> $skillRefs The linked skill uuids.
     * @param string             $uid       The requesting user's UID.
     *
     * @return bool True only when every referenced skill resolves and $uid acts as its owner.
     *
     * @spec openspec/specs/agent-evals/spec.md#requirement-the-paired-trigger-owner-guard-covers-dataset-agent-and-every-linked-skill
     */
    private function ownsEverySkill(array $skillRefs, string $uid): bool
    {
        foreach ($skillRefs as $skillId) {
            try {
                $skill = $this->objectService->find(
                    id: $skillId,
                    register: self::REGISTER_SLUG,
                    schema: self::SKILL_SCHEMA
                );
            } catch (Throwable $e) {
                return false;
            }

            if (($skill instanceof ObjectEntity) === false) {
                return false;
            }

            if ($this->seedCustody->actsAsOwner(owner: $skill->getOwner(), uid: $uid) === false) {
                return false;
            }
        }

        return true;

    }//end ownsEverySkill()
}//end class
