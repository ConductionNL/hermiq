<?php

/**
 * Hermiq AgentsController.
 *
 * REST API endpoints for AI agent management, ported route-for-route from
 * OpenRegister's AgentsController (agent-engine-port) and re-pointed at the
 * `agent` object in the `hermiq` OpenRegister register via ObjectService — no
 * OR AgentMapper. The tool catalogue endpoint is backed by OR's public
 * ToolRegistryFacade (the same list ToolLoop consumes, gate-27 contract).
 *
 * Adaptations vs the OR ground truth:
 * - Agent ids are UUID strings (OR used int row ids).
 * - Organisation is never taken from the request: ObjectService multitenancy
 *   assigns owner + organisation on create and scopes every read (OR set/
 *   preserved them explicitly against the entity).
 * - Visibility semantics mirror OR's AgentMapper::canUserAccessAgent():
 *   non-private OR owner OR invited; modification is owner-only.
 * - OR's `agents#page` TemplateResponse route is NOT mirrored — hermiq's SPA
 *   catch-all serves the page URL (see appinfo/routes.php).
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
 * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use Exception;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\SanitizesForSaveTrait;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AgentsController handles REST API endpoints for AI agent management:
 * CRUD, per-agent RBAC visibility checks, tool catalogue, and statistics.
 *
 * Every `@NoAdminRequired` method that takes an agent uuid guards per-object
 * access/ownership in the method body (gate-7 no-admin-idor).
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
 */
class AgentsController extends Controller
{
    use SanitizesForSaveTrait;

    /**
     * OpenRegister register slug that holds Hermiq agent-engine objects.
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
     * Request/metadata keys that must never reach the stored agent payload:
     * routing internals, entity identity, timestamps, and the owner/
     * organisation pair (assigned by ObjectService — accepting them from the
     * request would allow privilege escalation, exactly the tampering OR's
     * update() stripped).
     *
     * @var array<int, string>
     */
    private const PROTECTED_KEYS = ['_route', 'id', 'uuid', 'created', 'updated', 'organisation', 'owner'];

