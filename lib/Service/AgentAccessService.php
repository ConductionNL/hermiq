<?php

/**
 * Hermiq AgentAccessService.
 *
 * The ONE home for hermiq's per-agent authorization predicate (ADR-005 Rule 3 /
 * OWASP A01:2021). Every `#[NoAdminRequired]` route that takes a caller-supplied
 * agent id off the URL resolves the agent through this service and treats a
 * refusal as a 404 — never a 403 — so a non-owner cannot even confirm that a
 * private agent exists.
 *
 * The predicate itself is NOT new: it is lifted verbatim from
 * `AgentsController::canUserAccessAgent()` / `::canUserModifyAgent()`, which
 * mirror OpenRegister's `AgentMapper::canUserAccessAgent()` /
 * `::canUserModifyAgent()`. It lived as a PRIVATE method duplicated across four
 * controllers (`AgentsController`, `AgentVersionController`,
 * `ChatStreamController`, `ToolOversightController`), which is precisely why the
 * memory, skill-install, agent-template and run-on-object surfaces could ship
 * without it: there was nothing shared to call. Extracting it means the next
 * endpoint has a collaborator to inject rather than a body to re-type.
 *
 * ⚠️ Those four private copies are deliberately NOT migrated onto this service in
 * the same change: each is guarded and correct today, and rewiring four working
 * controllers inside a security fix widens the blast radius of the fix itself.
 * Consolidation is filed as follow-up work.
 *
 * ⚠️ This guard is the ONLY layer. OpenRegister's register RBAC is
 * default-OPEN for a schema that declares no `authorization` block
 * (`PermissionHandler::hasGroupPermission()` returns true when the block is
 * empty and `enforce_default_closed` is off, which is the shipped default), and
 * `hermiq_register.json` declares a block on `Agent` only — `Memory`,
 * `UserProfile`, `AgentSession`, `AgentSessionTurn` and `AgentTemplate` have
 * none. Multitenancy scopes organisations, not two users inside one.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-agent read/modify authorization, shared by every agent-scoped route.
 *
 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
 */
class AgentAccessService
{

    /**
     * OpenRegister register slug hermiq's objects live in.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * OpenRegister schema slug for agent objects.
     *
     * @var string
     */
    private const AGENT_SCHEMA = 'agent';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object read path.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the user may READ an agent: non-private agents are open to the
     * organisation (multitenancy already scoped the read), private agents only
     * to their owner or an explicitly invited user — mirrors OR's
     * `AgentMapper::canUserAccessAgent()`.
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool True when the user may access the agent.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function canUserAccessAgent(ObjectEntity $agent, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

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
     * Whether the user may MODIFY an agent or the per-agent state hanging off it
     * (memory, profiles, skill installs): owner-only, mirroring OR's
     * `AgentMapper::canUserModifyAgent()`.
     *
     * Read access is deliberately NOT enough here. An agent's memory entries and
     * its installed skills are folded into the system-prompt preamble of every
     * subsequent run (`Engine::assembleSkillsForRun()`), so a write to them is a
     * durable instruction to somebody else's agent, not a note on a shared board.
     *
     * @param ObjectEntity $agent  Agent object.
     * @param string       $userId Nextcloud user id.
     *
     * @return bool True when the user may modify the agent.
     *
     * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
     */
    public function canUserModifyAgent(ObjectEntity $agent, string $userId): bool
    {
        return $agent->getOwner() === $userId && $userId !== '';

    }//end canUserModifyAgent()

    /**
     * Load an agent only when it exists AND the requesting user may READ it —
     * null either way, so a non-owner cannot confirm a private agent exists.
     *
     * @param string $agentId The Agent UUID.
     * @param string $userId  The requesting user's UID.
     *
     * @return ObjectEntity|null The accessible agent, or null.
     *
     * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function loadAccessibleAgent(string $agentId, string $userId): ?ObjectEntity
    {
        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return null;
        }

        if ($this->canUserAccessAgent(agent: $agent, userId: $userId) === false) {
            return null;
        }

        return $agent;

    }//end loadAccessibleAgent()

    /**
     * Load an agent only when it exists AND the requesting user may MODIFY it
     * (owner-only) — null either way, same non-disclosure rule as
     * `loadAccessibleAgent()`.
     *
     * @param string $agentId The Agent UUID.
     * @param string $userId  The requesting user's UID.
     *
     * @return ObjectEntity|null The modifiable agent, or null.
     *
     * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
     */
    public function loadModifiableAgent(string $agentId, string $userId): ?ObjectEntity
    {
        $agent = $this->findAgent(agentId: $agentId);
        if ($agent === null) {
            return null;
        }

        if ($this->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
            return null;
        }

        return $agent;

    }//end loadModifiableAgent()

    /**
     * Resolve the agent object, translating a lookup failure into null.
     *
     * `ObjectService::find()` documents `@throws Exception If the object is not
     * found`, and callers invoke the guard OUTSIDE their own try block — an
     * unhandled throw would escape to the dispatcher as a framework 500 with a
     * stack trace on a `#[NoAdminRequired]` route (gate-49). An agent that
     * cannot be loaded is, to a caller, not accessible.
     *
     * @param string $agentId The Agent UUID.
     *
     * @return ObjectEntity|null The agent, or null when absent/unreadable.
     */
    private function findAgent(string $agentId): ?ObjectEntity
    {
        if (trim($agentId) === '') {
            return null;
        }

        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Hermiq agent lookup failed for '.$agentId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }

        if (($agent instanceof ObjectEntity) === false) {
            return null;
        }

        return $agent;

    }//end findAgent()
}//end class
