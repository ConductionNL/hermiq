<?php

/**
 * Hermiq Chat Conversation Management Handler.
 *
 * Handler for conversation lifecycle management: title generation, summarisation.
 * Ported from `OCA\OpenRegister\Service\Chat\ConversationManagementHandler`,
 * re-pointed at the `Conversation`/`Message` OR objects (agent-engine-schemas) via
 * `ObjectService`, and at `ProviderFactory` instead of OR's inline per-provider
 * LLPhant config building.
 *
 * Ground-truth adaptation (documented per the pause protocol — "document,
 * continue"): the ported original's Fireworks title/summary path invoked
 * `ResponseGenerationHandler::callFireworksChatAPI()` via reflection — a method
 * that does not exist on `ResponseGenerationHandler` at openregister HEAD (only
 * `callFireworksChatAPIWithHistory($apiKey, $model, $baseUrl, $messageHistory,
 * $functions)` does, taking a message-history array, not a single prompt
 * string). That reflection call would throw `ReflectionException` at runtime —
 * a latent, apparently-never-exercised bug in the ground truth. This port
 * calls `ProviderFactory::callFireworksChat()` (the ported
 * `callFireworksChatAPIWithHistory`) with a single-message history
 * (`[Message::user($prompt)]`), which produces an identical Fireworks API
 * request shape and actually works.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use DateTime;
use Exception;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use LLPhant\Chat\Message as LLPhantMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles conversation title generation, summarisation, and uniqueness.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class ConversationManagementHandler
{
    use SanitizesForSaveTrait;

    /**
     * OpenRegister register slug that holds Hermiq agent-engine objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for conversation objects.
     *
     * @var string
     */
    private const CONVERSATION_SCHEMA = 'conversation';

    /**
     * Maximum tokens before triggering summarisation.
     *
     * @var int
     */
    private const MAX_TOKENS_BEFORE_SUMMARY = 4000;

    /**
     * Number of recent messages to keep when summarising.
     *
     * @var int
     */
    private const RECENT_MESSAGES_COUNT = 10;

    /**
     * Upper bound on how many historical messages a single summarisation pass
     * will consider (resource guard; not present in the ported original,
     * which had no cap — see class docblock for the adaptation rationale).
     *
     * @var int
     */
    private const MAX_MESSAGES_FOR_SUMMARY = 1000;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService   OpenRegister object read/write.
     * @param ProviderFactory $providerFactory LLM provider resolution.
     * @param LoggerInterface $logger          Logger.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ProviderFactory $providerFactory,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Generate a conversation title from the first user message.
     *
     * Uses the configured LLM; falls back to a truncated-message title when no
     * provider is configured or generation fails.
     *
     * @param string      $firstMessage First user message.
     * @param string|null $organisation The conversation's organisation
     *                                  (tenant-model-policy); null skips policy
     *                                  enforcement (backward-compatible default —
     *                                  see ProviderFactory::createChatDriver()).
     *
     * @return string Generated title.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    public function generateConversationTitle(string $firstMessage, ?string $organisation=null): string
    {
        $this->logger->info(
            message: '[ConversationManagementHandler] Generating conversation title',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        $prompt  = 'Generate a short, descriptive title (max 60 characters) for a conversation ';
        $prompt .= "that starts with this message:\n\n";
        $prompt .= "\"{$firstMessage}\"\n\n";
        $prompt .= 'Title:';

        try {
            $title = $this->generateTextViaConfiguredLlm(prompt: $prompt, organisation: $organisation);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ConversationManagementHandler] Failed to generate title, using fallback',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return $this->generateFallbackTitle(message: $firstMessage);
        }

        $title = trim($title, "\"'");
        if (strlen($title) > 60) {
            $title = substr($title, 0, 57).'...';
        }

        return $title;

    }//end generateConversationTitle()

    /**
     * Ensure a conversation title is unique for a user-agent combination.
     *
     * Appends a numeric suffix (`Title (2)`, `Title (3)`, ...) when a
     * conversation with the same title already exists for this user + agent.
     *
     * Adapted from the ported original's `ConversationMapper::findTitlesByUserAgent()`
     * (a database `LIKE '{baseTitle}%'` query): fetches this user+agent's
     * conversations via `ObjectService` equality filters, then applies the
     * prefix match in PHP (`str_starts_with()`), since ObjectService's filter
     * config is equality-based, not a LIKE query. Functionally equivalent for
     * the bounded per-user-per-agent conversation set.
     *
     * @param string $baseTitle Base title to check.
     * @param string $userId    User id.
     * @param string $agentId   Agent UUID.
     *
     * @return string Unique title, with a numeric suffix if needed.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ported suffix-probing loop kept
     * structurally intact from the OR original for parity reviewability.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function ensureUniqueTitle(string $baseTitle, string $userId, string $agentId): string
    {
        $this->logger->info(
            message: '[ConversationManagementHandler] Ensuring unique title',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
                'baseTitle' => $baseTitle,
                'userId'    => $userId,
                'agentId'   => $agentId,
            ]
        );

        $conversations = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::CONVERSATION_SCHEMA)
            ->findAll(
                config: [
                    'filters' => [
                        'userId'  => $userId,
                        'agentId' => $agentId,
                    ],
                    'limit'   => 500,
                ]
            );

        $existingTitles = [];
        foreach ($conversations as $conversation) {
            if (($conversation instanceof ObjectEntity) === false) {
                continue;
            }

            $title = $conversation->getObject()['title'] ?? null;
            if (is_string($title) === true && str_starts_with($title, $baseTitle) === true) {
                $existingTitles[] = $title;
            }
        }

        if (empty($existingTitles) === true) {
            return $baseTitle;
        }

        if (in_array($baseTitle, $existingTitles, true) === false) {
            return $baseTitle;
        }

        $maxNumber        = 1;
        $baseTitleEscaped = preg_quote($baseTitle, '/');

        foreach ($existingTitles as $title) {
            if (preg_match('/^'.$baseTitleEscaped.' \((\d+)\)$/', $title, $matches) === 1) {
                $number = (int) $matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }

        $uniqueTitle = $baseTitle.' ('.($maxNumber + 1).')';

        $this->logger->info(
            message: '[ConversationManagementHandler] Generated unique title',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'baseTitle'   => $baseTitle,
                'uniqueTitle' => $uniqueTitle,
                'foundTitles' => count($existingTitles),
            ]
        );

        return $uniqueTitle;

    }//end ensureUniqueTitle()

    /**
     * Check whether a conversation needs summarisation and, if so, create one.
     *
     * Triggers when `metadata.token_count` exceeds `MAX_TOKENS_BEFORE_SUMMARY`
     * and no summary has run in the last hour. Never throws — failures are
     * logged and the conversation is left unchanged.
     *
     * @param ObjectEntity $conversation The Conversation object.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function checkAndSummarize(ObjectEntity $conversation): void
    {
        $data     = $conversation->getObject();
        $metadata = $data['metadata'] ?? [];
        if (is_array($metadata) === false) {
            $metadata = [];
        }

        $tokenCount = $metadata['token_count'] ?? 0;
        if ($tokenCount < self::MAX_TOKENS_BEFORE_SUMMARY) {
            return;
        }

        $lastSummary = $metadata['last_summary_at'] ?? null;
        if ($lastSummary !== null) {
            try {
                $lastSummaryTime   = new DateTime($lastSummary);
                $hoursSinceSummary = (time() - $lastSummaryTime->getTimestamp()) / 3600;
                if ($hoursSinceSummary < 1) {
                    return;
                }
            } catch (Exception) {
                // Malformed timestamp — fall through and re-summarise.
                unset($lastSummary);
            }
        }

        $conversationUuid = (string) $conversation->getUuid();

        $this->logger->info(
            message: '[ConversationManagementHandler] Triggering conversation summarisation',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
                'conversationId' => $conversationUuid,
                'tokenCount'     => $tokenCount,
            ]
        );

        try {
            $allMessages         = $this->fetchAllMessages(conversationId: $conversationUuid);
            $messagesToSummarize = array_slice($allMessages, 0, -self::RECENT_MESSAGES_COUNT);

            if (empty($messagesToSummarize) === true) {
                return;
            }

            // Tenant-model-policy: the Conversation object already carries its
            // organisation (ObjectEntity metadata) — no new lookup needed.
            $organisation = (string) ($conversation->getOrganisation() ?? '');
            $summary      = $this->generateSummary(messages: $messagesToSummarize, organisation: $organisation);

            $metadata['summary']         = $summary;
            $metadata['last_summary_at'] = (new DateTime())->format('c');
            $metadata['summarized_messages'] = count($messagesToSummarize);
            $data['metadata'] = $metadata;

            $this->objectService->saveObject(
                object: $this->sanitizeForSave(data: $data),
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA,
                uuid: $conversationUuid
            );

            $this->logger->info(
                message: '[ConversationManagementHandler] Conversation summarised',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'conversationId' => $conversationUuid,
                    'summaryLength'  => strlen($summary),
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[ConversationManagementHandler] Failed to summarise conversation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

    }//end checkAndSummarize()

    /**
     * Fetch every Message for a conversation, oldest-first, up to
     * `MAX_MESSAGES_FOR_SUMMARY`.
     *
     * @param string $conversationId Conversation UUID.
     *
     * @return array<int, ObjectEntity> The conversation's Message objects, ascending.
     */
    private function fetchAllMessages(string $conversationId): array
    {
        $messages = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema('message')
            ->findAll(
                config: [
                    'filters' => ['conversationId' => $conversationId],
                    'sort'    => ['created' => 'ASC'],
                    'limit'   => self::MAX_MESSAGES_FOR_SUMMARY,
                ]
            );

        return array_values(array_filter($messages, static fn ($object): bool => $object instanceof ObjectEntity));

    }//end fetchAllMessages()

    /**
     * Generate a concise summary of a set of Message objects via the
     * configured LLM.
     *
     * @param array<int, ObjectEntity> $messages     Messages to summarise, oldest-first.
     * @param string|null              $organisation The conversation's organisation
     *                                               (tenant-model-policy); null skips
     *                                               policy enforcement.
     *
     * @return string Summary text.
     *
     * @throws \OCA\Hermiq\Service\Llm\ProviderUnavailableException When no chat
     *         provider is configured or reachable.
     * @throws \OCA\Hermiq\Service\Llm\ModelPolicyViolationException When `$organisation`
     *         is given and the resolved pair is outside its effective policy.
     */
    private function generateSummary(array $messages, ?string $organisation=null): string
    {
        $conversationText = '';
        foreach ($messages as $message) {
            $data = $message->getObject();
            $role = 'Assistant';
            if (($data['role'] ?? null) === 'user') {
                $role = 'User';
            }

            $conversationText .= "{$role}: ".($data['content'] ?? '')."\n\n";
        }

        $prompt  = 'Summarize the following conversation concisely. ';
        $prompt .= "Focus on key topics, decisions, and information discussed:\n\n";
        $prompt .= $conversationText;
        $prompt .= "\n\nSummary:";

        return $this->generateTextViaConfiguredLlm(prompt: $prompt, organisation: $organisation);

    }//end generateSummary()

    /**
     * Generate free text against whichever chat provider `hermiq.llm` currently
     * selects. Shared by title generation and summarisation.
     *
     * @param string      $prompt       The prompt text.
     * @param string|null $organisation The calling organisation (tenant-model-policy);
     *                                  null skips policy enforcement (background
     *                                  callers with no organisation context).
     *
     * @return string Generated text.
     *
     * @throws \OCA\Hermiq\Service\Llm\ProviderUnavailableException When no chat
     *         provider is configured or reachable.
     * @throws \OCA\Hermiq\Service\Llm\ModelPolicyViolationException When `$organisation`
     *         is given and the resolved pair is outside its effective policy.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) LLPhant's Message::user() factory is the
     * library's public API — there is no injectable seam.
     *
     * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
     */
    private function generateTextViaConfiguredLlm(string $prompt, ?string $organisation=null): string
    {
        $llmConfig = $this->providerFactory->getLlmConfig();
        $driver    = $this->providerFactory->createChatDriver(llmConfig: $llmConfig, organisation: $organisation);

        if ($driver->provider === 'fireworks') {
            return $this->providerFactory->callFireworksChat(
                credentialId: (string) $driver->credentialId,
                model: $driver->model,
                baseUrl: (string) $driver->baseUrl,
                messageHistory: [LLPhantMessage::user($prompt)]
            );
        }

        if ($driver->provider === 'anthropic') {
            return $this->providerFactory->callAnthropicChat(
                credentialId: (string) $driver->credentialId,
                model: $driver->model,
                baseUrl: (string) $driver->baseUrl,
                messageHistory: [LLPhantMessage::user($prompt)],
                authMode: (string) $driver->authMode,
                executionMode: $driver->executionMode
            );
        }

        if ($driver->provider === 'nextcloud') {
            return $this->providerFactory->generateViaNextcloud(prompt: $prompt);
        }

        // OpenAI / Ollama: driver->chat is a ready LLPhant chat instance.
        return $driver->chat->generateText($prompt);

    }//end generateTextViaConfiguredLlm()

    /**
     * Generate a fallback title by truncating the message to ~60 characters,
     * breaking on a word boundary when possible.
     *
     * @param string $message Message text.
     *
     * @return string Fallback title.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    private function generateFallbackTitle(string $message): string
    {
        $title = substr($message, 0, 60);

        if (strlen($message) > 60) {
            $lastSpace = strrpos($title, ' ');
            if ($lastSpace !== false && $lastSpace > 30) {
                $title = substr($title, 0, $lastSpace);
            }

            $title .= '...';
        }

        return $title;

    }//end generateFallbackTitle()
}//end class
