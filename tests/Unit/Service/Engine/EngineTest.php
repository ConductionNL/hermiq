<?php

/**
 * Unit tests for the Engine facade (agent-engine-port).
 *
 * Covers the top-level orchestration with all handlers mocked: delegation order
 * (store user turn → summarise check → RAG → history → LLM → store assistant
 * turn), access control on the conversation owner, the messageId surfaced from
 * the persisted assistant Message, first-exchange title generation, and — load
 * bearing for ScheduleService/run-analytics — the `usage` key surviving in the
 * return shape.
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

use Exception;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the Engine chat facade.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class EngineTest extends TestCase
{

    /**
     * Build an ObjectEntity with a UUID and payload.
     *
     * @param string               $uuid    The object UUID.
     * @param array<string, mixed> $payload The object data.
     *
     * @return ObjectEntity
     */
    private function entity(string $uuid, array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;

    }//end entity()

    /**
     * Wire an Engine over fully mocked collaborators.
     *
     * @param ObjectService|MockObject                 $objectService       Object service mock.
     * @param ContextRetrievalHandler|MockObject       $contextHandler      Context handler mock.
     * @param ResponseGenerationHandler|MockObject     $responseHandler     Response handler mock.
     * @param ConversationManagementHandler|MockObject $conversationHandler Conversation handler mock.
     * @param MessageHistoryHandler|MockObject         $historyHandler      History handler mock.
     *
     * @return Engine
     */
    private function engine(
        ObjectService|MockObject $objectService,
        ContextRetrievalHandler|MockObject $contextHandler,
        ResponseGenerationHandler|MockObject $responseHandler,
        ConversationManagementHandler|MockObject $conversationHandler,
        MessageHistoryHandler|MockObject $historyHandler
    ): Engine {
        return new Engine(
            $objectService,
            $contextHandler,
            $responseHandler,
            $conversationHandler,
            $historyHandler,
            new NullLogger()
        );

    }//end engine()

    /**
     * Happy path: handlers are delegated to in order and the return shape
     * carries message, messageId (assistant Message uuid), sources, timings,
     * and — load-bearing — the usage key from the response handler.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testProcessMessageDelegatesAndPreservesUsage(): void
    {
        $conversation = $this->entity(
            'conv-1',
            [
                'userId'  => 'alice',
                'agentId' => 'agent-1',
                'title'   => 'Existing title',
            ]
        );
        $agent        = $this->entity('agent-1', ['name' => 'Helper', 'tools' => []]);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('find')->willReturnCallback(
            function (int|string $id) use ($conversation, $agent): ?ObjectEntity {
                if ($id === 'conv-1') {
                    return $conversation;
                }

                if ($id === 'agent-1') {
                    return $agent;
                }

                return null;
            }
        );
        // Title probe: 3+ messages → no title generation (title also non-new).
        $objectService->method('findAll')->willReturn([1, 2, 3]);
        $objectService->expects($this->never())->method('saveObject');

        $contextHandler = $this->createMock(ContextRetrievalHandler::class);
        $ragContext     = [
            'text'    => 'CONTEXT',
            'sources' => [
                [
                    'id'   => 'src-1',
                    'type' => 'object',
                    'name' => 'Doc',
                ],
            ],
        ];
        $contextHandler->expects($this->once())
            ->method('retrieveContext')
            ->with('Hello?', $agent, ['view-1'], ['includeFiles' => false])
            ->willReturn($ragContext);

        $responseHandler = $this->createMock(ResponseGenerationHandler::class);
        $responseHandler->expects($this->once())
            ->method('generateResponse')
            ->willReturnCallback(
                function (
                    string $userMessage,
                    array $context,
                    array $messageHistory,
                    ?ObjectEntity $agentArg,
                    array $selectedTools,
                    $channel,
                    array $cnAiContext
                ) use ($agent, $ragContext): string {
                    $this->assertSame('Hello?', $userMessage);
                    $this->assertSame($ragContext, $context);
                    $this->assertSame(['history'], $messageHistory);
                    $this->assertSame($agent, $agentArg);
                    $this->assertSame(['a.one'], $selectedTools);
                    $this->assertNull($channel);
                    // The CnAiContext snapshot must reach the LLM, not be
                    // overwritten by the RAG context reuse of `$context`.
                    $this->assertSame(['app' => 'decidesk'], $cnAiContext);
                    return 'Hi there!';
                }
            );
        // Simulate the provider filling per-run usage (public property).
        $responseHandler->lastUsage = [
            'promptTokens'     => 120,
            'completionTokens' => 45,
            'totalDurationMs'  => 900,
            'llmSeconds'       => 0.9,
        ];

        $conversationHandler = $this->createMock(ConversationManagementHandler::class);
        $conversationHandler->expects($this->once())
            ->method('checkAndSummarize')
            ->with($conversation);
        $conversationHandler->expects($this->never())->method('generateConversationTitle');

        $historyHandler = $this->createMock(MessageHistoryHandler::class);
        $storedRoles    = [];
        $historyHandler->method('storeMessage')->willReturnCallback(
            function (
                string $conversationId,
                string $role,
                string $content,
                ?array $sources=null,
                ?array $context=null
            ) use (&$storedRoles): ObjectEntity {
                $storedRoles[] = [
                    'role'    => $role,
                    'sources' => $sources,
                    'context' => $context,
                ];
                return $this->entity('msg-'.$role, ['role' => $role, 'content' => $content]);
            }
        );
        $historyHandler->method('buildMessageHistory')->willReturn(['history']);

        $engine = $this->engine(
            $objectService,
            $contextHandler,
            $responseHandler,
            $conversationHandler,
            $historyHandler
        );

        $result = $engine->processMessage(
            conversationId: 'conv-1',
            userId: 'alice',
            userMessage: 'Hello?',
            selectedViews: ['view-1'],
            selectedTools: ['a.one'],
            ragSettings: ['includeFiles' => false],
            context: ['app' => 'decidesk']
        );

        // Return shape: all five keys, usage passed through verbatim.
        $this->assertSame('Hi there!', $result['message']);
        $this->assertSame('msg-assistant', $result['messageId']);
        $this->assertSame($ragContext['sources'], $result['sources']);
        $this->assertArrayHasKey('context', $result['timings']);
        $this->assertArrayHasKey('total', $result['timings']);
        $this->assertSame($responseHandler->lastUsage, $result['usage']);

        // Both turns persisted: user turn with the CnAiContext snapshot,
        // assistant turn with the RAG sources.
        $this->assertCount(2, $storedRoles);
        $this->assertSame('user', $storedRoles[0]['role']);
        $this->assertSame(['app' => 'decidesk'], $storedRoles[0]['context']);
        $this->assertSame('assistant', $storedRoles[1]['role']);
        $this->assertSame($ragContext['sources'], $storedRoles[1]['sources']);

    }//end testProcessMessageDelegatesAndPreservesUsage()

    /**
     * A user who does not own the conversation is denied.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testProcessMessageDeniesForeignConversation(): void
    {
        $conversation  = $this->entity('conv-1', ['userId' => 'alice']);
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($conversation);

        $engine = $this->engine(
            $objectService,
            $this->createMock(ContextRetrievalHandler::class),
            $this->createMock(ResponseGenerationHandler::class),
            $this->createMock(ConversationManagementHandler::class),
            $this->createMock(MessageHistoryHandler::class)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Access denied to conversation');
        $engine->processMessage(conversationId: 'conv-1', userId: 'mallory', userMessage: 'Hi');

    }//end testProcessMessageDeniesForeignConversation()

    /**
     * An unknown conversation id fails clearly.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testProcessMessageFailsOnUnknownConversation(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn(null);

        $engine = $this->engine(
            $objectService,
            $this->createMock(ContextRetrievalHandler::class),
            $this->createMock(ResponseGenerationHandler::class),
            $this->createMock(ConversationManagementHandler::class),
            $this->createMock(MessageHistoryHandler::class)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Conversation not found');
        $engine->processMessage(conversationId: 'nope', userId: 'alice', userMessage: 'Hi');

    }//end testProcessMessageFailsOnUnknownConversation()

    /**
     * First exchange on an untitled conversation: a title is generated, made
     * unique for the user+agent pair, and persisted on the Conversation object.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testProcessMessageGeneratesTitleOnFirstExchange(): void
    {
        $conversation = $this->entity(
            'conv-1',
            [
                'userId'  => 'alice',
                'agentId' => 'agent-1',
            ]
        );
        $agent        = $this->entity('agent-1', ['name' => 'Helper']);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('setRegister')->willReturnSelf();
        $objectService->method('setSchema')->willReturnSelf();
        $objectService->method('find')->willReturnCallback(
            static function (int|string $id) use ($conversation, $agent): ?ObjectEntity {
                if ($id === 'conv-1') {
                    return $conversation;
                }

                return $agent;
            }
        );
        // Title probe: 2 messages → first exchange.
        $objectService->method('findAll')->willReturn([1, 2]);

        $savedPayload = null;
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null) use (&$savedPayload): ObjectEntity {
                    $savedPayload = ['object' => $object, 'uuid' => $uuid];
                    return new ObjectEntity();
                }
            );

        $contextHandler = $this->createMock(ContextRetrievalHandler::class);
        $contextHandler->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);

        $responseHandler = $this->createMock(ResponseGenerationHandler::class);
        $responseHandler->method('generateResponse')->willReturn('Answer');

        $conversationHandler = $this->createMock(ConversationManagementHandler::class);
        $conversationHandler->expects($this->once())
            ->method('generateConversationTitle')
            ->with('What is our leave policy?')
            ->willReturn('Leave policy');
        $conversationHandler->expects($this->once())
            ->method('ensureUniqueTitle')
            ->with('Leave policy', 'alice', 'agent-1')
            ->willReturn('Leave policy (2)');

        $historyHandler = $this->createMock(MessageHistoryHandler::class);
        $historyHandler->method('storeMessage')->willReturn(new ObjectEntity());
        $historyHandler->method('buildMessageHistory')->willReturn([]);

        $engine = $this->engine(
            $objectService,
            $contextHandler,
            $responseHandler,
            $conversationHandler,
            $historyHandler
        );

        $engine->processMessage(conversationId: 'conv-1', userId: 'alice', userMessage: 'What is our leave policy?');

        $this->assertNotNull($savedPayload);
        $this->assertSame('Leave policy (2)', $savedPayload['object']['title']);
        $this->assertSame('conv-1', $savedPayload['uuid']);

    }//end testProcessMessageGeneratesTitleOnFirstExchange()

    /**
     * The title/uniqueness delegation helpers forward to the conversation handler.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
     */
    public function testTitleHelpersDelegate(): void
    {
        $conversationHandler = $this->createMock(ConversationManagementHandler::class);
        $conversationHandler->method('generateConversationTitle')->willReturn('A title');
        $conversationHandler->method('ensureUniqueTitle')->willReturn('A title (3)');

        $engine = $this->engine(
            $this->createMock(ObjectService::class),
            $this->createMock(ContextRetrievalHandler::class),
            $this->createMock(ResponseGenerationHandler::class),
            $conversationHandler,
            $this->createMock(MessageHistoryHandler::class)
        );

        $this->assertSame('A title', $engine->generateConversationTitle(firstMessage: 'hello'));
        $this->assertSame('A title (3)', $engine->ensureUniqueTitle(baseTitle: 'A title', userId: 'alice', agentId: 'agent-1'));

    }//end testTitleHelpersDelegate()
}//end class
