<?php

/**
 * Test stub for OpenRegister ChatService.
 *
 * Stands in for OCA\OpenRegister\Service\ChatService when OpenRegister is not
 * installed (standalone CI). Exposes only processMessage, the agent-runtime entry
 * point ScheduleService invokes. The real service ships with OpenRegister.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal ChatService stub for standalone unit runs.
 */
class ChatService
{

    /**
     * Process a chat message against a conversation's bound agent.
     *
     * @param int    $conversationId The conversation id.
     * @param string $userId         The acting user id.
     * @param string $userMessage    The prompt/message.
     * @param array  $selectedViews  Optional view filters.
     * @param array  $selectedTools  Optional tool UUIDs.
     * @param array  $ragSettings    Optional RAG overrides.
     * @param array  $context        Optional CnAiContext snapshot.
     *
     * @return array<string,mixed>
     */
    public function processMessage(
        int $conversationId,
        string $userId,
        string $userMessage,
        array $selectedViews=[],
        array $selectedTools=[],
        array $ragSettings=[],
        array $context=[]
    ): array {
        return ['message' => ''];
    }//end processMessage()
}//end class
