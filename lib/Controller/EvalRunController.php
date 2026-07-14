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
 * @spec openspec/changes/agent-evals/tasks.md#task-7-evalruncontroller--route
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
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
 * @spec openspec/changes/agent-evals/tasks.md#task-7-evalruncontroller--route
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
     * Constructor.
     *
     * @param IRequest        $request        The request object.
     * @param ObjectService   $objectService  OpenRegister object read (dataset ownership check).
     * @param AgentMapper     $agentMapper    Resolves + ownership-checks the target Agent.
     * @param IUserSession    $userSession    Resolves the requesting user for the owner guard.
     * @param EvalRunService  $evalRunService Executes the gated, scored eval run.
     * @param LoggerInterface $logger         PSR-3 logger.
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
     * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor
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
                regressionThresholdOverride: $thresholdOverride
            );
        } catch (ModelPolicyViolationException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        } catch (ProviderUnavailableException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 503);
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
     * Load the dataset only if the given user owns it (IDOR guard). Fetches WITH
     * RBAC enabled and additionally asserts owner identity, so neither a
     * cross-tenant object nor another user's owned dataset is ever returned or run.
     *
     * @param string $datasetId The EvalDataset object UUID.
     * @param string $uid       The requesting user's UID.
     *
     * @return ObjectEntity|null The owned dataset, or null when absent/not owned.
     */
    private function loadOwnedDataset(string $datasetId, string $uid): ?ObjectEntity
    {
        $dataset = $this->objectService->find(
            id: $datasetId,
            register: self::REGISTER_SLUG,
            schema: self::DATASET_SCHEMA
        );

        if (($dataset instanceof ObjectEntity) === false) {
            return null;
        }

        if ((string) ($dataset->getOwner() ?? '') !== $uid) {
            return null;
        }

        return $dataset;

    }//end loadOwnedDataset()

    /**
     * Load the target agent only if the given user owns it (IDOR guard), mirroring
     * `loadOwnedDataset()`.
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

        if ((string) ($agent->getOwner() ?? '') !== $uid) {
            return null;
        }

        return $agent;

    }//end loadOwnedAgent()
}//end class
