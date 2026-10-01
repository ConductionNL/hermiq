<?php

/**
 * Hermiq AppAssistantChoice.
 *
 * Reads an agent's app and its assistant choice (agents-bound-to-their-app). The
 * choice (`appAssistantFor`) holds only while it equals the app the agent serves
 * (`applicationSlug`), so moving the agent to another app ends it.
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
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

/**
 * Pure reads over an agent's stored fields.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */
class AppAssistantChoice
{

    /**
     * The app the agent serves, trimmed and lower case; '' for none.
     *
     * @param array<string, mixed> $data The agent.
     *
     * @return string
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function appOf(array $data): string
    {
        return strtolower(trim((string) ($data['applicationSlug'] ?? '')));

    }//end appOf()

    /**
     * Whether the agent answers in this app's assistant.
     *
     * @param array<string, mixed> $data The agent.
     * @param string               $app  The app slug, lower case.
     *
     * @return bool
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function answersIn(array $data, string $app): bool
    {
        $chosenFor = strtolower(trim((string) ($data['appAssistantFor'] ?? '')));

        return $app !== '' && $chosenFor === $app && $this->appOf(data: $data) === $app;

    }//end answersIn()
}//end class
