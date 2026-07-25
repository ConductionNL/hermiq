<?php

/**
 * Hermiq AgentRunController.
 *
 * The user-initiated, OBJECT-SCOPED "run this agent on this object now" surface
 * (agent-object-leaf): `POST /api/agents/{id}/run-on-object`. It is the per-object
 * affordance the agent render leaf's widget POSTs to — the one entry point that
 * lets a user click "run agent" from any OpenRegister object detail page, which no
 * existing trigger (declarative flow, admin-only GraphController::run, schedule,
 * webhook) provides.
 *
 * AUTHORIZATION (ADR-005 / hydra-gate-no-admin-idor). Unlike
 * `GraphController::run` — admin-gated because it executes an arbitrary
 * caller-supplied graph — this endpoint names an EXISTING agent and an EXISTING
 * object and is authorized against the OBJECT's own OpenRegister permissions in
 * the CALLER's RBAC scope (`_rbac: true`). A caller who cannot read the object
 * gets a 404, fail-closed and indistinguishable from "does not exist", so the
 * endpoint cannot be used to probe for objects the caller may not see. This is the
 * per-object guard that keeps `#[NoAdminRequired]` safe (design.md Decision 2).
 *
 * GOVERNANCE (ADR-041 / ADR-066). Starting the run is a cross-app COMMAND, so the
 * endpoint dispatches the SAME typed `AgentRunRequestedEvent` recipe every other
 * trigger uses (mode `"async"`, a fresh correlation id, `flowName: "run-on-object"`)
 * and returns 202. It NEVER calls `FlowAgentRunService` directly and re-implements
 * no run logic — so kill-switch/budget/human-approval/redacted-audit can never be
 * bypassed by adding this new caller. `requiresApproval` is derived from the
 * AGENT's own policy, never from the request body: a caller must not be able to
 * downgrade an approval requirement (design.md Decision 3).
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
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-scoped-run-on-object-endpoint
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Agent\AgentContextBuilder;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Object-scoped agent-run endpoint (agent-object-leaf).
 *
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-scoped-run-on-object-endpoint
 */
class AgentRunController extends Controller
{

    /**
     * The default field the run's output is written to when the body names none.
     *
     * @var string
     */
    private const DEFAULT_RESULT_FIELD = 'agentResult';

    /**
     * Constructor.
     *
     * @param IRequest            $request         The request.
     * @param ObjectService       $objectService   Resolves the triggering object in the caller's RBAC scope.
     * @param AgentMapper         $agentMapper     Resolves + validates the named agent (mirrors
     *                                             FlowAgentRunService::resolveAgent()).
     * @param SchemaMapper        $schemaMapper    Resolves the target schema to read its context allowlist.
     * @param AgentContextBuilder $contextBuilder  Builds the bounded, fail-closed prompt context.
     * @param IEventDispatcher    $eventDispatcher Dispatches the governed AgentRunRequestedEvent recipe.
     * @param IUserSession        $userSession     The requesting user (authentication).
     * @param IL10N               $l10n            Localisation.
     * @param LoggerInterface     $logger          PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI — each parameter is a distinct
     *   injected collaborator, not a logic-bearing argument list.
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly AgentMapper $agentMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AgentContextBuilder $contextBuilder,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Dispatch a governed agent run against a caller-readable object.
     *
     * Body: `register`, `schema`, `objectId` (required); `resultField`, `skill`,
     * `prompt` (optional). Returns 202 with the correlation id on success.
     *
     * @param string $id The agent reference (UUID in v1).
     *
     * @return JSONResponse 202 `{status, correlationId, mode}` on success; 400 on a missing
     *                      required field; 404 when the object is not readable in the caller's
     *                      scope or the agent cannot be resolved.
     *
     * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-scoped-run-on-object-endpoint
     * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-run-on-object-authorization-is-object-permission-scoped
     * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-run-on-object-rides-the-existing-governed-recipe
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function runOnObject(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $register = trim((string) $this->request->getParam('register', ''));
        $schema   = trim((string) $this->request->getParam('schema', ''));
        $objectId = trim((string) $this->request->getParam('objectId', ''));

        if ($register === '' || $schema === '' || $objectId === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('register, schema and objectId are required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        // OBJECT-SCOPED AUTHORIZATION: resolve in the CALLER's RBAC scope. A caller
        // who cannot read the object gets a 404 — fail-closed and indistinguishable
        // from nonexistent (per-object IDOR guard; NOT GraphController::run's admin gate).
        $object = $this->resolveReadableObject(register: $register, schema: $schema, objectId: $objectId);
        if ($object === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Object not found')],
                Http::STATUS_NOT_FOUND
            );
        }

        // Resolve + validate the agent exactly as FlowAgentRunService::resolveAgent()
        // does; an unresolvable agent is a 404 (indistinguishable from nonexistent).
        $agent = $this->resolveAgent(ref: $id);
        if ($agent === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Agent not found')],
                Http::STATUS_NOT_FOUND
            );
        }

        $skill  = $this->optionalString(param: 'skill');
        $prompt = (string) $this->request->getParam('prompt', '');

        $resultField = $this->optionalString(param: 'resultField') ?? self::DEFAULT_RESULT_FIELD;

        // Build the bounded, fail-closed prompt context from the schema allowlist and
        // fold it into the rendered prompt so the run is grounded on ONLY allowlisted fields.
        $context         = $this->buildBoundedContext(object: $object, schemaRef: $schema);
        $effectivePrompt = $this->renderPrompt(prompt: $prompt, context: $context);

        // Approval requirement comes from the AGENT's own policy — NEVER the request
        // body — so a caller cannot downgrade an approval requirement (spec: caller
        // cannot bypass the approval gate).
        $requiresApproval = $this->agentRequiresApproval(agent: $agent);

        $event = new AgentRunRequestedEvent(
            subjectUuid: (string) $object->getUuid(),
            subjectRegister: $register,
            subjectSchema: $schema,
            agent: $id,
            skill: $skill,
            prompt: $effectivePrompt,
            resultField: $resultField,
            requiresApproval: $requiresApproval,
            mode: 'async',
            flowName: 'run-on-object',
        );

        // ADR-041 / ADR-066: dispatch the SAME governed recipe every other trigger
        // uses. The existing AgentRunRequestedListener enqueues the governed job.
        // NEVER call FlowAgentRunService directly.
        $this->eventDispatcher->dispatchTyped($event);

        $this->logger->info(
            sprintf(
                'Hermiq run-on-object dispatched: agent=%s object=%s correlationId=%s',
                $id,
                (string) $object->getUuid(),
                $event->getCorrelationId()
            )
        );

        return new JSONResponse(
            [
                'status'        => 'accepted',
                'mode'          => 'async',
                'correlationId' => $event->getCorrelationId(),
            ],
            Http::STATUS_ACCEPTED
        );

    }//end runOnObject()

    /**
     * Resolve the triggering object in the CALLER's RBAC scope; null on any failure.
     *
     * `_rbac: true` is deliberate — it is the object-permission gate that makes
     * `#[NoAdminRequired]` safe. The later governed job re-resolves in the system
     * scope AFTER this authorization, exactly as FlowAgentRunService does.
     *
     * @param string $register The register slug/id.
     * @param string $schema   The schema slug/id.
     * @param string $objectId The object uuid/id.
     *
     * @return ObjectEntity|null The object, or null when not readable / not found.
     *
     * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-run-on-object-authorization-is-object-permission-scoped
     */
    private function resolveReadableObject(string $register, string $schema, string $objectId): ?ObjectEntity
    {
        try {
            $object = $this->objectService->find(
                id: $objectId,
                register: $register,
                schema: $schema,
                _rbac: true,
                _multitenancy: true
            );
        } catch (Throwable $e) {
            // A permission failure surfaces as an exception in some code paths — treat
            // it identically to "not found": fail-closed, never leak existence.
            $this->logger->info(
                'Hermiq run-on-object: object not readable in caller scope: '.$e->getMessage()
            );
            return null;
        }

        if (($object instanceof ObjectEntity) === false) {
            return null;
        }

        return $object;

    }//end resolveReadableObject()

