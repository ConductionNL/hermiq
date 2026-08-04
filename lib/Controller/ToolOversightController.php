<?php

/**
 * Hermiq ToolOversightController (agent-tool-governance-and-disclosure).
 *
 * Three read/write endpoints over the ADR-063 consumer-side governance layer:
 *
 * - `GET tool-catalog`     — the grant-annotated derived catalog for the grant
 *   editor (design.md §"API Design").
 * - `PUT tool-grants`      — persists `Agent.tools` (owner-gated, single write-path
 *   via `ObjectService`).
 * - `GET tool-invocations` — the per-agent oversight surface (EU AI Act art.12/14),
 *   sourced from OpenRegister's MCP invocation AuditTrail entries when available,
 *   degrading gracefully to the coarser `run`-action entries `run-audit-log`
 *   already reads when the richer shape is absent (LIMITATION, carried from the
 *   cross-repo brief: OR's audit entry records the ambient NC session user, not an
 *   agent principal, so "per-agent" here is CORRELATED via this agent's own owner
 *   + its schedules' owners — not a first-class agent-identity column upstream).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\ToolGrantResolver;
use OCA\Hermiq\Service\Engine\ToolReachResolver;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tool-catalog / tool-grants / tool-invocations endpoints.
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   One injected collaborator per seam
 *   (object service, tool registry, grant resolver, audit mapper, app config, user
 *   session, group manager, logger) plus the OR entity and HTTP response types.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small guard-and-shape
 *   endpoint methods (catalog, grants, invocations, export) — a governance read/write
 *   surface, not one tangled algorithm.
 */
class ToolOversightController extends Controller
{

    /**
     * AuditTrail action recorded when an approval waiver is added or removed.
     *
     * Its OWN action, never folded into a general grant-change record: this is
     * the one grant edit that takes a human out of the loop, and it is a single
     * fragment away from the un-waived entry in any textual diff.
     *
     * @var string
     */
    public const WAIVER_AUDIT_ACTION = 'tool-grant-approval-waiver';

    /**
     * OpenRegister register slug that holds Hermiq objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Schema slug for schedule objects (owner-correlation for the oversight read).
     *
     * @var string
     */
    private const SCHEDULE_SCHEMA = 'schedule';

    /**
     * Default progressive-disclosure threshold — mirrors `ToolLoop`'s default.
     *
     * @var int
     */
    private const DEFAULT_DISCLOSURE_THRESHOLD = 30;

    /**
     * The audit `action` values an MCP tool invocation entry carries
     * (`AuditTrailMapper::createToolInvocationEntry()`: `mcp.{verb}`).
     *
     * @var string
     */
    private const MCP_ACTIONS = 'mcp.search,mcp.get,mcp.create,mcp.update,mcp.delete';

    /**
     * Constructor.
     *
     * @param IRequest           $request          The request object.
     * @param ObjectService      $objectService    OpenRegister object read/write (single write-path).
     * @param ToolRegistryFacade $toolRegistry     OR's public tool read surface (gate-27 contract).
     * @param ToolGrantResolver  $grantResolver    Schema-scoped grant expansion + default-deny.
     * @param AuditTrailMapper   $auditTrailMapper OR audit read (MCP invocation / run entries).
     * @param IAppConfig         $appConfig        Reads `hermiq.tools.disclosureThreshold`.
     * @param IUserSession       $userSession      Resolves the requesting user.
     * @param IGroupManager      $groupManager     Instance-admin check for the oversight bypass.
     * @param LoggerInterface    $logger           PSR-3 logger.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
     *   distinct injected collaborator, not a logic-bearing argument list.
     *
     * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly ToolRegistryFacade $toolRegistry,
        private readonly ToolGrantResolver $grantResolver,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * The grant-annotated derived catalog for the grant editor.
     *
     * @param string $agentId Agent UUID.
     *
     * @return JSONResponse The catalog payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#api-design
     * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#scenario-the-reach-of-every-catalogue-entry-is-readable-through-the-tool-catalogue-api
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Access guards plus per-descriptor
     *   shaping (id fallback, write classification, grant annotation) each add a
     *   branch on one linear catalog-build path.
     * @SuppressWarnings(PHPMD.StaticAccess)         ToolGrantResolver and ToolReachResolver
     *   are pure static classification predicates — the same ones the engine's tool
     *   loop uses, called statically by design so the two cannot drift.
     */
    public function toolCatalog(string $agentId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->canAccessAgent(agent: $agent, userId: $user->getUID()) === false) {
            return new JSONResponse(['error' => 'Access denied to this agent'], Http::STATUS_FORBIDDEN);
        }

