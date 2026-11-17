<?php

/**
 * Hermiq AssistantPick
 *
 * The running choice of the agent that answers in one app, as usable agents
 * are seen in order: the app's own assistant at once, otherwise the first agent
 * of the app, otherwise the first usable agent.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\Service\Agent\AppAssistantChoice;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Picks the answering agent for one app.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
 */
final class AssistantPick
{

    /**
     * Reads which app an agent answers in.
     *
     * @var AppAssistantChoice
     */
    private readonly AppAssistantChoice $choice;

    /**
     * The first usable agent seen.
     *
     * @var string
     */
    private string $firstAccessible = '';

    /**
     * The first usable agent of the app seen.
     *
     * @var string
     */
    private string $firstOfApp = '';

    /**
     * Start a pick.
     *
     * @param string $appId The app, lower-cased and trimmed; empty for none.
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function __construct(
        private readonly string $appId,
    ) {
        $this->choice = new AppAssistantChoice();
    }//end __construct()

    /**
     * Consider one usable agent: its uuid when it answers here at once, else null.
     *
     * @param ObjectEntity $agent A usable agent.
     *
     * @return string|null
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function consider(ObjectEntity $agent): ?string
    {
        $uuid = (string) $agent->getUuid();
        $data = $agent->getObject();
        if ($this->answersHere(data: $data) === true) {
            return $uuid;
        }

        $this->remember(uuid: $uuid, data: $data);

        return null;

    }//end consider()

    /**
     * Whether an agent answers here at once: there is no app, or it is the app's assistant.
     *
     * @param array<string, mixed> $data The agent's payload.
     *
     * @return bool
     */
    private function answersHere(array $data): bool
    {
        return ($this->appId === '' || $this->choice->answersIn(data: $data, app: $this->appId) === true);

    }//end answersHere()

    /**
     * Remember the first usable agent, and the first one of the app.
     *
     * @param string               $uuid The agent's uuid.
     * @param array<string, mixed> $data The agent's payload.
     *
     * @return void
     */
    private function remember(string $uuid, array $data): void
    {
        if ($this->firstAccessible === '') {
            $this->firstAccessible = $uuid;
        }

        if ($this->firstOfApp === '' && $this->choice->appOf(data: $data) === $this->appId) {
            $this->firstOfApp = $uuid;
        }

    }//end remember()

    /**
     * The answer when no agent answered at once: the first of the app, else the first usable.
     *
     * @return string
     *
     * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-organisation-admin-picks-the-agent-that-answers-in-an-app-req-appag-002
     */
    public function fallback(): string
    {
        if ($this->firstOfApp !== '') {
            return $this->firstOfApp;
        }

        return $this->firstAccessible;

    }//end fallback()
}//end class