    /**
     * Resolve the configured agent reference (UUID in v1) — mirrors
     * FlowAgentRunService::resolveAgent().
     *
     * @param string $ref The agent reference.
     *
     * @return Agent|null The resolved agent, or null when unresolvable.
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-1-3
     */
    private function resolveAgent(string $ref): ?Agent
    {
        if ($ref === '') {
            return null;
        }

        try {
            return $this->agentMapper->findByUuid($ref);
        } catch (Throwable $e) {
            return null;
        }

    }//end resolveAgent()

    /**
     * Build the bounded, fail-closed context from the schema's allowlist.
     *
     * Schema-resolution failure is non-fatal: it yields an EMPTY context (the same
     * safe default the allowlist itself produces when absent).
     *
     * @param ObjectEntity $object    The triggering object.
     * @param string       $schemaRef The schema slug/id.
     *
     * @return array<string,mixed> The bounded context.
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-1-4
     */
    private function buildBoundedContext(ObjectEntity $object, string $schemaRef): array
    {
        $configuration = [];
        try {
            $schemaEntity  = $this->schemaMapper->find($schemaRef);
            $configuration = ($schemaEntity->getConfiguration() ?? []);
        } catch (Throwable $e) {
            $this->logger->info(
                'Hermiq run-on-object: schema not resolvable for context allowlist; using empty context: '.$e->getMessage()
            );
            return [];
        }

        return $this->contextBuilder->build(objectData: $object->getObject(), schemaConfiguration: $configuration);

    }//end buildBoundedContext()

    /**
     * Fold the bounded context into the rendered prompt.
     *
     * @param string              $prompt  The caller-supplied prompt (may be empty).
     * @param array<string,mixed> $context The bounded context.
     *
     * @return string The prompt handed to the governed run.
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-1-4
     */
    private function renderPrompt(string $prompt, array $context): string
    {
        if ($context === []) {
            return $prompt;
        }

        $json = json_encode($context, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($json === false) {
            $json = '{}';
        }

        $grounding = 'Object context (allowlisted fields only):'."\n".$json;
        if (trim($prompt) === '') {
            return $grounding;
        }

        return $prompt."\n\n".$grounding;

    }//end renderPrompt()

    /**
     * Whether the AGENT's own policy requires human approval.
     *
     * Read from the agent's configuration, NEVER from the request body — a caller
     * must not be able to downgrade an approval requirement.
     *
     * @param Agent $agent The resolved agent.
     *
     * @return bool True when the agent's policy requires approval.
     *
     * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-run-on-object-rides-the-existing-governed-recipe
     */
    private function agentRequiresApproval(Agent $agent): bool
    {
        $configuration = ($agent->getConfiguration() ?? []);
        return (($configuration['requiresApproval'] ?? false) === true);

    }//end agentRequiresApproval()

    /**
     * Read an optional non-empty string body param, or null.
     *
     * @param string $param The param name.
     *
     * @return string|null The trimmed value, or null when absent/empty.
     *
     * @spec openspec/changes/hermiq-agent-leaf/tasks.md#task-1-1
     */
    private function optionalString(string $param): ?string
    {
        $value = $this->request->getParam($param);
        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;

    }//end optionalString()
}//end class
