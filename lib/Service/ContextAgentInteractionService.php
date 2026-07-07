<?php

/**
 * Hermiq ContextAgent interaction service.
 *
 * The engine behind Hermiq's `core:contextagent:interaction` TaskProcessing provider
 * (SPECTR-NEXTCLOUD-PLAN.md §8 move 3, the flagship): it puts Hermiq's governed
 * agents behind Nextcloud Assistant's agent-chat surface. NC's ContextAgent shape —
 * `input` + `confirmation` (0/1) + `conversation_token` → `output` + new token +
 * `actions` JSON — maps 1:1 onto Hermiq's governance primitives:
 *
 *   - conversation_token ↔ a Hermiq `Conversation` OR object (create on first turn,
 *     reuse thereafter);
 *   - confirmation (0/1) ↔ a Hermiq approval-gate decision (deny / approve) on the
 *     user's pending Approval for this agent;
 *   - actions JSON ↔ the per-agent tool allowlist (`Agent.tools`) — the governance
 *     disclosure of what the agent may do;
 *   - plus the org kill-switch halts the interaction before the agent runs.
 *
 * NC ships a stock `context_agent` ExApp (LangChain/LangGraph) as the OTHER provider
 * for this task type; Hermiq registers as the ALTERNATIVE (admin picks the preferred
 * provider per task type). Hermiq's differentiator is governance — approval gate,
 * kill-switch, per-agent capability profile, redacted audit.
 *
 * DEFERRED (single-turn scope; see openspec/changes/contextagent-provider/design.md):
 * the stateful multi-turn "propose actions → pause → confirm → resume the exact tool
 * execution" loop. This pass runs one governed turn per interaction (Hermiq's engine
 * executes its own allowlist-gated tool loop inline), surfaces the allowlist in
 * `actions`, and maps a supplied `confirmation` onto approve/deny of the user's
 * pending Approval for the agent — it does NOT yet pause a turn awaiting confirmation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\TaskProcessing\Exception\ProcessingException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one governed ContextAgent interaction turn.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The interaction seam intentionally
 * ties together conversation storage, the engine, the approval gate, the kill-switch
 * and the audit write — that governance composition IS the feature.
 *
 * @spec openspec/changes/contextagent-provider/tasks.md#task-2-1
 */
class ContextAgentInteractionService
{
    /**
     * OpenRegister register slug holding Hermiq agent-engine objects.
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
     * Schema slug for conversation objects.
     *
     * @var string
     */
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * IAppConfig key naming the agent that serves ContextAgent interactions.
     *
     * @var string
     */
    private const AGENT_CONFIG_KEY = 'contextagent_agent';

