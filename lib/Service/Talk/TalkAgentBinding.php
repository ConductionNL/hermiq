<?php

/**
 * Hermiq TalkAgentBinding.
 *
 * Resolves which agent — if any — a Talk room is bound to, and enforces the
 * Hermiq half of the two-sided opt-in.
 *
 * The bridge requires BOTH switches, and both default to off: a Talk moderator
 * must enable the Hermiq bot in the room, AND the agent must be marked
 * Talk-enabled in Hermiq. Neither alone activates anything, so installing this
 * change changes no behaviour until an operator deliberately turns it on.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#7-opt-in-and-admin-visibility
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the opted-in agent bound to a Talk room.
 *
 * @psalm-api
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
 */
class TalkAgentBinding
{

    /**
     * OpenRegister register slug.
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
     * IAppConfig key holding the room-token → agent-uuid map.
     *
     * A room is bound to an agent by an administrator; the map is small,
     * administrative configuration rather than domain data, so it lives in
     * app config rather than becoming another register schema.
     *
     * @var string
     */
    public const ROOM_AGENT_MAP_KEY = 'talk_room_agents';

    /**
     * IAppConfig key holding the default agent for rooms with no explicit binding.
     *
     * @var string
     */
    public const DEFAULT_AGENT_KEY = 'talk_default_agent';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object read.
     * @param IAppConfig      $appConfig     Room→agent binding configuration.
     * @param LoggerInterface $logger        PSR-3 logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The uuid of the opted-in agent bound to a room, or null.
     *
     * Returns null — meaning "take no turn" — when the room has no binding,
     * when the bound agent no longer exists, or when that agent is not
     * Talk-enabled. Null is the DEFAULT state and is not an error.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return string|null The agent uuid, or null when the bridge is not active here.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
     */
    public function agentForRoom(string $roomToken): ?string
    {
        $agentId = $this->boundAgentId(roomToken: $roomToken);
        if ($agentId === null) {
            return null;
        }

        $agent = $this->loadAgent(agentId: $agentId);
        if ($agent === null) {
            return null;
        }

        if ($this->isTalkEnabled(agent: $agent) === false) {
            $this->logger->debug(
                message: '[TalkAgentBinding] Agent is bound to the room but not Talk-enabled — no turn taken',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'roomToken' => $roomToken,
                    'agentId'   => $agentId,
                ]
            );
            return null;
        }

