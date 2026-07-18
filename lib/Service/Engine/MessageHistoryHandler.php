<?php

/**
 * Hermiq Chat Message History Handler.
 *
 * Handler for message storage and conversation history building. Ported from
 * `OCA\OpenRegister\Service\Chat\MessageHistoryHandler`, re-pointed at the
 * `Message` OR object (agent-engine-schemas) via `ObjectService` instead of
 * OR's `MessageMapper`/`ConversationMapper` QBMapper entities.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use LLPhant\Chat\Message as LLPhantMessage;
use Psr\Log\LoggerInterface;

/**
 * Handles message storage and conversation history building against the
 * hermiq-register `Message` object.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class MessageHistoryHandler
{
    use SanitizesForSaveTrait;

    /**
     * OpenRegister register slug that holds Hermiq agent-engine objects.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'hermiq';

    /**
     * Schema slug for chat message objects.
     *
     * @var string
     */
    private const MESSAGE_SCHEMA = 'message';

    /**
     * Number of recent messages to keep in context.
     *
     * @var int
     */
    private const RECENT_MESSAGES_COUNT = 10;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object read/write.
     * @param LoggerInterface $logger        Logger.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Build message history array for the LLM.
     *
     * Converts the most recent Message objects for the conversation into
     * LLPhant `Message` instances, oldest-first.
     *
     * @param string $conversationId Conversation UUID.
     *
     * @return array<int, LLPhantMessage> Array of LLPhant Message objects.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) LLPhant's Message role factories
     * (Message::user()/assistant()/system()) are the library's public API —
     * there is no injectable seam.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function buildMessageHistory(string $conversationId): array
    {
        $recent = $this->objectService
            ->setRegister(self::REGISTER_SLUG)
            ->setSchema(self::MESSAGE_SCHEMA)
            ->findAll(
                config: [
                    'filters' => ['conversationId' => $conversationId],
                    // `@self.created`, NOT `created`. OpenRegister reads a bare key as an OBJECT
                    // PROPERTY; `Message` has no `created` property (the timestamp is metadata),
                    // so this sort silently did NOTHING — and this is the one place where that
                    // was not cosmetic. The intent is "newest N, then reverse to chronological".
                    // Unsorted, findAll returned the OLDEST N in natural order and the
                    // array_reverse below then handed the LLM the conversation's OPENING
                    // messages, backwards. Any conversation longer than RECENT_MESSAGES_COUNT
                    // was answered without a single recent turn in context.
                    // Verified live: `_order[created]=DESC` returns the oldest row first;
                    // `_order[@self.created]=DESC` returns the newest.
                    'sort'    => ['@self.created' => 'DESC'],
                    'limit'   => self::RECENT_MESSAGES_COUNT,
                ]
            );

        // The findAll() fetch is most-recent-first; the LLM needs chronological order.
        $messages = array_reverse(array_filter($recent, static fn ($object): bool => $object instanceof ObjectEntity));

        $this->logger->debug(
            message: '[MessageHistoryHandler] Building message history',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
                'conversationId' => $conversationId,
                'messageCount'   => count($messages),
            ]
        );

        $history = [];
        foreach ($messages as $message) {
            $data    = $message->getObject();
            $role    = $data['role'] ?? null;
            $content = $data['content'] ?? null;

            if (empty($role) === true || empty($content) === true) {
                $this->logger->warning(
                    message: '[MessageHistoryHandler] Skipping message with missing role or content',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'hasRole'    => empty($role) === false,
                        'hasContent' => empty($content) === false,
                    ]
                );
                continue;
            }

            if (in_array($role, ['user', 'assistant', 'system'], true) === false) {
                $this->logger->warning(
                    message: '[MessageHistoryHandler] Unknown message role',
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                        'role' => $role,
                    ]
                );
                continue;
            }

            $history[] = match ($role) {
                'user'      => LLPhantMessage::user($content),
                'assistant' => LLPhantMessage::assistant($content),
                'system'    => LLPhantMessage::system($content),
            };
        }//end foreach

        $this->logger->info(
            message: '[MessageHistoryHandler] Message history built',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'historyCount' => count($history),
            ]
        );

        return $history;

    }//end buildMessageHistory()

    /**
     * Store a message as a `Message` OR object.
     *
     * @param string     $conversationId Conversation UUID.
     * @param string     $role           Message role (system|user|assistant|tool).
     * @param string     $content        Message content.
     * @param array|null $sources        Optional RAG sources.
     * @param array|null $context        Optional AI Chat Companion context snapshot.
     *
     * @return ObjectEntity The persisted Message object.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     */
    public function storeMessage(
        string $conversationId,
        string $role,
        string $content,
        ?array $sources=null,
        ?array $context=null
    ): ObjectEntity {
        $payload = [
            'conversationId' => $conversationId,
            'role'           => $role,
            'content'        => $content,
        ];

        if ($sources !== null && empty($sources) === false) {
            $payload['sources'] = $sources;
        }

        if ($context !== null && empty($context) === false) {
            $payload['context'] = $context;
        }

        $stored = $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $payload),
            register: self::REGISTER_SLUG,
            schema: self::MESSAGE_SCHEMA
        );

        $this->logger->debug(
            message: '[MessageHistoryHandler] Message stored',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
                'messageId'      => $stored->getUuid(),
                'conversationId' => $conversationId,
                'role'           => $role,
                'hasSources'     => $sources !== null && empty($sources) === false,
            ]
        );

        return $stored;

    }//end storeMessage()
}//end class
