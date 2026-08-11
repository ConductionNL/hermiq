<?php

/**
 * Hermiq MemoryController.
 *
 * Read + manage an agent's memory surface (Memory, UserProfile, Session, SessionTurn)
 * and run tenant-scoped recall.
 *
 * ⚠️ Tenancy is NOT the guard. Every route here takes a caller-supplied `agentId`
 * off the URL, and the Memory/UserProfile/AgentSession/AgentSessionTurn schemas
 * declare no `authorization` block, so OpenRegister's register RBAC is
 * default-OPEN on them and multitenancy scopes organisations rather than the two
 * users inside one. Each route therefore resolves the agent through
 * `AgentAccessService` first — READ routes require read access, WRITE routes
 * (append, consolidate) require ownership — and a refusal is a 404, never a 403,
 * so a non-owner cannot confirm a private agent exists (ADR-005 Rule 3).
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
 * @spec openspec/changes/agent-memory/tasks.md#3-controller-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\MemoryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped agent-memory read/manage endpoints.
 *
 * @spec openspec/changes/agent-memory/tasks.md#3-controller-routes
 */
class MemoryController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request       The request object.
     * @param MemoryService      $memoryService The memory read/write path.
     * @param IUserSession       $userSession   Resolves the requesting user.
     * @param LoggerInterface    $logger        PSR-3 logger.
     * @param AgentAccessService $agentAccess   Per-agent authorization (IDOR guard).
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function __construct(
        IRequest $request,
        private readonly MemoryService $memoryService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly AgentAccessService $agentAccess,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get an agent's Memory object (tenant-scoped).
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The memory payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function memory(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requireAgentReadAccess(agentId: $agentId) === false) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $memory = $this->memoryService->getMemory(agentId: $agentId);
            return new JSONResponse($this->shape(object: $memory));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq memory read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load memory'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end memory()

    /**
     * Append a fact to an agent's Memory (operator seeding / management surface).
     *
     * Exposes the char-budget-aware append: the entry is always persisted and the object
     * is flagged `needsConsolidation` when the budget is exceeded (never truncated). The
     * agent run loop uses the same service method; this endpoint lets an operator seed or
     * curate memory from the UI.
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The updated memory payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function addMemory(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requireAgentOwnership(agentId: $agentId) === false) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        $text = trim((string) $this->request->getParam('text', ''));
        if ($text === '') {
            return new JSONResponse(['error' => 'A non-empty text is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $memory = $this->memoryService->appendMemoryEntry(agentId: $agentId, text: $text);
            return new JSONResponse($this->shape(object: $memory));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq memory append failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not add memory'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end addMemory()

    /**
     * List an agent's UserProfile objects (tenant-scoped).
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The user-profile list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function userProfiles(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requireAgentReadAccess(agentId: $agentId) === false) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $profiles = array_map([$this, 'shape'], $this->memoryService->listUserProfiles(agentId: $agentId));
            return new JSONResponse(['results' => $profiles]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq user-profiles read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load user profiles'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end userProfiles()

    /**
     * List an agent's Sessions (tenant-scoped).
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The session list, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function sessions(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requireAgentReadAccess(agentId: $agentId) === false) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $sessions = array_map([$this, 'shape'], $this->memoryService->listSessions(agentId: $agentId));
            return new JSONResponse(['results' => $sessions]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq sessions read failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Could not load sessions'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end sessions()

    /**
     * Consolidate an agent's Memory: replace entries with a supplied consolidated set and
     * clear the nudge (the caller supplies the strategy; empty body ⇒ de-duplicate).
     *
     * @param string $agentId The agent UUID.
     *
     * @return JSONResponse The consolidated memory payload, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function consolidate(string $agentId): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requireAgentOwnership(agentId: $agentId) === false) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        $entries = $this->request->getParam('entries');
        if (is_array($entries) === false) {
            // No explicit strategy supplied: de-duplicate the current entries by text so a
            // manual consolidation still bounds the array (intelligent summarisation is
            // the OR run-loop seam).
            $entries = $this->dedupeCurrent(agentId: $agentId);
        }

        try {
            $memory = $this->memoryService->consolidateMemory(agentId: $agentId, entries: $entries);
            return new JSONResponse($this->shape(object: $memory));
        } catch (Throwable $e) {
            $this->logger->error('Hermiq memory consolidate failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Consolidation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end consolidate()

    /**
     * Recall an agent's SessionTurns matching a query (tenant-scoped, OR search).
     *
     * @param string $agentId The agent UUID.
     * @param string $q       The recall query.
     *
     * @return JSONResponse The matching turns, or an error status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @SuppressWarnings(PHPMD.ShortVariable) `$q` is the public query-parameter name
     * (`GET /api/agents/{agentId}/recall?q=…`) — NC binds route params by name, so
     * renaming it would break the API contract.
     *
     * @spec openspec/changes/agent-memory/tasks.md#task-3-1
     */
    public function recall(string $agentId, string $q=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requireAgentReadAccess(agentId: $agentId) === false) {
            return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $turns = array_map([$this, 'shape'], $this->memoryService->recallSessions(agentId: $agentId, query: $q));
            return new JSONResponse(['results' => $turns]);
        } catch (Throwable $e) {
            $this->logger->error('Hermiq recall failed: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => 'Recall failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

    }//end recall()

    /**
     * IDOR guard for the READ routes: the caller must be able to read the agent
     * whose memory surface they are addressing (owner, invitee, or any
     * organisation member for a non-private agent).
     *
     * @param string $agentId The agent UUID taken off the URL.
     *
     * @return bool True when the caller may read this agent's memory surface.
     *
     * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
     */
    private function requireAgentReadAccess(string $agentId): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->agentAccess->loadAccessibleAgent(
            agentId: $agentId,
            userId: $user->getUID()
        ) !== null;

    }//end requireAgentReadAccess()

    /**
     * IDOR guard for the WRITE routes (append, consolidate): owner-only.
     *
     * Read access is deliberately not enough. A memory entry is folded into the
     * system-prompt preamble of every subsequent run of the agent, so an append
     * by a non-owner is a durable instruction to somebody else's agent, and
     * `consolidate` REPLACES the entry array outright — a wipe.
     *
     * @param string $agentId The agent UUID taken off the URL.
     *
     * @return bool True when the caller owns this agent.
     *
     * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
     */
    private function requireAgentOwnership(string $agentId): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->agentAccess->loadModifiableAgent(
            agentId: $agentId,
            userId: $user->getUID()
        ) !== null;

    }//end requireAgentOwnership()

    /**
     * De-duplicate the current Memory entries by text (default consolidation strategy).
     *
     * @param string $agentId The agent UUID.
     *
     * @return array<int, array<string, string>> The de-duplicated entries.
     */
    private function dedupeCurrent(string $agentId): array
    {
        $data    = $this->memoryService->getMemory(agentId: $agentId)->getObject();
        $entries = ($data['entries'] ?? []);
        if (is_array($entries) === false) {
            return [];
        }

        $seen = [];
        $out  = [];
        foreach ($entries as $entry) {
            if (is_array($entry) === false || isset($entry['text']) === false) {
                continue;
            }

            $text = (string) $entry['text'];
            if (isset($seen[$text]) === true) {
                continue;
            }

            $seen[$text] = true;
            $out[]       = $entry;
        }

        return $out;

    }//end dedupeCurrent()

    /**
     * Shape an ObjectEntity into a UUID + payload response map.
     *
     * @param ObjectEntity $object The object.
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
