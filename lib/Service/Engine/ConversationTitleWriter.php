<?php

/**
 * Hermiq ConversationTitleWriter.
 *
 * Generates a conversation's title from its first user message and persists it.
 * Extracted from `Engine::maybeGenerateTitle()` so the work can run off the
 * reply's critical path (`ConversationTitleJob`) while staying directly testable
 * without a job runner (session-context-performance).
 *
 * Behaviour is deliberately identical to the synchronous version it replaces —
 * same "should this be titled?" test, same organisation-scoped generation, same
 * uniqueness pass. Only *when* it runs changes.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
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
 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names a conversation from its first user message.
 *
 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
 */
class ConversationTitleWriter
{
    use SanitizesForSaveTrait;

    /**
     * OpenRegister register slug holding hermiq's engine objects.
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
     * Constructor.
     *
     * @param ObjectService                 $objectService       Reads the conversation, writes the title.
     * @param ConversationManagementHandler $conversationHandler Generates + de-duplicates the title.
     * @param LoggerInterface               $logger              Logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ConversationManagementHandler $conversationHandler,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate and persist a conversation's title.
     *
     * Re-reads the conversation rather than trusting a payload snapshot: this runs
     * after the reply, so the object may have moved on, and `IJobList` arguments are
     * a JSON round-trip. The re-read also makes the "already titled" test authoritative
     * at write time, so a retried or duplicated job cannot rename a conversation the
     * user has since titled themselves.
     *
     * Never throws: a missing title is cosmetic, and this runs detached from the reply
     * that already succeeded. Failing loudly here would turn a naming hiccup into a
     * red job with nothing to retry usefully.
     *
     * @param string $conversationId The conversation UUID.
     * @param string $userMessage    The first user message to name it from.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
     */
    public function write(string $conversationId, string $userMessage): void
    {
        try {
            $conversation = $this->objectService->find(
                id: $conversationId,
                register: self::REGISTER_SLUG,
                schema: self::CONVERSATION_SCHEMA
            );
        } catch (Throwable $e) {
            $this->logger->info(
                message: '[ConversationTitleWriter] Conversation gone before it could be titled — nothing to do',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'conversationId' => $conversationId,
                ]
            );
            return;
        }

        if ($conversation === null) {
            return;
        }

        $conversationData = $conversation->getObject();
        if ($this->needsTitle(conversationData: $conversationData) === false) {
            return;
        }

        // Tenant-model-policy: the conversation carries its own organisation, so the
        // effective policy is enforced on this call exactly as on the synchronous path
        // it replaces. Deferring the work must not become a way around governance.
        $organisation = (string) ($conversation->getOrganisation() ?? '');

        try {
            $title = $this->conversationHandler->generateConversationTitle(
                firstMessage: $userMessage,
                organisation: $organisation
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[ConversationTitleWriter] Title generation failed — the conversation keeps its '
                    .'placeholder, which is a pending title and not a failure state',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'conversationId' => $conversationId,
                    'error'          => $e->getMessage(),
                ]
            );
            return;
        }

        $agentId = ($conversationData['agentId'] ?? null);
        if (is_string($agentId) === true && $agentId !== '') {
            $title = $this->conversationHandler->ensureUniqueTitle(
                baseTitle: $title,
                userId: (string) ($conversationData['userId'] ?? ''),
                agentId: $agentId
            );
        }

        // `saveObject()` is PUT-semantic: any schema property omitted here is written
        // back as null. So the WHOLE object is carried forward and only `title` is
        // changed — never a `['title' => …]` patch, which would silently blank
        // `userId`, `agentId` and every other field on the object.
        $conversationData['title'] = $title;

        $this->objectService->saveObject(
            object: $this->sanitizeForSave(data: $conversationData),
            register: self::REGISTER_SLUG,
            schema: self::CONVERSATION_SCHEMA,
            uuid: $conversationId
        );

    }//end write()

    /**
     * Whether a conversation still wants a generated title.
     *
     * Matches the placeholder the create path writes; a user-set title is never
     * overwritten.
     *
     * @param array<string, mixed> $conversationData The conversation's object data.
     *
     * @return bool True when a title should be generated.
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
     */
    private function needsTitle(array $conversationData): bool
    {
        $currentTitle = ($conversationData['title'] ?? null);
        if ($currentTitle === null || $currentTitle === '') {
            return true;
        }

        // Case-insensitive: the create path writes "New conversation" while the
        // pre-existing check matched "New Conversation". Matching only one casing
        // would leave the other permanently unnamed.
        return (stripos((string) $currentTitle, 'New conversation') === 0);

    }//end needsTitle()
}//end class
