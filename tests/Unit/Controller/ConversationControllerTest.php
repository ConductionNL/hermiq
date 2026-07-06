<?php

/**
 * Unit tests for ConversationController (agent-engine-port).
 *
 * Exercises the ported conversation CRUD against ObjectService: the
 * active/archived partition on the payload-level `metadata.deletedAt` marker,
 * the gate-7 ownership guards (403 on a foreign conversation), the immutable
 * field protection on update, the two-step destroy (archive → permanent), and
 * restore clearing the marker.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ConversationController;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-engine-port ConversationController.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class ConversationControllerTest extends TestCase
{

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock engine facade.
     *
     * @var Engine&MockObject
     */
    private Engine $engine;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock user session (alice by default).
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request       = $this->createMock(IRequest::class);
        $this->engine        = $this->createMock(Engine::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);

    }//end setUp()

    /**
     * Build the controller wired to the current mocks.
     *
     * @return ConversationController
     */
    private function controller(): ConversationController
    {
        return new ConversationController(
            $this->request,
            $this->engine,
            $this->objectService,
            $this->userSession,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * Build a conversation ObjectEntity fixture.
     *
     * @param string              $uuid    The object UUID.
     * @param array<string,mixed> $payload The object payload.
     *
     * @return ObjectEntity
     */
    private function conversation(string $uuid, array $payload): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($payload);
        return $entity;

    }//end conversation()

    /**
     * index() default lists only active conversations; _deleted=true lists
     * only archived ones (metadata.deletedAt partition).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testIndexPartitionsActiveAndArchived(): void
    {
        $active   = $this->conversation('conv-active', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Active']);
        $archived = $this->conversation(
            'conv-archived',
            [
                'userId'   => 'alice',
                'agentId'  => 'a1',
                'title'    => 'Archived',
                'metadata' => ['deletedAt' => '2026-07-01T00:00:00+00:00', 'deletedBy' => 'alice'],
            ]
        );
        $this->objectService->method('findAll')->willReturn([$active, $archived]);

        // Default: active only.
        $this->request->method('getParams')->willReturn([]);
        $response = $this->controller()->index();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);
        $this->assertSame('conv-active', $response->getData()['results'][0]['uuid']);
        $this->assertNull($response->getData()['results'][0]['deletedAt']);

    }//end testIndexPartitionsActiveAndArchived()

    /**
     * index() with _deleted=true returns only the archived conversations.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testIndexDeletedFilterReturnsArchivedOnly(): void
    {
        $active   = $this->conversation('conv-active', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Active']);
        $archived = $this->conversation(
            'conv-archived',
            [
                'userId'   => 'alice',
                'agentId'  => 'a1',
                'title'    => 'Archived',
                'metadata' => ['deletedAt' => '2026-07-01T00:00:00+00:00', 'deletedBy' => 'alice'],
            ]
        );
        $this->objectService->method('findAll')->willReturn([$active, $archived]);
        $this->request->method('getParams')->willReturn(['_deleted' => 'true']);

        $response = $this->controller()->index();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);
        $this->assertSame('conv-archived', $response->getData()['results'][0]['uuid']);
        $this->assertSame('2026-07-01T00:00:00+00:00', $response->getData()['results'][0]['deletedAt']);

    }//end testIndexDeletedFilterReturnsArchivedOnly()

    /**
     * show() is 404 for a missing conversation and 403 for a foreign one
     * (gate-7); the owner gets the payload plus messageCount from the
     * paginated total.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testShowGuardsAndReturnsMessageCount(): void
    {
        $this->request->method('getParams')->willReturn([]);

        // Missing → 404.
        $this->objectService->method('find')->willReturnOnConsecutiveCalls(
            null,
            $this->conversation('conv-bob', ['userId' => 'bob', 'agentId' => 'a1']),
            $this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Mine'])
        );
        $this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 3]);

        $controller = $this->controller();

        $this->assertSame(404, $controller->show('ghost')->getStatus());

        // Foreign → 403.
        $this->assertSame(403, $controller->show('conv-bob')->getStatus());

        // Own → 200 with messageCount.
        $response = $controller->show('conv-1');
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Mine', $response->getData()['title']);
        $this->assertSame(3, $response->getData()['messageCount']);

    }//end testShowGuardsAndReturnsMessageCount()

    /**
     * messages() enforces the ownership guard and returns the page plus the
     * paginated total.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testMessagesReturnsPageAndTotal(): void
    {
        $this->request->method('getParams')->willReturn(['limit' => 10]);
        $this->objectService->method('find')->willReturn(
            $this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1'])
        );

        $message = new ObjectEntity();
        $message->setUuid('msg-1');
        $message->setObject(['conversationId' => 'conv-1', 'role' => 'user', 'content' => 'hi']);
        $this->objectService->method('findAll')->willReturn([$message]);
        $this->objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 1]);

        $response = $this->controller()->messages('conv-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);
        $this->assertSame('msg-1', $response->getData()['results'][0]['uuid']);

    }//end testMessagesReturnsPageAndTotal()

    /**
     * create() persists userId/agentId/title/metadata and generates a unique
     * title via the engine when none is provided.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testCreatePersistsConversationWithGeneratedTitle(): void
    {
        $this->request->method('getParams')->willReturn(['agentId' => 'agent-1']);
        $this->engine->expects($this->once())->method('ensureUniqueTitle')->willReturn('New Conversation 2');

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$saved): ObjectEntity {
                $saved  = $object;
                $entity = new ObjectEntity();
                $entity->setUuid('conv-new');
                $entity->setObject($object);
                return $entity;
            }
        );

        $response = $this->controller()->create();

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('alice', $saved['userId']);
        $this->assertSame('agent-1', $saved['agentId']);
        $this->assertSame('New Conversation 2', $saved['title']);
        $this->assertSame('conv-new', $response->getData()['uuid']);

    }//end testCreatePersistsConversationWithGeneratedTitle()

    /**
     * update() refuses a foreign conversation (403, gate-7) and, for the
     * owner, only applies title/metadata — request attempts to change
     * userId/agentId are ignored.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testUpdateGuardsOwnershipAndImmutableFields(): void
    {
        $this->request->method('getParams')->willReturn(
            [
                'title'   => 'Renamed',
                'userId'  => 'mallory',
                'agentId' => 'evil-agent',
            ]
        );

        $this->objectService->method('find')->willReturnOnConsecutiveCalls(
            $this->conversation('conv-bob', ['userId' => 'bob', 'agentId' => 'a1']),
            $this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1', 'title' => 'Old'])
        );

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$saved): ObjectEntity {
                $saved  = $object;
                $entity = new ObjectEntity();
                $entity->setUuid('conv-1');
                $entity->setObject($object);
                return $entity;
            }
        );

        $controller = $this->controller();

        // Foreign → 403, nothing saved.
        $this->assertSame(403, $controller->update('conv-bob')->getStatus());
        $this->assertNull($saved);

        // Owner → title applied, immutables preserved.
        $response = $controller->update('conv-1');
        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Renamed', $saved['title']);
        $this->assertSame('alice', $saved['userId'], 'userId must never be taken from the request.');
        $this->assertSame('a1', $saved['agentId'], 'agentId must never be taken from the request.');

    }//end testUpdateGuardsOwnershipAndImmutableFields()

    /**
     * destroy() on an active conversation archives it (metadata.deletedAt
     * marker, no hard delete).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testDestroyArchivesActiveConversation(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('find')->willReturn(
            $this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1'])
        );
        $this->objectService->expects($this->never())->method('deleteObject');

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$saved): ObjectEntity {
                $saved = $object;
                return new ObjectEntity();
            }
        );

        $response = $this->controller()->destroy('conv-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['archived']);
        $this->assertNotEmpty($saved['metadata']['deletedAt']);
        $this->assertSame('alice', $saved['metadata']['deletedBy']);

    }//end testDestroyArchivesActiveConversation()

    /**
     * destroy() on an already-archived conversation deletes it permanently:
     * related feedback + messages first, then the conversation itself, all
     * via ObjectService::deleteObject().
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testDestroyArchivedConversationDeletesPermanently(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('find')->willReturn(
            $this->conversation(
                'conv-1',
                [
                    'userId'   => 'alice',
                    'agentId'  => 'a1',
                    'metadata' => ['deletedAt' => '2026-07-01T00:00:00+00:00'],
                ]
            )
        );

        $related = new ObjectEntity();
        $related->setUuid('rel-1');
        $related->setObject(['conversationId' => 'conv-1']);
        $this->objectService->method('findAll')->willReturn([$related]);
        $this->objectService->expects($this->never())->method('saveObject');

        $deleted = [];
        $this->objectService->method('deleteObject')->willReturnCallback(
            function (string $uuid, mixed $register=null, mixed $schema=null) use (&$deleted): bool {
                $deleted[] = [$uuid, $schema];
                return true;
            }
        );

        $response = $this->controller()->destroy('conv-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(
            [
                ['rel-1', 'feedback'],
                ['rel-1', 'message'],
                ['conv-1', 'conversation'],
            ],
            $deleted,
            'Feedback, then messages, then the conversation must be deleted (OR ordering).'
        );

    }//end testDestroyArchivedConversationDeletesPermanently()

    /**
     * restore() clears the archive marker (metadata collapses back to null
     * when no other keys remain).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testRestoreClearsArchiveMarker(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('find')->willReturn(
            $this->conversation(
                'conv-1',
                [
                    'userId'   => 'alice',
                    'agentId'  => 'a1',
                    'metadata' => [
                        'deletedAt' => '2026-07-01T00:00:00+00:00',
                        'deletedBy' => 'alice',
                    ],
                ]
            )
        );

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$saved): ObjectEntity {
                $saved  = $object;
                $entity = new ObjectEntity();
                $entity->setUuid('conv-1');
                $entity->setObject($object);
                return $entity;
            }
        );

        $response = $this->controller()->restore('conv-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($saved['metadata'], 'The emptied metadata object must collapse to null.');
        $this->assertNull($response->getData()['deletedAt']);

    }//end testRestoreClearsArchiveMarker()

    /**
     * destroyPermanent() deletes the messages and the conversation via
     * ObjectService::deleteObject() (feedback is untouched, mirroring OR).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testDestroyPermanentDeletesMessagesThenConversation(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('find')->willReturn(
            $this->conversation('conv-1', ['userId' => 'alice', 'agentId' => 'a1'])
        );

        $message = new ObjectEntity();
        $message->setUuid('msg-1');
        $message->setObject(['conversationId' => 'conv-1']);
        $this->objectService->method('findAll')->willReturn([$message]);

        $deleted = [];
        $this->objectService->method('deleteObject')->willReturnCallback(
            function (string $uuid, mixed $register=null, mixed $schema=null) use (&$deleted): bool {
                $deleted[] = [$uuid, $schema];
                return true;
            }
        );

        $response = $this->controller()->destroyPermanent('conv-1');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(
            [
                ['msg-1', 'message'],
                ['conv-1', 'conversation'],
            ],
            $deleted
        );

    }//end testDestroyPermanentDeletesMessagesThenConversation()

    /**
     * Gate-7: a foreign conversation can be neither destroyed, restored, nor
     * permanently deleted — every path is 403 with no write.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testForeignConversationLifecycleIsForbidden(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('find')->willReturn(
            $this->conversation('conv-bob', ['userId' => 'bob', 'agentId' => 'a1'])
        );
        $this->objectService->expects($this->never())->method('saveObject');
        $this->objectService->expects($this->never())->method('deleteObject');

        $controller = $this->controller();

        $this->assertSame(403, $controller->destroy('conv-bob')->getStatus());
        $this->assertSame(403, $controller->restore('conv-bob')->getStatus());
        $this->assertSame(403, $controller->destroyPermanent('conv-bob')->getStatus());

    }//end testForeignConversationLifecycleIsForbidden()
}//end class
