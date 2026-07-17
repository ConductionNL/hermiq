<?php

/**
 * Unit tests for ConversationTitleWriter (session-context-performance).
 *
 * The writer runs detached from the reply, so its risks are different from the
 * synchronous code it replaces: it must not overwrite a title the user has set in
 * the meantime, it must not blank the conversation via PUT-semantic save, it must
 * still enforce the org's model policy, and it must never throw at a reply that has
 * already been delivered.
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
 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\ConversationTitleWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the deferred conversation-title writer.
 *
 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
 */
class ConversationTitleWriterTest extends TestCase
{

    /**
     * A conversation ObjectEntity.
     *
     * @param array<string, mixed> $payload      The conversation object data.
     * @param string               $organisation The organisation the conversation belongs to.
     *
     * @return ObjectEntity
     */
    private function conversation(array $payload, string $organisation=''): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('conv-1');
        $entity->setObject($payload);
        if ($organisation !== '') {
            $entity->setOrganisation($organisation);
        }

        return $entity;

    }//end conversation()

    /**
     * An ObjectService whose find() returns the given conversation.
     *
     * @param ObjectEntity|null $conversation The conversation to return.
     *
     * @return ObjectService&MockObject
     */
    private function objectService(?ObjectEntity $conversation): ObjectService&MockObject
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('find')->willReturn($conversation);
        return $objectService;

    }//end objectService()

    /**
     * The whole conversation object survives the title write.
     *
     * `saveObject()` is PUT-semantic: any schema property left out of the payload is
     * written back as null. A `['title' => …]` patch would therefore silently blank
     * userId/agentId/metadata. Asserts a NON-CHANGED field survives, because that is
     * the field a regression would eat.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
     */
    public function testTitleWriteCarriesTheWholeObjectForward(): void
    {
        $conversation = $this->conversation(
            [
                'title'    => 'New conversation',
                'userId'   => 'alice',
                'agentId'  => 'agent-1',
                'metadata' => ['source' => 'companion'],
            ]
        );

        $saved         = null;
        $objectService = $this->objectService($conversation);
        $objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, mixed $extend=null, mixed $register=null, mixed $schema=null, ?string $uuid=null) use (&$saved): ObjectEntity {
                    $saved = $object;
                    return new ObjectEntity();
                }
            );

        $handler = $this->createMock(ConversationManagementHandler::class);
        $handler->method('generateConversationTitle')->willReturn('Leave policy');
        $handler->method('ensureUniqueTitle')->willReturn('Leave policy');

        $writer = new ConversationTitleWriter($objectService, $handler, new NullLogger());
        $writer->write(conversationId: 'conv-1', userMessage: 'What is our leave policy?');

        $this->assertSame('Leave policy', $saved['title']);
        // The fields nobody asked to change must still be there.
        $this->assertSame('alice', $saved['userId']);
        $this->assertSame('agent-1', $saved['agentId']);
        $this->assertSame(['source' => 'companion'], $saved['metadata']);

    }//end testTitleWriteCarriesTheWholeObjectForward()

    /**
     * The org's model policy is still enforced on the deferred call.
     *
     * `generateConversationTitle()` treats a null organisation as "skip policy
     * enforcement", so dropping it here would turn a latency fix into a governance
     * hole: titles would generate on a model the org's policy forbids.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
     */
    public function testTheConversationsOrganisationIsPassedToGeneration(): void
    {
        $conversation = $this->conversation(
            [
                'title'  => 'New conversation',
                'userId' => 'alice',
            ],
            organisation: 'org-uuid-1'
        );

        $objectService = $this->objectService($conversation);
        $objectService->method('saveObject')->willReturn(new ObjectEntity());

        $handler = $this->createMock(ConversationManagementHandler::class);
        $handler->expects($this->once())
            ->method('generateConversationTitle')
            ->with('What is our leave policy?', 'org-uuid-1')
            ->willReturn('Leave policy');

        $writer = new ConversationTitleWriter($objectService, $handler, new NullLogger());
        $writer->write(conversationId: 'conv-1', userMessage: 'What is our leave policy?');

    }//end testTheConversationsOrganisationIsPassedToGeneration()

    /**
     * A conversation the user has already titled is never renamed.
     *
     * The decision is made at write time against a fresh read, so a job that runs
     * late — or twice — cannot clobber a title the user chose in the meantime.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
     */
    public function testAUserTitledConversationIsLeftAlone(): void
    {
        $conversation = $this->conversation(
            [
                'title'  => 'Q3 planning',
                'userId' => 'alice',
            ]
        );

        $objectService = $this->objectService($conversation);
        $objectService->expects($this->never())->method('saveObject');

        $handler = $this->createMock(ConversationManagementHandler::class);
        $handler->expects($this->never())->method('generateConversationTitle');

        $writer = new ConversationTitleWriter($objectService, $handler, new NullLogger());
        $writer->write(conversationId: 'conv-1', userMessage: 'anything');

    }//end testAUserTitledConversationIsLeftAlone()

    /**
     * The lowercase placeholder is recognised.
     *
     * Regression: the create path writes `New conversation` while the old check
     * matched `New Conversation` with a case-SENSITIVE strpos, so every conversation
     * started from the streaming path was permanently unnameable — 129 of 181 on the
     * reference instance.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
     */
    public function testTheLowercasePlaceholderIsRecognisedAsUntitled(): void
    {
        $conversation = $this->conversation(
            [
                'title'  => 'New conversation',
                'userId' => 'alice',
            ]
        );

        $objectService = $this->objectService($conversation);
        $objectService->expects($this->once())->method('saveObject')->willReturn(new ObjectEntity());

        $handler = $this->createMock(ConversationManagementHandler::class);
        $handler->expects($this->once())->method('generateConversationTitle')->willReturn('Leave policy');
        $handler->method('ensureUniqueTitle')->willReturn('Leave policy');

        $writer = new ConversationTitleWriter($objectService, $handler, new NullLogger());
        $writer->write(conversationId: 'conv-1', userMessage: 'What is our leave policy?');

    }//end testTheLowercasePlaceholderIsRecognisedAsUntitled()

    /**
     * A failed generation leaves the placeholder and does not throw.
     *
     * The reply has already been delivered; a naming hiccup must not surface as a
     * failed job with nothing useful to retry.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
     */
    public function testAFailedGenerationIsSwallowedAndWritesNothing(): void
    {
        $conversation = $this->conversation(
            [
                'title'  => 'New conversation',
                'userId' => 'alice',
            ]
        );

        $objectService = $this->objectService($conversation);
        $objectService->expects($this->never())->method('saveObject');

        $handler = $this->createMock(ConversationManagementHandler::class);
        $handler->method('generateConversationTitle')->willThrowException(new RuntimeException('LLM down'));

        $writer = new ConversationTitleWriter($objectService, $handler, new NullLogger());
        $writer->write(conversationId: 'conv-1', userMessage: 'What is our leave policy?');

        $this->addToAssertionCount(1);

    }//end testAFailedGenerationIsSwallowedAndWritesNothing()

    /**
     * A conversation deleted before the job ran is a no-op, not an error.
     *
     * @return void
     *
     * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
     */
    public function testAMissingConversationIsANoOp(): void
    {
        $objectService = $this->objectService(null);
        $objectService->expects($this->never())->method('saveObject');

        $handler = $this->createMock(ConversationManagementHandler::class);
        $handler->expects($this->never())->method('generateConversationTitle');

        $writer = new ConversationTitleWriter($objectService, $handler, new NullLogger());
        $writer->write(conversationId: 'gone', userMessage: 'anything');

        $this->addToAssertionCount(1);

    }//end testAMissingConversationIsANoOp()
}//end class