    /**
     * Constructor.
     *
     * @param IRequest           $request       The request object.
     * @param ObjectService      $objectService OpenRegister object read/write (single write-path).
     * @param ToolRegistryFacade $toolRegistry  OR's public tool read surface (gate-27 contract).
     * @param IUserSession       $userSession   Resolves the requesting user.
     * @param LoggerInterface    $logger        PSR-3 logger.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function __construct(
        IRequest $request,
        private readonly ObjectService $objectService,
        private readonly ToolRegistryFacade $toolRegistry,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get all agents accessible by the current user.
     *
     * Organisation scoping is applied by ObjectService multitenancy on the
     * read; the per-agent visibility rule (non-private OR owner OR invited)
     * is applied here, mirroring OR's mapper-layer RBAC filter.
     *
     * @return JSONResponse List of agents.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function index(): JSONResponse
    {
        try {
            $userId = (string) $this->userSession->getUser()?->getUID();
            $params = $this->request->getParams();

            // Extract pagination parameters.
            $limit  = (int) ($params['_limit'] ?? 50);
            $offset = (int) ($params['_offset'] ?? 0);
            $page   = null;
            if (isset($params['_page']) === true) {
                $page = (int) $params['_page'];
            }

            // Convert page to offset if provided (page-based pagination).
            if ($page !== null) {
                $offset = (($page - 1) * $limit);
            }

            // Fetch the page (org-scoped by ObjectService multitenancy), then
            // apply the per-agent visibility rule.
            $agents = $this->objectService
                ->setRegister(self::REGISTER_SLUG)
                ->setSchema(self::AGENT_SCHEMA)
                ->findAll(
                    config: [
                        'limit'  => $limit,
                        'offset' => $offset,
                    ]
                );

            $results = [];
            foreach ($agents as $agent) {
                if (($agent instanceof ObjectEntity) === false) {
                    continue;
                }

                if ($this->canUserAccessAgent(agent: $agent, userId: $userId) === true) {
                    $results[] = $this->serializeAgent(agent: $agent);
                }
            }

            // Return successful response with agents list.
            return new JSONResponse(
                data: ['results' => $results],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to get agents',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to retrieve agents'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Get a single agent.
     *
     * @param string $id Agent UUID.
     *
     * @return JSONResponse JSON response containing agent details.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function show(string $id): JSONResponse
    {
        try {
            $userId = (string) $this->userSession->getUser()?->getUID();
            $agent  = $this->objectService->find(
                id: $id,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
            if ($agent === null) {
                return new JSONResponse(
                    data: ['error' => 'Agent not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // Per-object visibility check (gate-7).
            if ($this->canUserAccessAgent(agent: $agent, userId: $userId) === false) {
                return new JSONResponse(
                    data: ['error' => 'Access denied to this agent'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            return new JSONResponse(
                data: $this->serializeAgent(agent: $agent),
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to get agent',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Agent not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end show()

    /**
     * Create a new agent.
     *
     * The owner and organisation are assigned by ObjectService on save (the
     * request cannot set them — see PROTECTED_KEYS). Defaults mirror OR:
     * private, file search and object search enabled.
     *
     * @return JSONResponse JSON response with the created agent.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @no-admin-idor-exempt Creates a NEW object, so there is no caller-supplied
     * object id to substitute — the IDOR shape this gate detects cannot exist
     * here. The two fields that would make it exploitable, `owner` and
     * `organisation`, are in PROTECTED_KEYS and stripped from the request before
     * save (stripProtectedKeys), then assigned server-side by ObjectService from
     * the session, so a caller cannot create an agent owned by, or inside the
     * organisation of, anyone else. New agents default to isPrivate: true.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->stripProtectedKeys(data: $this->request->getParams());

            // Set default values for new properties if not provided.
            if (isset($data['isPrivate']) === false) {
                // Private by default.
                $data['isPrivate'] = true;
            }

            if (isset($data['searchFiles']) === false) {
                // Search files by default.
                $data['searchFiles'] = true;
            }

            if (isset($data['searchObjects']) === false) {
                // Search objects by default.
                $data['searchObjects'] = true;
            }

            // Create the agent object (uuid/timestamps/owner/organisation are
            // assigned by ObjectService).
            $agent = $this->objectService->saveObject(
                object: $this->sanitizeForSave(data: $data),
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );

            $this->logger->info(
                message: '[AgentsController] Agent created successfully',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'id'           => $agent->getUuid(),
                    'organisation' => $agent->getOrganisation(),
                    'isPrivate'    => ($agent->getObject()['isPrivate'] ?? null),
                ]
            );

            return new JSONResponse(
                data: $this->serializeAgent(agent: $agent),
                statusCode: Http::STATUS_CREATED
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to create agent',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to create agent: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Update an existing agent.
     *
     * Owner-only (gate-7). Immutable/identity fields and the owner/
     * organisation pair are stripped from the request to prevent privilege
     * escalation (PROTECTED_KEYS) — in hermiq owner/organisation live on the
     * ObjectEntity envelope, not the payload, so stripping keeps them out of
     * the stored object entirely.
     *
     * @param string $id Agent UUID.
     *
     * @return JSONResponse JSON response with the updated agent.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function update(string $id): JSONResponse
    {
        try {
            $userId = (string) $this->userSession->getUser()?->getUID();
            $agent  = $this->objectService->find(
                id: $id,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
            if ($agent === null) {
                return new JSONResponse(
                    data: ['error' => 'Agent not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // Owner-only modification guard (gate-7).
            if ($this->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
                return new JSONResponse(
                    data: ['error' => 'You do not have permission to modify this agent'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $data = $this->stripProtectedKeys(data: $this->request->getParams());

            // Update agent properties via merge (hydrate semantics: partial
            // update over the existing payload).
            $payload = array_merge($agent->getObject(), $data);

            $updatedAgent = $this->objectService->saveObject(
                object: $this->sanitizeForSave(data: $payload),
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA,
                uuid: $id
            );

            $this->logger->info(
                message: '[AgentsController] Agent updated successfully',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'id'   => $id,
                ]
            );

            return new JSONResponse(
                data: $this->serializeAgent(agent: $updatedAgent),
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to update agent',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to update agent: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end update()

    /**
     * Patch (partially update) an agent.
     *
     * Delegates to update() which already implements partial-update semantics.
     *
     * @param string $id The UUID of the agent to patch.
     *
     * @return JSONResponse JSON response with updated agent or error.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function patch(string $id): JSONResponse
    {
        // Delegate to update method (both handle partial updates).
        return $this->update(id: $id);
    }//end patch()

    /**
     * Delete an agent.
     *
     * Owner-only (gate-7). Delegates to ObjectService::deleteObject() (OR's
     * audited delete path).
     *
     * @param string $id Agent UUID.
     *
     * @return JSONResponse Success message.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $userId = $this->userSession->getUser()?->getUID();
            if ($userId === null) {
                return new JSONResponse(
                    data: ['error' => 'User not authenticated'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $agent = $this->objectService->find(
                id: $id,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
            if ($agent === null) {
                return new JSONResponse(
                    data: ['error' => 'Failed to delete agent'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Owner-only modification guard (gate-7).
            if ($this->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
                return new JSONResponse(
                    data: ['error' => 'You do not have permission to delete this agent'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $this->objectService->deleteObject(
                uuid: $id,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );

            $this->logger->info(
                message: '[AgentsController] Agent deleted successfully',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'id'   => $id,
                ]
            );

            return new JSONResponse(
                data: ['message' => 'Agent deleted successfully'],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to delete agent',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to delete agent'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end destroy()

    /**
     * Get agent statistics.
     *
     * Mirrors OR's {total, active, inactive} shape; every count is an
     * ObjectService paginated total (org-scoped by multitenancy) instead of
     * OR's mapper COUNT queries.
     *
     * @return JSONResponse Agent statistics.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @no-admin-idor-exempt Read-only aggregate over the caller's OWN scope.
     * Takes no caller-supplied object id: it returns three COUNTS (total /
     * active / inactive) and no object content or identifiers. The counts come
     * from countAgents(), which queries through ObjectService and is therefore
     * organisation-scoped by OR's multitenancy on the same read path as index(),
     * so a caller cannot count another organisation's agents by any parameter
     * this endpoint accepts.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function stats(): JSONResponse
    {
        try {
            $stats = [
                'total'    => $this->countAgents(filters: []),
                'active'   => $this->countAgents(filters: ['active' => true]),
                'inactive' => $this->countAgents(filters: ['active' => false]),
            ];

            return new JSONResponse(data: $stats, statusCode: Http::STATUS_OK);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to get agent statistics',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to retrieve statistics'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end stats()

    /**
     * Get all available tools.
     *
     * Returns the tool catalogue for the frontend agent editor, backed by
     * OR's public ToolRegistryFacade::listTools() — the same descriptor list
     * the engine's ToolLoop consumes (gate-27: facade only, never OR's
     * internal ToolRegistry). Response envelope mirrors OR's tools().
     *
     * @return JSONResponse List of available tools with metadata.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @no-admin-idor-exempt Read-only instance-wide catalogue. Takes no
     * caller-supplied object id and reads no per-user or per-organisation data:
     * it returns the set of tool DESCRIPTORS this instance has installed (name,
     * title, parameter schema), which is the same list for every caller and
     * carries no tenant content. Whether a given agent may actually invoke any
     * of them is a separate decision enforced at call time by the tool-grant
     * check, not by hiding the catalogue.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    public function tools(): JSONResponse
    {
        try {
            // describeTools(), not listTools(): this endpoint feeds a PICKER,
            // and a person choosing between 98 tools needs the contributing app
            // and the operation, which a raw LLM function descriptor does not
            // carry. listTools() stays exactly as it is — its descriptors go to
            // the model as function definitions, and extra keys there are
            // rejected by strict provider APIs.
            $tools = $this->toolRegistry->describeTools();

            $this->logger->debug(
                message: '[AgentsController] Returning available tools',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'count' => count($tools),
                ]
            );

            return new JSONResponse(
                data: ['results' => $tools],
                statusCode: Http::STATUS_OK
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[AgentsController] Failed to get available tools',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to retrieve tools'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end tools()

    /**
     * Whether the user may use an agent: non-private agents are open to the
     * organisation (multitenancy already scoped the read), private agents
     * only to their owner or explicitly invited users — mirrors OR's
     * AgentMapper::canUserAccessAgent().
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool True when the user may access the agent.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    private function canUserAccessAgent(ObjectEntity $agent, string $userId): bool
    {
        $data      = $agent->getObject();
        $isPrivate = ($data['isPrivate'] ?? null);

        // Non-private agents are accessible to all users in the organisation.
        if ($isPrivate === false || $isPrivate === null) {
            return true;
        }

        // Owner always has access.
        if ($agent->getOwner() === $userId) {
            return true;
        }

        // Check if user is invited.
        $invitedUsers = ($data['invitedUsers'] ?? []);
        if (is_array($invitedUsers) === true && in_array($userId, $invitedUsers, true) === true) {
            return true;
        }

        return false;
    }//end canUserAccessAgent()

    /**
     * Whether the user may modify (or delete) an agent: owner-only, mirroring
     * OR's AgentMapper::canUserModifyAgent().
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool True when the user may modify the agent.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    private function canUserModifyAgent(ObjectEntity $agent, string $userId): bool
    {
        return $agent->getOwner() === $userId && $userId !== '';
    }//end canUserModifyAgent()

    /**
     * Strip routing internals, identity fields, and the owner/organisation
     * pair from a request payload (see PROTECTED_KEYS).
     *
     * @param array<string, mixed> $data Raw request parameters.
     *
     * @return array<string, mixed> The cleaned payload.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    private function stripProtectedKeys(array $data): array
    {
        foreach (self::PROTECTED_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;
    }//end stripProtectedKeys()

    /**
     * Count agents matching the given payload filters via the paginated
     * search total (org-scoped by ObjectService multitenancy).
     *
     * @param array<string, mixed> $filters Payload filters (e.g. active).
     *
     * @return int Agent count.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    private function countAgents(array $filters): int
    {
        // Booleans MUST be normalised to the literal strings 'true'/'false'.
        // The filter value reaches OpenRegister's query builder as a bound
        // parameter, and PHP's string cast of a bool gives '1' for true and the
        // EMPTY STRING for false — so `['active' => false]` bound '' against a
        // boolean column and Postgres rejected the whole statement with
        // SQLSTATE[22P02] "invalid input syntax for type boolean". stats() was
        // therefore a hard 500 on every call (both the active and the inactive
        // count go through here), which is why the dashboard's agent counters
        // showed nothing.
        $normalised = [];
        foreach ($filters as $key => $value) {
            if (is_bool($value) === true) {
                $normalised[$key] = ($value === true) ? 'true' : 'false';
                continue;
            }

            $normalised[$key] = $value;
        }

        $paginated = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::AGENT_SCHEMA)
            ->searchObjectsPaginated(query: array_merge($normalised, ['_limit' => 1]));

        return (int) ($paginated['total'] ?? 0);
    }//end countAgents()

    /**
     * Serialize an agent object to the OR-compatible response shape
     * (payload merged with entity identity/ownership metadata).
     *
     * @param ObjectEntity $agent The agent object.
     *
     * @return array<string, mixed> Serialized agent.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
     */
    private function serializeAgent(ObjectEntity $agent): array
    {
        return array_merge(
            $agent->getObject(),
            [
                'id'           => $agent->getUuid(),
                'uuid'         => $agent->getUuid(),
                'owner'        => $agent->getOwner(),
                'organisation' => $agent->getOrganisation(),
                'created'      => $agent->getCreated()?->format('c'),
                'updated'      => $agent->getUpdated()?->format('c'),
            ]
        );
    }//end serializeAgent()
}//end class
