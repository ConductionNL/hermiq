<?php

/**
 * Hermiq AppAssistantService.
 *
 * An organisation admin marks the agent that answers in an app's assistant, one per
 * app per organisation (agents-bound-to-their-app). The flag is written here, with
 * the uniqueness rule across agents; the register lets only an instance admin write
 * it through OpenRegister's object API, so an owner cannot promote their own agent.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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

namespace OCA\Hermiq\Service\Agent;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Marks and unmarks an app's assistant.
 *
 * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */
class AppAssistantService
{

    /**
     * Agents per page when looking for the app's current assistant.
     *
     * @var integer
     */
    private const PAGE_SIZE = 100;

    /**
     * Constructor.
     *
     * @param ObjectService            $objectService Reads and writes agents.
     * @param AgentAvailabilityService $availability  Who may read an agent and who administers an organisation.
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AgentAvailabilityService $availability,
    ) {
    }//end __construct()

    /**
     * Mark (or unmark) an agent as the assistant of the app it serves.
     *
     * @param string $agentId   The agent.
     * @param bool   $assistant Whether it answers in its app.
     * @param string $uid       Who marks it.
     *
     * @return ObjectEntity The stored agent.
     *
     * @throws RuntimeException 404 unknown or unreadable, 403 not an admin of its organisation,
     *                          422 no app, 409 the app already has an assistant.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag IS a boolean.
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function mark(string $agentId, bool $assistant, string $uid): ObjectEntity
    {
        $agent = $this->availability->readableAgent($agentId, $uid);
        if ($agent === null) {
            throw new RuntimeException('Agent not found', 404);
        }

        $organisation = (string) ($agent->getOrganisation() ?? '');
        if ($this->availability->mayAdministerOrganisation(organisation: $organisation, uid: $uid) === false) {
            throw new RuntimeException('Only an admin of the agent\'s organisation chooses the assistant for an app.', 403);
        }

        $data = $agent->getObject();
        $app  = strtolower(trim((string) ($data['applicationSlug'] ?? '')));
        if ($assistant === true && $app === '') {
            throw new RuntimeException('Choose the app this agent serves first.', 422);
        }

        if ($assistant === true && $this->otherAssistant(app: $app, organisation: $organisation, agentId: (string) $agent->getUuid()) === true) {
            throw new RuntimeException('Another agent already answers in this app', 409);
        }

        $data['appAssistant'] = $assistant;

        return $this->objectService->saveObject(
            object: $data,
            register: 'hermiq',
            schema: 'agent',
            uuid: (string) $agent->getUuid(),
            _rbac: false,
            _multitenancy: false
        );

    }//end mark()

    /**
     * Whether another agent of the same organisation already answers in this app.
     *
     * @param string $app          The app slug, lower case.
     * @param string $organisation The organisation.
     * @param string $agentId      The agent being marked.
     *
     * @return bool
     *
     * @spec openspec/changes/agents-bound-to-their-app/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    private function otherAssistant(string $app, string $organisation, string $agentId): bool
    {
        $offset = 0;
        do {
            $page = $this->objectService
                ->setRegister('hermiq')
                ->setSchema('agent')
                ->findAll(config: ['limit' => self::PAGE_SIZE, 'offset' => $offset], _rbac: false, _multitenancy: false);
            foreach ($page as $other) {
                if (($other instanceof ObjectEntity) === false
                    || (string) $other->getUuid() === $agentId
                    || (string) ($other->getOrganisation() ?? '') !== $organisation
                ) {
                    continue;
                }

                $data = $other->getObject();
                if (($data['appAssistant'] ?? false) === true && strtolower(trim((string) ($data['applicationSlug'] ?? ''))) === $app) {
                    return true;
                }
            }

            $offset += self::PAGE_SIZE;
        } while (count($page) === self::PAGE_SIZE);

        return false;

    }//end otherAssistant()
}//end class
