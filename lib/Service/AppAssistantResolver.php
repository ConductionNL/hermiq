<?php

/**
 * Hermiq AppAssistantResolver.
 *
 * The agent that answers when a chat opens in an app without naming an agent, on
 * both chat endpoints: the app's assistant (chosen by an organisation admin) when
 * the user may use it, else an agent of that app the user may use, else the first
 * agent the user may use. Access and the agent's switch are decided before the app
 * preference, never by it.
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
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Generator;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\Agent\AppAssistantChoice;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Picks the agent for an app.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */
class AppAssistantResolver
{

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService Reads the agents.
     * @param AgentAccessService $agentAccess   Who may use an agent.
     * @param LoggerInterface    $logger        Logs a failed lookup.
     * @param int                $pageSize      Agents per page (paged to exhaustion, never a cap).
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AgentAccessService $agentAccess,
        private readonly LoggerInterface $logger,
        private readonly int $pageSize = 100,
    ) {
    }//end __construct()

    /**
     * The agent uuid that answers for this user in this app, or '' when none.
     *
     * @param string $userId The user.
     * @param string $appId  The app the chat is open in ('' when unknown).
     *
     * @return string
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function resolve(string $userId, string $appId): string
    {
        $appId           = strtolower(trim($appId));
        $choice          = new AppAssistantChoice();
        $firstAccessible = '';
        $firstOfApp      = '';

        try {
            foreach ($this->usableAgents(userId: $userId) as $agent) {
                $uuid = (string) $agent->getUuid();
                $data = $agent->getObject();
                if ($appId === '' || $choice->answersIn(data: $data, app: $appId) === true) {
                    return $uuid;
                }

                if ($firstAccessible === '') {
                    $firstAccessible = $uuid;
                }

                if ($firstOfApp === '' && $choice->appOf(data: $data) === $appId) {
                    $firstOfApp = $uuid;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('[AppAssistantResolver] Agent lookup failed: '.$e->getMessage(), ['exception' => $e]);
        }

        if ($firstOfApp !== '') {
            return $firstOfApp;
        }

        return $firstAccessible;

    }//end resolve()

    /**
     * Every agent the user may chat with now, page by page, in register order.
     *
     * @param string $userId The user.
     *
     * @return Generator<int, ObjectEntity>
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    private function usableAgents(string $userId): Generator
    {
        $offset = 0;
        do {
            $page    = $this->objectService
                ->setRegister('hermiq')
                ->setSchema('agent')
                ->findAll(config: ['limit' => $this->pageSize, 'offset' => $offset]);
            $fetched = count($page);
            foreach ($page as $agent) {
                if ($this->usable(agent: $agent, userId: $userId) === true) {
                    yield $agent;
                }
            }

            $offset += $this->pageSize;
        } while ($fetched === $this->pageSize);

    }//end usableAgents()

    /**
     * Whether the user may chat with this agent now: it is an agent, the user may
     * use it, and it is switched on.
     *
     * @param mixed  $agent  A row from the agent register.
     * @param string $userId The user.
     *
     * @return bool
     *
     * @psalm-assert-if-true ObjectEntity $agent
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    private function usable(mixed $agent, string $userId): bool
    {
        if (($agent instanceof ObjectEntity) === false) {
            return false;
        }

        if ($this->agentAccess->canUserAccessAgent(agent: $agent, userId: $userId) === false) {
            return false;
        }

        return (new AgentAvailability())->isOn(agent: $agent);

    }//end usable()
}//end class
