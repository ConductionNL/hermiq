<?php

/**
 * Unit tests for MessageHistoryHandler (agent-engine-port).
 *
 * Covers Message persistence through ObjectService (sources/context only attached
 * when non-empty) and history building (chronological re-ordering of the
 * most-recent-first fetch, LLPhant role mapping, skipping incomplete/unknown turns).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use LLPhant\Chat\Enums\ChatRole;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the message storage/history handler.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class MessageHistoryHandlerTest extends TestCase
{

    /**
     * A Message ObjectEntity.
     *
     * @param string $role    The role.
     * @param string $content The content.
     *
     * @return ObjectEntity
     */
    private function message(string $role, string $content): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('msg-'.$role.'-'.substr(md5($content), 0, 6));
        $entity->setObject(
            [
                'conversationId' => 'conv-1',
                'role'           => $role,
                'content'        => $content,
            ]
        );
        return $entity;

    }//end message()

    /**
     * storeMessage persists the message payload through ObjectService, attaching
     * sources/context ONLY when non-empty.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testStoreMessageAttachesOptionalFieldsOnlyWhenPresent(): void
    {
        $saved         = [];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null) use (&$saved): ObjectEntity {
                $saved[] = [
                    'object'   => $object,
                    'register' => $register,
                    'schema'   => $schema,
                ];
                $entity = new ObjectEntity();
                $entity->setUuid('msg-1');
                $entity->setObject($object);
                return $entity;
            }
        );

        $handler = new MessageHistoryHandler($objectService, new NullLogger());

        // A user turn with a context snapshot but no sources.
        $handler->storeMessage(
            conversationId: 'conv-1',
            role: 'user',
            content: 'Hello',
            sources: null,
            context: ['app' => 'decidesk']
        );

        // An assistant turn with sources but empty context.
        $stored = $handler->storeMessage(
            conversationId: 'conv-1',
            role: 'assistant',
            content: 'Hi!',
            sources: [['id' => 'src-1', 'type' => 'object', 'name' => 'Doc']]
        );

        $this->assertSame('msg-1', $stored->getUuid());
        $this->assertCount(2, $saved);

        $userPayload = $saved[0]['object'];
        $this->assertSame('user', $userPayload['role']);
        $this->assertSame(['app' => 'decidesk'], $userPayload['context']);
        $this->assertArrayNotHasKey('sources', $userPayload);
        $this->assertSame('hermiq', $saved[0]['register']);
        $this->assertSame('message', $saved[0]['schema']);

        $assistantPayload = $saved[1]['object'];
        $this->assertSame('assistant', $assistantPayload['role']);
        $this->assertCount(1, $assistantPayload['sources']);
        $this->assertArrayNotHasKey('context', $assistantPayload);

    }//end testStoreMessageAttachesOptionalFieldsOnlyWhenPresent()

    /**
     * storeMessage() persists a non-empty `attachments` value onto the Message
     * payload; omitting it (every pre-existing caller) leaves the key absent —
     * byte-for-byte unchanged behaviour for callers that do not pass it
     * (hermiq-chat-attachments Task 4).
     *
     * @return void
     *
     * @spec openspec/changes/hermiq-chat-attachments/specs/chat-attachments/spec.md#requirement-the-user-message-persists-its-attachment-references
     */
    public function testStoreMessagePersistsAttachmentsOnlyWhenPresent(): void
    {
        $saved         = [];
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$saved): ObjectEntity {
                $saved[] = $object;
                $entity  = new ObjectEntity();
                $entity->setUuid('msg-1');
                $entity->setObject($object);
                return $entity;
            }
        );

        $handler = new MessageHistoryHandler($objectService, new NullLogger());

        $attachments = [['path' => 'Hermiq/Attachments/report.txt', 'name' => 'report.txt']];
        $handler->storeMessage(
            conversationId: 'conv-1',
            role: 'user',
            content: 'What does the report say?',
            attachments: $attachments
        );

        // A second turn with no attachments must NOT carry the key at all.
        $handler->storeMessage(
            conversationId: 'conv-1',
            role: 'user',
            content: 'Follow-up question'
        );

        $this->assertCount(2, $saved);
        $this->assertSame($attachments, $saved[0]['attachments']);
        $this->assertArrayNotHasKey('attachments', $saved[1]);

    }//end testStoreMessagePersistsAttachmentsOnlyWhenPresent()

    /**
     * buildMessageHistory re-orders the most-recent-first fetch chronologically,
     * maps roles onto LLPhant factories, and skips incomplete or unknown turns.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testBuildMessageHistoryOrdersAndFiltersTurns(): void
    {
        // findAll returns most-recent-first (sort created DESC).
        $fetched = [
            $this->message('assistant', 'Second answer'),
            $this->message('user', 'Second question'),
            $this->message('tool', 'raw tool output'),
            $this->message('assistant', ''),
            $this->message('user', 'First question'),
        ];

        $capturedConfig = null;
        $objectService  = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('findAll')->willReturnCallback(
            function (array $config) use (&$capturedConfig, $fetched): array {
                $capturedConfig = $config;
                return $fetched;
            }
        );

        $handler = new MessageHistoryHandler($objectService, new NullLogger());
        $history = $handler->buildMessageHistory(conversationId: 'conv-1');

        // The fetch is filtered + capped + newest-first.
        $this->assertSame('conv-1', $capturedConfig['filters']['conversationId']);
        $this->assertSame(['created' => 'DESC'], $capturedConfig['sort']);
        $this->assertSame(10, $capturedConfig['limit']);

        // tool role (unknown to LLPhant mapping) and the empty-content turn are
        // skipped; the rest is chronological (oldest first).
        $this->assertCount(3, $history);
        $this->assertSame(ChatRole::User, $history[0]->role);
        $this->assertSame('First question', $history[0]->content);
        $this->assertSame(ChatRole::User, $history[1]->role);
        $this->assertSame('Second question', $history[1]->content);
        $this->assertSame(ChatRole::Assistant, $history[2]->role);
        $this->assertSame('Second answer', $history[2]->content);

    }//end testBuildMessageHistoryOrdersAndFiltersTurns()
}//end class