    /**
     * Constructor.
     *
     * @param ObjectService    $objectService    OR object read/write.
     * @param Engine           $engine           Hermiq agent engine (runs the turn).
     * @param ApprovalService  $approvalService  Approval-gate decisions (confirmation mapping).
     * @param ScheduleService  $scheduleService  Kill-switch source (isOrganisationEngaged).
     * @param AuditTrailMapper $auditTrailMapper Redacted per-interaction audit write-path.
     * @param RedactionService $redactionService Masks secrets/PII before the audit write.
     * @param IAppConfig       $appConfig        Reads the configured ContextAgent agent id.
     * @param LoggerInterface  $logger           Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly Engine $engine,
        private readonly ApprovalService $approvalService,
        private readonly ScheduleService $scheduleService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly RedactionService $redactionService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Run one ContextAgent interaction turn.
     *
     * @param string|null $userId            The task creator (owns the conversation; required).
     * @param string      $input             The chat message.
     * @param int|null    $confirmation      The client's confirmation of previously-requested
     *                                       actions: 1 to confirm, 0 to deny, null when not
     *                                       answering a prior request.
     * @param string      $conversationToken The conversation token (a Hermiq Conversation UUID);
     *                                       empty to start a new conversation.
     *
     * @return array{output: string, conversation_token: string, actions: string} The
     *         ContextAgent output shape (reply, the new token, and the agent's action
     *         allowlist as JSON).
     *
     * @throws ProcessingException When there is no user context, no available agent, the
     *                             org kill-switch is engaged, or the turn fails.
     *
     * @spec openspec/changes/contextagent-provider/tasks.md#task-2-2
     */
    public function interact(?string $userId, string $input, ?int $confirmation, string $conversationToken): array
    {
        if ($userId === null || $userId === '') {
            throw new ProcessingException('Hermiq ContextAgent requires a user context.');
        }

        if (trim($input) === '') {
            throw new ProcessingException('Hermiq ContextAgent requires a non-empty message.');
        }

        $agent = $this->resolveAgent();
        if ($agent === null) {
            throw new ProcessingException(
                'No Hermiq agent is available to serve ContextAgent. Configure one via the `contextagent_agent` app setting.'
            );
        }

        $agentId = (string) $agent->getUuid();

        // GATE — org kill-switch (EU-AI-Act Art. 14 / governance differentiator).
        $organisation = (string) ($agent->getOrganisation() ?? '');
        if ($this->scheduleService->isOrganisationEngaged(organisation: $organisation) === true) {
            $this->audit(object: $agent, status: 'skipped_killswitch', summary: '', agentId: $agentId);
            throw new ProcessingException('Hermiq kill-switch is engaged for this organisation; the agent will not run.');
        }

        $conversation   = $this->resolveConversation(
            conversationToken: $conversationToken,
            userId: $userId,
            agentId: $agentId
        );
        $conversationId = (string) $conversation->getUuid();

        // Confirmation (0/1) → approval-gate decision on the user's pending Approval
        // for this agent. A no-op when confirmation is absent or no such approval
        // exists (the common single-turn case).
        $confirmationOutcome = $this->applyConfirmation(
            confirmation: $confirmation,
            userId: $userId,
            agentId: $agentId
        );

        // Run one governed turn through Hermiq's engine (its own allowlist-gated tool
        // loop executes inline).
        try {
            $result = $this->engine->processMessage(
                conversationId: $conversationId,
                userId: $userId,
                userMessage: $input
            );
        } catch (Throwable $e) {
            $this->audit(object: $conversation, status: 'error', summary: $e->getMessage(), agentId: $agentId);
            $this->logger->warning(
                message: '[ContextAgentInteractionService] Engine turn failed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            throw new ProcessingException('Hermiq ContextAgent turn failed: '.$e->getMessage(), 0, $e);
        }

        $output = (string) $result['message'];

        $this->audit(
            object: $conversation,
            status: 'ok',
            summary: $confirmationOutcome,
            agentId: $agentId
        );

        return [
            'output'             => $output,
            'conversation_token' => $conversationId,
            'actions'            => $this->buildActions(agent: $agent),
        ];

    }//end interact()

    /**
     * Resolve the agent that serves ContextAgent interactions: the configured
     * `contextagent_agent` UUID when set and resolvable, otherwise the first active
     * agent in the hermiq register.
     *
     * @return ObjectEntity|null The agent, or null when none is available.
     */
    private function resolveAgent(): ?ObjectEntity
    {
        $configured = $this->appConfig->getValueString(Application::APP_ID, self::AGENT_CONFIG_KEY, '');
        if ($configured !== '') {
            $agent = $this->objectService->find(
                id: $configured,
                register: self::REGISTER_SLUG,
                schema: self::AGENT_SCHEMA
            );
            if ($agent instanceof ObjectEntity) {
                return $agent;
            }
        }

        $agents = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::AGENT_SCHEMA)
            ->findAll(config: ['filters' => ['active' => true], 'limit' => 1]);

        foreach ($agents as $agent) {
            if ($agent instanceof ObjectEntity) {
                return $agent;
            }
        }

        return null;

    }//end resolveAgent()

