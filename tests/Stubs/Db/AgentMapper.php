<?php

/**
 * Test stub for OpenRegister AgentMapper.
 *
 * Stands in for OCA\OpenRegister\Db\AgentMapper when OpenRegister is not
 * installed (standalone CI). Exposes `findByUuid` (used by ScheduleService to
 * resolve a schedule's agentId) and the two per-agent authorization predicates
 * `AgentRunController` calls. The real mapper ships with OpenRegister.
 *
 * ⚠️ The predicate bodies below are copied VERBATIM from the real mapper
 * (`openregister/lib/Db/AgentMapper.php:280` / `:311`). A stub that merely
 * declared the methods and returned `true` would make the IDOR tests green in
 * standalone CI while proving nothing — the exact "double shaped to the caller"
 * failure this app has been bitten by before. If OpenRegister changes the rule,
 * this copy must change with it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal AgentMapper stub for standalone unit runs.
 */
class AgentMapper
{

    /**
     * Resolve an agent by UUID.
     *
     * @param string $uuid The agent UUID.
     *
     * @return Agent
     */
    public function findByUuid(string $uuid): Agent
    {
        return new Agent();
    }//end findByUuid()

    /**
     * Whether the user may access an agent.
     *
     * - Non-private agents: anyone in the organisation may access.
     * - Private agents: owner or an explicitly invited user only.
     *
     * @param Agent  $agent  The agent entity.
     * @param string $userId The user id.
     *
     * @return bool True when the user may access the agent.
     */
    public function canUserAccessAgent(Agent $agent, string $userId): bool
    {
        // Non-private agents are accessible to all users in the organisation.
        if ($agent->getIsPrivate() === false || $agent->getIsPrivate() === null) {
            return true;
        }

        // Owner always has access.
        if ($agent->getOwner() === $userId) {
            return true;
        }

        // Check if user is invited.
        if ($agent->hasInvitedUser($userId) === true) {
            return true;
        }

        return false;
    }//end canUserAccessAgent()

    /**
     * Whether the user may modify an agent — owner only.
     *
     * @param Agent  $agent  The agent entity.
     * @param string $userId The user id.
     *
     * @return bool True when the user may modify the agent.
     */
    public function canUserModifyAgent(Agent $agent, string $userId): bool
    {
        return $agent->getOwner() === $userId;
    }//end canUserModifyAgent()
}//end class