        try {
            $grants      = $this->agentGrants(agent: $agent);
            $catalog     = $this->toolRegistry->listTools();
            $resolvedIds = $this->grantResolver->resolve(grants: $grants, catalog: $catalog);
            $resolvedSet = array_flip($resolvedIds);

            $tools = [];
            foreach ($catalog as $descriptor) {
                $id = ($descriptor['mcpId'] ?? ($descriptor['name'] ?? null));
                if (is_string($id) === false || $id === '') {
                    continue;
                }

                // 🔴 The descriptor is threaded through here. It was not before:
                // this call was id-only, so every 2-segment native id fell to
                // the fail-closed rule and the oversight UI showed `listFiles`
                // and `readFile` as WRITE tools — the exact opposite of what
                // their descriptors declare, and of what the engine's resolver
                // concluded about the same tools three lines above. An operator
                // deciding what to grant was reading a classification the
                // runtime did not share.
                $hints = null;
                if (is_array($descriptor) === true) {
                    $hints = $descriptor;
                }

                $isWrite = ToolGrantResolver::isWriteOrDestructive(id: $id, descriptor: $hints);
                $reach   = ToolReachResolver::resolve(toolId: $id, descriptor: $hints);
                $granted = isset($resolvedSet[$id]);

                $scope = 'read';
                if ($isWrite === true) {
                    $scope = 'write';
                }

                $tools[] = [
                    'id'                    => $id,
                    'name'                  => (string) ($descriptor['name'] ?? $id),
                    'description'           => (string) ($descriptor['description'] ?? ''),
                    'scope'                 => $scope,
                    'reach'                 => $reach,
                    'destructiveHint'       => $isWrite,
                    'granted'               => $granted,
                    'grantedBy'             => $this->grantedBy(id: $id, grants: $grants, granted: $granted),
                    // The UNION, not `$isWrite` — a read-scoped tool whose reach
                    // is `instance` or beyond needs naming just as explicitly.
                    'requiresExplicitGrant' => (
                        ToolGrantResolver::requiresGrant(id: $id, descriptor: $hints) === true
                        && $granted === false
                    ),
                ];
            }//end foreach

            $threshold     = $this->disclosureThreshold();
            $resolvedCount = count($resolvedIds);

            return new JSONResponse(
                [
                    'agentId'             => $agentId,
                    'disclosureThreshold' => $threshold,
                    'resolvedCount'       => $resolvedCount,
                    'disclosureActive'    => ($resolvedCount > $threshold),
                    'tools'               => $tools,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('Hermiq tool-catalog read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load tool catalog'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end toolCatalog()

    /**
     * Persist the `Agent.tools` grant array (owner-only, single write-path).
     *
     * Deliberately NOT `@NoCSRFRequired` — unlike the two read endpoints, this one
     * MUTATES an authorization boundary (which tools an agent may call), so the CSRF
     * guard stays ON. `@nextcloud/axios` sends the requesttoken on every request, so
     * `src/api/toolOversight.js`'s PUT works unchanged; a cross-site forger cannot
     * silently widen an agent's grants.
     *
     * @param string $agentId Agent UUID.
     *
     * @return JSONResponse The updated grant array, or an error status.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/design.md#put-api-agents-agentid-tool-grants
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guards (auth, 404,
     *   owner-only IDOR check, grants-shape validation, per-entry string filter)
     *   each add a branch on one linear write path.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning: independent
     *   early-return guards multiply paths without nested logic.
     */
    public function updateToolGrants(string $agentId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        // Owner-only modification guard (gate-7 no-admin-idor), mirroring
        // AgentsController::canUserModifyAgent().
        if ($agent->getOwner() !== $user->getUID() || $user->getUID() === '') {
            return new JSONResponse(
                ['error' => 'You do not have permission to modify this agent'],
                Http::STATUS_FORBIDDEN
            );
        }

        $rawGrants = $this->request->getParam('grants');
        if (is_array($rawGrants) === false) {
            return new JSONResponse(['error' => 'grants must be an array of strings'], Http::STATUS_BAD_REQUEST);
        }

        $grants = [];
        foreach ($rawGrants as $grant) {
            if (is_string($grant) === true && $grant !== '') {
                $grants[] = $grant;
            }
        }

        // Read the PREVIOUS waiver set before the write, so the audit can say
        // which waivers were added and which removed rather than only what the
        // list now holds. Diffing after the save would compare the new list with
        // itself and report every change as a no-op.
        $waiversBefore = $this->waiverEntries(grants: ($agent->getObject()['tools'] ?? []));

        try {
            // 🔴 Strip OpenRegister's own metadata before writing back.
            //
            // The whole stored object is carried forward on purpose —
            // `saveObject()` is PUT-semantic, so any field this endpoint omits
            // is NULLED, and it must not clear the fields it does not manage.
            // But `getObject()` also returns OR's `@self` envelope, and feeding
            // that back makes the schema resolver fail with
            // `$ref must be a non-empty string` — a 500 on every grant write,
            // for the owner, on a clean instance.
            //
            // It survived this long because the only client that exercised the
            // path is `AgentFormModal.vue`, which does the same strip in
            // JavaScript (`delete base['@self']`) before it ever gets here. The
            // endpoint was relying on its caller to sanitise its input.
            $payload = array_merge($agent->getObject(), ['tools' => $grants]);
            unset($payload['@self']);

            // 🔴 And drop every null / empty-object value.
            //
            // OpenRegister refuses BOTH `{}` and `null` for an object-typed
            // property — the documented remedy is to OMIT the key rather than
            // send either. Writing them back raises
            // `$ref must be a non-empty string` from the schema resolver, which
            // names neither the key nor the schema and so reads like a broken
            // register rather than a payload the caller built.
            //
            // Only reachable on an agent whose optional object fields were
            // never populated — i.e. a freshly created one, which is exactly
            // what a clean instance has and a long-lived dev instance does not.
            // That is why this endpoint looked healthy for months.
            //
            // Omitting is safe under PUT semantics here: an absent optional
            // object and a stored empty one are the same state.
            foreach ($payload as $key => $value) {
                if ($value === null || $value === []) {
                    unset($payload[$key]);
                }
            }

            // `tools` is the one key this endpoint exists to write, so it is
            // re-asserted after the sweep — an intentional empty grant list
            // must survive, and the sweep above would have removed it.
            $payload['tools'] = $grants;

            $updated = $this->objectService->saveObject(
                object: $payload,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                uuid: $agentId
            );

            // 🔴 The audit must never be able to fail the write it describes.
            //
            // `auditWaiverChange()` already swallows its own write errors, but
            // that catch is INSIDE the method — it cannot protect the call
            // itself. `saveObject()`'s return type is not guaranteed across
            // OpenRegister versions, so passing it into an `ObjectEntity`
            // parameter can raise a TypeError right here, outside any of that
            // protection, and the owner then gets a 500 on a grant write that
            // actually SUCCEEDED. Checking the type first removes the whole
            // class of failure rather than the one instance of it.
            if (($updated instanceof ObjectEntity) === true) {
                $this->auditWaiverChange(
                    agent: $updated,
                    before: $waiversBefore,
                    after: $this->waiverEntries(grants: $grants),
                    actor: $user->getUID()
                );
            }

            $savedTools = $grants;
            if (($updated instanceof ObjectEntity) === true) {
                $savedTools = ($updated->getObject()['tools'] ?? $grants);
            }

            return new JSONResponse(['agentId' => $agentId, 'tools' => $savedTools]);
        } catch (DoesNotExistException $e) {
            // ObjectService::saveObject() re-throws DoesNotExistException on a
            // tenant/scope mismatch — translate to 404 (never a raw 500 on the
            // defended path; gate-49 / opencatalogi#86 lesson).
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq tool-grants update failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not update tool grants'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end updateToolGrants()

    /**
     * The grant entries in a list that carry a `#noapproval` fragment.
     *
     * @param mixed $grants The stored `tools` value — typed loosely because it
     *                      crosses the OpenRegister object boundary.
     *
     * @return array<int, string> The waived entries, verbatim.
     */
    private function waiverEntries(mixed $grants): array
    {
        if (is_array($grants) === false) {
            return [];
        }

        $waived = [];
        foreach ($grants as $grant) {
            if (is_string($grant) === true && str_ends_with($grant, ToolGrantResolver::WAIVER_FRAGMENT) === true) {
                $waived[] = $grant;
            }
        }

        return $waived;

    }//end waiverEntries()

    /**
     * Record adding or removing an approval waiver as its OWN audit event.
     *
     * 🔴 Why this is not folded into the ordinary grant-change record. Waiving
     * approval is the one grant edit that removes a human from the loop, and it
     * is invisible in a diff of the tool list: `hermiq.sendMail` and
     * `hermiq.sendMail#noapproval` are one string apart and sort next to each
     * other. An auditor scanning grant changes for "what did this agent gain"
     * would read the second as the first. A distinct action name is what makes
     * the question "when did anyone turn off approval, and who" answerable
     * without re-parsing every historical grant list.
     *
     * Both directions are recorded. Re-enabling approval is the safe change, but
     * an audit trail that only ever logs the dangerous direction cannot show
     * that a waiver was temporary — and "it was on for two hours during the
     * incident" is exactly what an auditor needs to establish.
     *
     * An ordinary grant change writes NOTHING here, so the presence of one of
     * these entries always means a waiver actually moved.
     *
     * Never throws: an audit write failing must not turn a successful,
     * authorised grant update into a 500 the owner cannot act on. The failure is
     * logged at warning level with the agent it concerns.
     *
     * @param ObjectEntity       $agent  The saved agent.
     * @param array<int, string> $before Waived entries before the write.
     * @param array<int, string> $after  Waived entries after the write.
     * @param string             $actor  The acting (owner) UID.
     *
     * @return void
     *
     * @spec openspec/changes/agent-capability-reach/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
     */
    private function auditWaiverChange(ObjectEntity $agent, array $before, array $after, string $actor): void
    {
        $added   = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return;
        }

        try {
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $agent,
                action: self::WAIVER_AUDIT_ACTION,
                context: [
                    'added'   => $added,
                    'removed' => $removed,
                    'actor'   => $actor,
                    'at'      => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq could not write approval-waiver audit for '.((string) $agent->getUuid()).': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end auditWaiverChange()

    /**
     * The per-agent oversight read (EU AI Act art.12/14): recorded tool
     * invocations, newest first, tenant-scoped, with a retention note and a
     * CSV/JSON export.
     *
     * @param string $agentId Agent UUID.
     * @param string $format  `json` (default) or `csv`.
     * @param string $from    Optional ISO 8601 lower bound (inclusive).
     * @param string $to      Optional ISO 8601 upper bound (inclusive).
     *
     * @return JSONResponse|DataDownloadResponse The oversight payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
     */
    public function toolInvocations(string $agentId, string $format='json', string $from='', string $to=''): JSONResponse|DataDownloadResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        if ($this->canAccessAgent(agent: $agent, userId: $user->getUID()) === false) {
            return new JSONResponse(['error' => 'Access denied to this agent'], Http::STATUS_FORBIDDEN);
        }

        try {
            $available = $this->richAuditAvailable();
            $ownerUids = $this->correlatedOwnerUids(agentId: $agentId, agent: $agent);

            $fromFilter = null;
            if ($from !== '') {
                $fromFilter = $from;
            }

            $toFilter = null;
            if ($to !== '') {
                $toFilter = $to;
            }

            $source = 'run-audit-log';
            $rows   = $this->degradedRows(agentId: $agentId, from: $fromFilter, to: $toFilter);
            if ($available === true) {
                $source = 'or-mcp-invocation-audit';
                $rows   = $this->richRows(ownerUids: $ownerUids, from: $fromFilter, to: $toFilter);
            }

            $payload = [
                'agentId'   => $agentId,
                'available' => $available,
                'source'    => $source,
                'retention' => "Retention follows OpenRegister's AuditTrail policy.",
                'rows'      => $rows,
            ];

            if ($format === 'csv') {
                return new DataDownloadResponse(
                    data: $this->toCsv(rows: $rows),
                    filename: 'hermiq-tool-invocations-'.$agentId.'.csv',
                    contentType: 'text/csv'
                );
            }

            return new JSONResponse($payload);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq tool-invocations read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load tool invocations'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

    }//end toolInvocations()

    /**
     * Load an agent (tenant-scoped by `ObjectService` multitenancy/RBAC, exactly
     * like `AgentsController::show()`).
     *
     * @param string $agentId Agent UUID.
     *
     * @return ObjectEntity|null
     */
    private function findAgent(string $agentId): ?ObjectEntity
    {
        $agent = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
        if (($agent instanceof ObjectEntity) === false) {
            return null;
        }

        return $agent;

    }//end findAgent()

    /**
     * Whether the user may view an agent: non-private OR owner OR invited —
     * mirrors `AgentsController::canUserAccessAgent()` (duplicated locally; no
     * cross-controller coupling for a small visibility rule).
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool
     */
    private function canAccessAgent(ObjectEntity $agent, string $userId): bool
    {
        $data      = $this->agentData(agent: $agent);
        $isPrivate = ($data['isPrivate'] ?? null);

        if ($isPrivate === false || $isPrivate === null) {
            return true;
        }

        if ($agent->getOwner() === $userId) {
            return true;
        }

        $invitedUsers = ($data['invitedUsers'] ?? []);
        if (is_array($invitedUsers) === true && in_array($userId, $invitedUsers, true) === true) {
            return true;
        }

        // Instance-admin oversight bypass. This is a governance surface (EU AI Act
        // art.12/14 tool-invocation oversight), so an instance admin must be able to
        // inspect any agent's tool catalogue/activity — including system-owned seeded
        // agents (owner `__system__`) that no human owns and OpenRegister lets admins
        // read anyway. Deliberately scoped to this oversight controller; it does NOT
        // widen general private-agent access elsewhere.
        return $this->groupManager->isAdmin($userId);

    }//end canAccessAgent()

    /**
     * The agent entity's decoded object payload — a plain in-memory accessor
     * (`ObjectEntity::getObject()` never touches storage and cannot throw).
     *
     * @param ObjectEntity $agent Agent object.
     *
     * @return array<string, mixed>
     */
    private function agentData(ObjectEntity $agent): array
    {
        return $agent->getObject();

    }//end agentData()

    /**
     * The agent's raw `Agent.tools` grant strings, sanitized.
     *
     * @param ObjectEntity $agent Agent object.
     *
     * @return array<int, string>
     */
    private function agentGrants(ObjectEntity $agent): array
    {
        $tools = ($agent->getObject()['tools'] ?? []);
        if (is_array($tools) === false) {
            return [];
        }

        $clean = [];
        foreach ($tools as $tool) {
            if (is_string($tool) === true && $tool !== '') {
                $clean[] = $tool;
            }
        }

        return $clean;

    }//end agentGrants()

    /**
     * Best-effort "which grant entry produced this id" for the editor's UX —
     * informational only, never load-bearing for the grant/deny decision itself.
     *
     * @param string             $id      The resolved tool id.
     * @param array<int, string> $grants  The agent's raw grant strings.
     * @param bool               $granted Whether `$id` is in the resolved set.
     *
     * @return string|null
     */
    private function grantedBy(string $id, array $grants, bool $granted): ?string
    {
        if ($granted === false) {
            return null;
        }

        foreach ($grants as $grant) {
            if ($grant === $id) {
                return $grant;
            }
        }

        $parts = explode('.', $id);
        if (count($parts) === 3) {
            $prefix = $parts[0].'.'.$parts[1];
            foreach ($grants as $grant) {
                if ($grant === ($prefix.'.*') || $grant === ($prefix.'.*:write')) {
                    return $grant;
                }
            }
        }

        if ($grants === []) {
            return '(default: all discovered tools)';
        }

        return null;

    }//end grantedBy()

    /**
     * The configured progressive-disclosure threshold.
     *
     * @return int
     */
    private function disclosureThreshold(): int
    {
        return $this->appConfig->getValueInt('hermiq', 'tools.disclosureThreshold', self::DEFAULT_DISCLOSURE_THRESHOLD);

    }//end disclosureThreshold()

    /**
     * Whether OpenRegister's richer per-invocation MCP audit shape
     * (`AuditTrailMapper::createToolInvocationEntry()`) is present — detected by
     * the DECLARED `toolId` property existing on the installed `AuditTrail`
     * entity class (real accessors are Nextcloud `Entity::__call()` magic, so a
     * `method_exists()` check would be unreliable; the declared property is not).
     * False in standalone unit runs (the test stub predates this chain) and on
     * any OpenRegister install that has not yet shipped it (Risk 4 fallback).
     *
     * `protected`, not `private`: a class-shape check like this cannot be
     * per-test-mocked any other way (it is not behind an injected collaborator),
     * so `ToolOversightControllerTest` exercises both branches via an anonymous
     * subclass overriding this one method — the rest of the controller is
     * exercised unchanged.
     *
     * @return bool
     */
    protected function richAuditAvailable(): bool
    {
        return property_exists(AuditTrail::class, 'toolId');

    }//end richAuditAvailable()

    /**
     * The owner UIDs correlated with this agent: its own owner plus the owners
     * of every schedule bound to it (the LIMITATION documented in the class
     * docblock — OR's MCP audit entry records the ambient session user, not an
     * agent principal, so this is the best available correlation).
     *
     * @param string       $agentId Agent UUID.
     * @param ObjectEntity $agent   Agent object.
     *
     * @return array<int, string>
     */
    private function correlatedOwnerUids(string $agentId, ObjectEntity $agent): array
    {
        $owners = [];

        $agentOwner = (string) ($agent->getOwner() ?? '');
        if ($agentOwner !== '') {
            $owners[$agentOwner] = true;
        }

        $schedules = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEDULE_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        foreach ($schedules as $schedule) {
            if (($schedule instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $schedule->getObject();
            if ((string) ($data['agentId'] ?? '') !== $agentId) {
                continue;
            }

            $owner = (string) ($schedule->getOwner() ?? '');
            if ($owner !== '') {
                $owners[$owner] = true;
            }
        }

        return array_keys($owners);

    }//end correlatedOwnerUids()

    /**
     * Read the richer per-invocation MCP audit rows, correlated by acting user
     * to this agent's owner set.
     *
     * @param array<int, string> $ownerUids Correlated owner UIDs.
     * @param string|null        $from      Optional ISO 8601 lower bound (inclusive).
     * @param string|null        $to        Optional ISO 8601 upper bound (inclusive).
     *
     * @return array<int, array<string, mixed>> Newest-first rows.
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-an-operator-reviews-an-agent-s-tool-activity
     */
    private function richRows(array $ownerUids, ?string $from, ?string $to): array
    {
        if ($ownerUids === []) {
            return [];
        }

        $ownerSet = array_flip($ownerUids);
        $logs     = $this->auditTrailMapper->findAll(filters: ['action' => self::MCP_ACTIONS]);

        $rows = [];
        foreach ($logs as $log) {
            $user = (string) ($log->getUser() ?? '');
            if (isset($ownerSet[$user]) === false) {
                continue;
            }

            $created    = $log->getCreated();
            $occurredAt = null;
            if ($created !== null) {
                $occurredAt = $created->format('c');
            }

            if ($this->withinRange(occurredAt: $occurredAt, from: $from, to: $to) === false) {
                continue;
            }

            $rows[] = [
                'at'            => $occurredAt,
                'toolId'        => $log->getToolId(),
                'actingUser'    => $user,
                // The audit entry never carries raw argument values
                // (REQ-DERIVED-006: "never raw object payloads") — only a
                // SHA-256 digest survives. Surfacing the digest is honest;
                // inventing decoded field values is not.
                'paramsDigest'  => $log->getParamsDigest(),
                'resultSummary' => $log->getResultSummary(),
                'dataTouched'   => array_values(array_filter([$log->getObjectUuid()])),
            ];
        }//end foreach

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $rows;

    }//end richRows()

    /**
     * Degraded fallback: coarse `action='run'` entries for this agent's
     * schedules (the same source `run-audit-log`/`AnalyticsService` already
     * read), used when the richer per-invocation shape is not yet available.
     *
     * @param string      $agentId Agent UUID.
     * @param string|null $from    Optional ISO 8601 lower bound (inclusive).
     * @param string|null $to      Optional ISO 8601 upper bound (inclusive).
     *
     * @return array<int, array<string, mixed>> Newest-first rows.
     *
     * @throws DoesNotExistException Propagated when the hermiq register/schema
     *         cannot be resolved by `ObjectService::findAll()` — intentional:
     *         `toolInvocations()`'s catch translates it to a JSON error response.
     *
     * @spec openspec/specs/agent-tool-governance/spec.md#scenario-the-richer-invocation-audit-shape-is-not-yet-available
     */
    private function degradedRows(string $agentId, ?string $from, ?string $to): array
    {
        $scheduleUuids = [];

        $schedules = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::SCHEDULE_SCHEMA)
            ->findAll(config: ['limit' => 1000]);

        foreach ($schedules as $schedule) {
            if (($schedule instanceof ObjectEntity) === false) {
                continue;
            }

            if ((string) ($schedule->getObject()['agentId'] ?? '') === $agentId) {
                $scheduleUuids[(string) $schedule->getUuid()] = true;
            }
        }

        if ($scheduleUuids === []) {
            return [];
        }

        $logs = $this->auditTrailMapper->findAll(filters: ['action' => 'run']);

        $rows = [];
        foreach ($logs as $log) {
            if (isset($scheduleUuids[(string) $log->getObjectUuid()]) === false) {
                continue;
            }

            $created    = $log->getCreated();
            $occurredAt = null;
            if ($created !== null) {
                $occurredAt = $created->format('c');
            }

            if ($this->withinRange(occurredAt: $occurredAt, from: $from, to: $to) === false) {
                continue;
            }

            $context = ($log->getChanged() ?? []);

            $rows[] = [
                'at'            => $occurredAt,
                'toolId'        => null,
                'actingUser'    => $log->getUser(),
                'paramsDigest'  => null,
                'resultSummary' => ['status' => ($context['status'] ?? 'unknown')],
                'dataTouched'   => [],
            ];
        }//end foreach

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return $rows;

    }//end degradedRows()

    /**
     * Whether a row's timestamp falls within an optional `[from, to]` window
     * (string comparison — both are ISO 8601, sortable lexically).
     *
     * @param string|null $occurredAt The row's timestamp, or null.
     * @param string|null $from       Optional lower bound (inclusive).
     * @param string|null $to         Optional upper bound (inclusive).
     *
     * @return bool
     */
    private function withinRange(?string $occurredAt, ?string $from, ?string $to): bool
    {
        if ($occurredAt === null) {
            return ($from === null && $to === null);
        }

        if ($from !== null && $occurredAt < $from) {
            return false;
        }

        if ($to !== null && $occurredAt > $to) {
            return false;
        }

        return true;

    }//end withinRange()

    /**
     * Render rows as CSV text (header + one line per row; `resultSummary`/
     * `dataTouched` flattened to JSON so the export stays one row per invocation).
     *
     * @param array<int, array<string, mixed>> $rows The rows to export.
     *
     * @return string
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // Explicit $escape: PHP 8.4 deprecates relying on the default (RFC 4180
        // knows no escape character — pass '' once the minimum PHP allows it).
        fputcsv($handle, ['at', 'toolId', 'actingUser', 'paramsDigest', 'resultSummary', 'dataTouched'], ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv(
                $handle,
                [
                    (string) ($row['at'] ?? ''),
                    (string) ($row['toolId'] ?? ''),
                    (string) ($row['actingUser'] ?? ''),
                    (string) ($row['paramsDigest'] ?? ''),
                    (string) json_encode($row['resultSummary'] ?? null),
                    (string) json_encode($row['dataTouched'] ?? []),
                ],
                ',',
                '"',
                '\\'
            );
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            return '';
        }

        return $csv;

    }//end toCsv()
}//end class
