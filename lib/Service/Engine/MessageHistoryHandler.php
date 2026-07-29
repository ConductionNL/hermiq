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
                    'sort'    => ['created' => 'DESC'],
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

        // A conversation is "shared" when its human turns come from more than
        // one author. Only then is speaker labelling added — a single-speaker
        // session keeps byte-identical prompts to before talk-shared-sessions.
        $isMultiSpeaker = $this->hasMultipleHumanAuthors(messages: $messages);

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

            if ($role === 'user' && $isMultiSpeaker === true) {
                $content = $this->labelWithAuthor(content: $content, data: $data);
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
     * Attach authorship to a message payload, for human turns only.
     *
     * Authorship is a property of HUMAN turns. The schema cannot bind these
     * fields to `role` — the OpenRegister importer rejects conditional blocks —
     * so the constraint is upheld here, at the single writer.
     *
     * @param array       $payload           The message payload being built.
     * @param string      $role              The message role.
     * @param string|null $authorId          The human author's uid, if any.
     * @param string|null $authorDisplayName The author's display name at send time.
     *
     * @return array The payload, with authorship attached where applicable.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-each-human-turn-records-its-author
     */
    private function withAuthorship(array $payload, string $role, ?string $authorId, ?string $authorDisplayName): array
    {
        if ($role !== 'user') {
            return $payload;
        }

        if ($authorId !== null && $authorId !== '') {
            $payload['authorId'] = $authorId;
        }

        if ($authorDisplayName !== null && $authorDisplayName !== '') {
            $payload['authorDisplayName'] = $authorDisplayName;
        }

        return $payload;

    }//end withAuthorship()

    /**
     * Whether the given messages carry human turns from more than one author.
     *
     * Used to decide if speaker labelling is warranted: in the overwhelmingly
     * common single-speaker session it is not, and the prompt stays exactly as
     * it was before talk-shared-sessions.
     *
     * @param ObjectEntity[] $messages The conversation's recent messages.
     *
     * @return bool True when two or more distinct human authors are present.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-the-model-can-tell-speakers-apart
     */
    private function hasMultipleHumanAuthors(array $messages): bool
    {
        $authors = [];
        foreach ($messages as $message) {
            $data = $message->getObject();
            if (($data['role'] ?? null) !== 'user') {
                continue;
            }

            $authorId = ($data['authorId'] ?? null);
            if (is_string($authorId) === true && $authorId !== '' && in_array($authorId, $authors, true) === false) {
                $authors[] = $authorId;
                if (count($authors) > 1) {
                    return true;
                }
            }
        }

        return false;

    }//end hasMultipleHumanAuthors()

    /**
     * Prefix a human turn with its author so the model can attribute it.
     *
     * Uses the display name CAPTURED AT SEND TIME, never re-resolved, so a
     * transcript reads as it did then (ADR-004). Falls back to the uid when no
     * display name was captured, and leaves the content untouched when neither
     * is present.
     *
     * @param string $content The message content.
     * @param array  $data    The message object payload.
     *
     * @return string The content, attributed where possible.
     *
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-the-model-can-tell-speakers-apart
     */
    private function labelWithAuthor(string $content, array $data): string
    {
        $label = ($data['authorDisplayName'] ?? null);
        if (is_string($label) === false || $label === '') {
            $label = ($data['authorId'] ?? null);
        }

        if (is_string($label) === false || $label === '') {
            return $content;
        }

        return $label.': '.$content;

    }//end labelWithAuthor()

    /**
     * Store a message as a `Message` OR object.
     *
     * @param string      $conversationId    Conversation UUID.
     * @param string      $role              Message role (system|user|assistant|tool).
     * @param string      $content           Message content.
     * @param array|null  $sources           Optional RAG sources.
     * @param array|null  $context           Optional AI Chat Companion context snapshot.
     * @param string|null $authorId          Uid of the human who produced this turn — `role=user` only.
     * @param string|null $authorDisplayName That human's display name AT SEND TIME (talk-shared-sessions).
     *
     * @return ObjectEntity The persisted Message object.
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-2
     * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-each-human-turn-records-its-author
     */
    public function storeMessage(
        string $conversationId,
        string $role,
        string $content,
        ?array $sources=null,
        ?array $context=null,
        ?string $authorId=null,
        ?string $authorDisplayName=null
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

        $payload = $this->withAuthorship(
            payload: $payload,
            role: $role,
            authorId: $authorId,
            authorDisplayName: $authorDisplayName
        );

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