    /**
     * Resolve the conversation for this interaction: reuse the token's conversation
     * when it exists and belongs to the user, otherwise create a fresh one bound to
     * the user + agent.
     *
     * @param string $conversationToken The incoming token (a Conversation UUID or empty).
     * @param string $userId            The owning user.
     * @param string $agentId           The serving agent UUID.
     *
     * @return ObjectEntity The resolved (existing or newly-created) Conversation.
     */
    private function resolveConversation(string $conversationToken, string $userId, string $agentId): ObjectEntity
    {
        if ($conversationToken !== '') {
            $existing = $this->objectService->find(
                id: $conversationToken,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
            if ($existing instanceof ObjectEntity
                && (string) ($existing->getObject()['userId'] ?? '') === $userId
            ) {
                return $existing;
            }
        }

        return $this->objectService->saveObject(
            object: [
                'userId'   => $userId,
                'agentId'  => $agentId,
                'title'    => 'Assistant agent chat',
                'metadata' => ['source' => 'contextagent'],
            ],
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA
        );

    }//end resolveConversation()

    /**
     * Map a supplied confirmation (0/1) onto an approval-gate decision on the user's
     * pending Approval for this agent, when one exists. Returns a short human-readable
     * summary of what happened (for the audit line).
     *
     * @param int|null $confirmation The confirmation value (1 confirm, 0 deny, null absent).
     * @param string   $userId       The confirming user (must be the approval's reviewer).
     * @param string   $agentId      The agent the approval must belong to.
     *
     * @return string A summary: 'no-confirmation', 'confirmation:no-pending',
     *                'confirmation:approved' or 'confirmation:denied'.
     */
    private function applyConfirmation(?int $confirmation, string $userId, string $agentId): string
    {
        if ($confirmation === null) {
            return 'no-confirmation';
        }

        $pending = $this->approvalService->listPendingForReviewer(uid: $userId);
        $match   = null;
        foreach ($pending as $record) {
            if (($record['agentId'] ?? '') === $agentId) {
                $match = $record;
                break;
            }
        }

        if ($match === null) {
            return 'confirmation:no-pending';
        }

        $approval = $this->approvalService->loadApproval(uuid: (string) $match['id']);
        if ($approval === null) {
            return 'confirmation:no-pending';
        }

        if ($confirmation >= 1) {
            $this->approvalService->approve(approval: $approval, deciderUid: $userId);
            return 'confirmation:approved';
        }

        $this->approvalService->deny(
            approval: $approval,
            deciderUid: $userId,
            reason: 'Denied via ContextAgent confirmation'
        );
        return 'confirmation:denied';

    }//end applyConfirmation()

    /**
     * Build the `actions` output: the agent's tool allowlist plus a note that the
     * stateful propose-then-confirm loop is deferred (single-turn scope).
     *
     * @param ObjectEntity $agent The serving agent.
     *
     * @return string A JSON string describing the agent's permitted actions.
     */
    private function buildActions(ObjectEntity $agent): string
    {
        $tools = $agent->getObject()['tools'] ?? [];
        if (is_array($tools) === false) {
            $tools = [];
        }

        $note  = 'Hermiq executes governed tools inline under this per-agent allowlist. ';
        $note .= 'A stateful propose-then-confirm action loop is deferred; see contextagent-provider/design.md.';

        $payload = [
            'toolAllowlist' => array_values($tools),
            'note'          => $note,
        ];

        $json = json_encode($payload);
        if ($json === false) {
            return '{}';
        }

        return $json;

    }//end buildActions()

    /**
     * Write a redacted `contextagent-interaction` AuditTrail entry. Never fatal.
     *
     * @param ObjectEntity $object  The object to attach the audit entry to (agent or conversation).
     * @param string       $status  The interaction status (ok/error/skipped_killswitch).
     * @param string       $summary A short summary (redacted before persist).
     * @param string       $agentId The serving agent UUID.
     *
     * @return void
     */
    private function audit(ObjectEntity $object, string $status, string $summary, string $agentId): void
    {
        try {
            $this->auditTrailMapper->createAuditTrailEntry(
                object: $object,
                action: 'contextagent-interaction',
                context: [
                    'status'  => $status,
                    'agentId' => $agentId,
                    'summary' => $this->redactionService->redact($summary),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ContextAgentInteractionService] Could not write interaction audit',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }

    }//end audit()
}//end class
