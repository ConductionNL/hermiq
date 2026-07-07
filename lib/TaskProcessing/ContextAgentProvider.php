<?php

/**
 * Hermiq `core:contextagent:interaction` provider (flagship).
 *
 * Registers Hermiq as an ALTERNATIVE provider for Nextcloud Assistant's agent-chat
 * task type (SPECTR-NEXTCLOUD-PLAN.md §8 move 3). NC ships a stock `context_agent`
 * ExApp (LangChain/LangGraph) as the other provider for this same task type; an admin
 * picks the preferred provider per task type. Hermiq's differentiator is governance —
 * approval gate, kill-switch, per-agent capability profiles, redacted audit.
 *
 * This class is a thin `ISynchronousProvider` adapter over
 * `ContextAgentInteractionService`, which owns the confirmation→approval-gate mapping,
 * conversation_token↔Conversation binding, actions↔tool-allowlist disclosure, and the
 * kill-switch gate. The multi-turn action-confirmation loop is deferred to a single-turn
 * path (see the service docblock + openspec/changes/contextagent-provider/design.md).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

use OCA\Hermiq\Service\ContextAgentInteractionService;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;

/**
 * Hermiq's provider for the `core:contextagent:interaction` task type.
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-1-1
 */
class ContextAgentProvider implements ISynchronousProvider
{
    use EmptyOptionalShapesTrait;

    /**
     * Constructor.
     *
     * @param ContextAgentInteractionService $interactionService Runs the governed turn.
     *
     * @return void
     */
    public function __construct(
        private readonly ContextAgentInteractionService $interactionService,
    ) {
    }//end __construct()

    /**
     * The unique id of this provider.
     *
     * @return string
     *
     * @spec exclude Trivial provider identity accessor; no behavioural spec.
     */
    public function getId(): string
    {
        return 'hermiq:contextagent';
    }//end getId()

    /**
     * The localized name of this provider.
     *
     * @return string
     *
     * @spec exclude Trivial provider name accessor; no behavioural spec.
     */
    public function getName(): string
    {
        return 'Hermiq (governed agents)';
    }//end getName()

    /**
     * The task type this provider handles.
     *
     * @return string
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-1-1
     */
    public function getTaskTypeId(): string
    {
        return ContextAgentInteraction::ID;
    }//end getTaskTypeId()

    /**
     * The expected average runtime of a task in seconds (an agent turn may call
     * tools, so budget more than a plain text2text call).
     *
     * @return int
     *
     * @spec exclude Trivial framework runtime hint; no behavioural spec.
     */
    public function getExpectedRuntime(): int
    {
        return 30;
    }//end getExpectedRuntime()

    /**
     * Run one ContextAgent interaction turn.
     *
     * Maps the NC ContextAgent input (`input` + `confirmation` + `conversation_token`)
     * onto a governed Hermiq turn and returns the ContextAgent output shape (`output`
     * + new `conversation_token` + `actions` JSON).
     *
     * @param string|null $userId         The user that created the task (owns the conversation).
     * @param array       $input          The task input (`input`, `confirmation`, `conversation_token`).
     * @param callable    $reportProgress Progress reporter (single blocking call; reported once).
     *
     * @return array{output: string, conversation_token: string, actions: string}
     *
     * @throws \OCP\TaskProcessing\Exception\ProcessingException When there is no user
     *         context, no available agent, the org kill-switch is engaged, or the turn fails.
     *
     * @psalm-param  callable(float):bool $reportProgress
     * @psalm-return array{output: string, conversation_token: string, actions: string}
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-1-2
     */
    public function process(?string $userId, array $input, callable $reportProgress): array
    {
        $message           = (string) ($input['input'] ?? '');
        $conversationToken = (string) ($input['conversation_token'] ?? '');

        // `confirmation` is a Number slot; null when the client is not answering a
        // prior action request.
        $confirmation = null;
        if (array_key_exists('confirmation', $input) === true && is_numeric($input['confirmation']) === true) {
            $confirmation = (int) $input['confirmation'];
        }

        $result = $this->interactionService->interact(
            userId: $userId,
            input: $message,
            confirmation: $confirmation,
            conversationToken: $conversationToken
        );

        // Report completion so cancelled tasks stop cleanly (single blocking call).
        $reportProgress(1.0);

        return $result;

    }//end process()
}//end class