        return $agentId;

    }//end agentForRoom()

    /**
     * The configured agent uuid for a room, without the opt-in check.
     *
     * @param string $roomToken The Talk room token.
     *
     * @return string|null The configured agent uuid, or null.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
     */
    public function boundAgentId(string $roomToken): ?string
    {
        if ($roomToken === '') {
            return null;
        }

        $map   = $this->roomAgentMap();
        $bound = ($map[$roomToken] ?? null);
        if (is_string($bound) === true && $bound !== '') {
            return $bound;
        }

        $default = $this->appConfig->getValueString('hermiq', self::DEFAULT_AGENT_KEY, '');
        if ($default === '') {
            return null;
        }

        return $default;

    }//end boundAgentId()

    /**
     * The full room-token → agent-uuid map.
     *
     * @return array<string, string> The configured bindings.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
     */
    public function roomAgentMap(): array
    {
        $raw = $this->appConfig->getValueString('hermiq', self::ROOM_AGENT_MAP_KEY, '');
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[TalkAgentBinding] Room→agent map is not valid JSON — treating it as empty',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return [];
        }

        if (is_array($decoded) === false) {
            return [];
        }

        $map = [];
        foreach ($decoded as $token => $agentId) {
            if (is_string($token) === true && is_string($agentId) === true && $token !== '' && $agentId !== '') {
                $map[$token] = $agentId;
            }
        }

        return $map;

    }//end roomAgentMap()

    /**
     * The room bound to a given agent, if any.
     *
     * The reverse of `roomAgentMap()`. Used when something needs to reach an
     * agent's room without already knowing the token — posting an approval
     * request where the reviewer can react to it, for instance.
     *
     * More than one room may name the same agent; the first is returned, which
     * is deterministic for a map read back in insertion order.
     *
     * @param string $agentId The agent uuid.
     *
     * @return string|null The room token, or null when no room is bound to it.
     *
     * @spec openspec/changes/talk-approval-reactions/specs/talk-approval-reactions/spec.md#requirement-an-approval-request-posted-to-talk-records-where-it-landed
     */
    public function roomForAgent(string $agentId): ?string
    {
        if ($agentId === '') {
            return null;
        }

        foreach ($this->roomAgentMap() as $token => $bound) {
            if ($bound === $agentId) {
                return $token;
            }
        }

        return null;

    }//end roomForAgent()

    /**
     * Bind a room to an agent.
     *
     * @param string $roomToken The Talk room token.
     * @param string $agentId   The agent uuid, or an empty string to unbind.
     *
     * @return void
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
     */
    public function bindRoom(string $roomToken, string $agentId): void
    {
        if ($roomToken === '') {
            return;
        }

        $map = $this->roomAgentMap();
        unset($map[$roomToken]);
        if ($agentId !== '') {
            $map[$roomToken] = $agentId;
        }

        $this->appConfig->setValueString('hermiq', self::ROOM_AGENT_MAP_KEY, json_encode($map));

    }//end bindRoom()

    /**
     * Whether an agent has opted in to being reachable from Talk.
     *
     * Defaults to FALSE for every agent that predates this change, so the
     * bridge stays inert until deliberately enabled.
     *
     * @param ObjectEntity $agent The agent object.
     *
     * @return bool True when the agent is Talk-enabled.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
     */
    private function isTalkEnabled(ObjectEntity $agent): bool
    {
        $data = $agent->getObject();

        return (($data['talkEnabled'] ?? false) === true);

    }//end isTalkEnabled()

    /**
     * An agent's display name, or an empty string when it cannot be read.
     *
     * This is the name Talk shows on the agent's own bot, and therefore the
     * name a user types to address it. Returns '' rather than throwing: the
     * only caller is the mention matcher, where "no name" must degrade to "not
     * addressed by name", never to a failed turn.
     *
     * @param string $agentId The agent uuid.
     *
     * @return string The agent's name, or ''.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-each-talk-enabled-agent-has-its-own-talk-bot-identity
     */
    /**
     * Whether an agent has opted into Talk.
     *
     * 🔴 The opt-in is TWO-SIDED and this is the agent's half. Without it a
     * session for an agent that never opted in still gets a room created —
     * and that room is useless by construction, because a Talk-disabled agent
     * has no bot to enable in it. Caught by e2e, not by unit tests: every unit
     * fixture and every live probe used an opted-in agent.
     *
     * Defaults to FALSE for an agent that cannot be read, matching
     * isTalkEnabled(): the bridge stays inert unless deliberately enabled.
     *
     * @param string $agentId The agent uuid.
     *
     * @return bool True when the agent is Talk-enabled.
     *
     * @spec openspec/changes/talk-agent-sessions/specs/talk-agent-sessions/spec.md#requirement-creating-a-chat-session-creates-and-owns-its-talk-room
     */
    public function isAgentTalkEnabled(string $agentId): bool
    {
        $agent = $this->loadAgent(agentId: $agentId);
        if (($agent instanceof ObjectEntity) === false) {
            return false;
        }

        return $this->isTalkEnabled(agent: $agent);

    }//end isAgentTalkEnabled()

    public function agentName(string $agentId): string
    {
        $agent = $this->loadAgent(agentId: $agentId);
        if (($agent instanceof ObjectEntity) === false) {
            return '';
        }

        return (string) (($agent->getObject())['name'] ?? '');

    }//end agentName()

    /**
     * Load an agent object by uuid.
     *
     * @param string $agentId The agent uuid.
     *
     * @return ObjectEntity|null The agent, or null when it no longer exists.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-the-integration-is-opt-in-on-both-sides
     */
    private function loadAgent(string $agentId): ?ObjectEntity
    {
        try {
            $agent = $this->objectService->find(
                id: $agentId,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );

            if (($agent instanceof ObjectEntity) === false) {
                return null;
            }

            return $agent;
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[TalkAgentBinding] Bound agent could not be loaded',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'agentId' => $agentId,
                    'error'   => $e->getMessage(),
                ]
            );
            return null;
        }//end try

    }//end loadAgent()
}//end class
