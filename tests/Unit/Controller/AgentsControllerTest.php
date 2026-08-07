<?php

/**
 * Unit tests for AgentsController (agent-engine-port).
 *
 * Exercises the ported agent CRUD against ObjectService: the visibility rule
 * (non-private OR owner OR invited) on index/show, the owner-only modify/
 * delete guards (gate-7), the organisation/owner strip on create/update
 * (privilege-escalation protection), paginated-total stats, and the tool
 * catalogue backed by OR's public ToolRegistryFacade.
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

use OCA\Hermiq\Controller\AgentsController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-engine-port AgentsController.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
 */
class AgentsControllerTest extends TestCase
{

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock ToolRegistryFacade.
     *
     * @var ToolRegistryFacade&MockObject
     */
    private ToolRegistryFacade $toolRegistry;

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
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->toolRegistry = $this->createMock(ToolRegistryFacade::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);

    }//end setUp()

    /**
     * Build the controller wired to the current mocks.
     *
     * @return AgentsController
     */
    private function controller(): AgentsController
    {
        return new AgentsController(
            $this->request,
            $this->objectService,
            $this->toolRegistry,
            $this->userSession,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * Build an agent ObjectEntity fixture.
     *
     * @param string              $uuid    The object UUID.
     * @param array<string,mixed> $payload The object payload.
     * @param string|null         $owner   The entity owner.
     *
     * @return ObjectEntity
     */
    private function agent(string $uuid, array $payload, ?string $owner=null): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setOwner($owner);
        $entity->setObject($payload);
        return $entity;

    }//end agent()

    /**
     * index() applies the visibility rule: non-private agents, own private
     * agents, and invited private agents are listed; foreign private agents
     * are not.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testIndexFiltersByVisibility(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('findAll')->willReturn(
            [
                $this->agent('agent-public', ['name' => 'Public', 'isPrivate' => false], 'bob'),
                $this->agent('agent-own', ['name' => 'Mine', 'isPrivate' => true], 'alice'),
                $this->agent('agent-invited', ['name' => 'Invited', 'isPrivate' => true, 'invitedUsers' => ['alice']], 'bob'),
                $this->agent('agent-foreign', ['name' => 'Hidden', 'isPrivate' => true], 'bob'),
            ]
        );

        $response = $this->controller()->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $uuids = array_column($response->getData()['results'], 'uuid');
        $this->assertSame(['agent-public', 'agent-own', 'agent-invited'], $uuids);

    }//end testIndexFiltersByVisibility()

    /**
     * show() is 404 for a missing agent, 403 for a foreign private agent
     * (gate-7), and returns the serialized agent for an accessible one.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testShowGuardsVisibility(): void
    {
        $this->objectService->method('find')->willReturnOnConsecutiveCalls(
            null,
            $this->agent('agent-foreign', ['name' => 'Hidden', 'isPrivate' => true], 'bob'),
            $this->agent('agent-own', ['name' => 'Mine', 'isPrivate' => true], 'alice')
        );

        $controller = $this->controller();

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('ghost')->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->show('agent-foreign')->getStatus());

        $response = $controller->show('agent-own');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('Mine', $response->getData()['name']);
        $this->assertSame('alice', $response->getData()['owner']);

    }//end testShowGuardsVisibility()

    /**
     * create() strips organisation/owner/_route from the request (they are
     * assigned by ObjectService, not the caller) and applies the OR defaults
     * (private, file/object search enabled).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testCreateStripsProtectedKeysAndAppliesDefaults(): void
    {
        $this->request->method('getParams')->willReturn(
            [
                '_route'       => 'hermiq.agents.create',
                'name'         => 'New agent',
                'organisation' => 'evil-org',
                'owner'        => 'mallory',
            ]
        );

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$saved): ObjectEntity {
                $saved  = $object;
                $entity = new ObjectEntity();
                $entity->setUuid('agent-new');
                $entity->setOwner('alice');
                $entity->setObject($object);
                return $entity;
            }
        );

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('New agent', $saved['name']);
        $this->assertArrayNotHasKey('organisation', $saved, 'The caller must not choose the organisation.');
        $this->assertArrayNotHasKey('owner', $saved, 'The caller must not choose the owner.');
        $this->assertArrayNotHasKey('_route', $saved);
        $this->assertTrue($saved['isPrivate'], 'Agents are private by default.');
        $this->assertTrue($saved['searchFiles'], 'File search defaults on.');
        $this->assertTrue($saved['searchObjects'], 'Object search defaults on.');

    }//end testCreateStripsProtectedKeysAndAppliesDefaults()

    /**
     * update() refuses a non-owner (403, gate-7, nothing saved) and, for the
     * owner, merges the payload while stripping owner/organisation tampering.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testUpdateIsOwnerOnlyAndStripsTampering(): void
    {
        $this->request->method('getParams')->willReturn(
            [
                'name'         => 'Renamed',
                'owner'        => 'mallory',
                'organisation' => 'evil-org',
            ]
        );

        $this->objectService->method('find')->willReturnOnConsecutiveCalls(
            $this->agent('agent-bob', ['name' => 'Bobs', 'isPrivate' => true], 'bob'),
            $this->agent('agent-own', ['name' => 'Mine', 'temperature' => 0.5, 'isPrivate' => true], 'alice')
        );

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object) use (&$saved): ObjectEntity {
                $saved  = $object;
                $entity = new ObjectEntity();
                $entity->setUuid('agent-own');
                $entity->setOwner('alice');
                $entity->setObject($object);
                return $entity;
            }
        );

        $controller = $this->controller();

        // Non-owner → 403, nothing saved.
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->update('agent-bob')->getStatus());
        $this->assertNull($saved);

        // Owner → partial update applied, tampering keys stripped, rest kept.
        $response = $controller->update('agent-own');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('Renamed', $saved['name']);
        $this->assertSame(0.5, $saved['temperature'], 'Unmentioned fields survive the partial update.');
        $this->assertArrayNotHasKey('owner', $saved);
        $this->assertArrayNotHasKey('organisation', $saved);

    }//end testUpdateIsOwnerOnlyAndStripsTampering()

    /**
     * patch() delegates to update() (same partial-update semantics).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testPatchDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Patched']);
        $this->objectService->method('find')->willReturn(
            $this->agent('agent-own', ['name' => 'Mine'], 'alice')
        );
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed $object): ObjectEntity {
                $entity = new ObjectEntity();
                $entity->setUuid('agent-own');
                $entity->setObject($object);
                return $entity;
            }
        );

        $response = $this->controller()->patch('agent-own');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('Patched', $response->getData()['name']);

    }//end testPatchDelegatesToUpdate()

    /**
     * destroy() refuses a non-owner (403, gate-7) and deletes for the owner
     * via ObjectService::deleteObject().
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testDestroyIsOwnerOnly(): void
    {
        $this->objectService->method('find')->willReturnOnConsecutiveCalls(
            $this->agent('agent-bob', ['name' => 'Bobs'], 'bob'),
            $this->agent('agent-own', ['name' => 'Mine'], 'alice')
        );

        $deleted = [];
        $this->objectService->method('deleteObject')->willReturnCallback(
            function (string $uuid, mixed $register=null, mixed $schema=null) use (&$deleted): bool {
                $deleted[] = [$uuid, $schema];
                return true;
            }
        );

        $controller = $this->controller();

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->destroy('agent-bob')->getStatus());
        $this->assertSame([], $deleted);

        $response = $controller->destroy('agent-own');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['agent-own', 'agent']], $deleted);

    }//end testDestroyIsOwnerOnly()

    /**
     * stats() reads total/active/inactive from paginated totals (org-scoped
     * by ObjectService multitenancy).
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testStatsUsesPaginatedTotals(): void
    {
        $this->objectService->method('searchObjectsPaginated')->willReturnCallback(
            function (array $query=[]): array {
                if (array_key_exists('active', $query) === false) {
                    return ['results' => [], 'total' => 10];
                }

                // The filter arrives as the STRING 'true'/'false', not a bool.
                // `countAgents()` normalises it on purpose: the value is bound as
                // a query parameter, and PHP casts `false` to the EMPTY STRING,
                // which Postgres rejects on a boolean column with SQLSTATE[22P02]
                // — so stats() was a hard 500 on every call and the dashboard's
                // agent counters were blank.
                //
                // Asserted rather than merely matched, so a regression to passing
                // raw booleans fails HERE with a readable message instead of
                // silently falling through to the inactive branch — which is what
                // it did while this callback still compared against `true`, and
                // it surfaced as "active 4, inactive 4, total 10", counts that do
                // not even add up.
                $this->assertIsString(
                    $query['active'],
                    'countAgents() must normalise the active filter to a string; a bool binds as '
                    ."'' for false and Postgres rejects it (SQLSTATE[22P02])."
                );

                if ($query['active'] === 'true') {
                    return ['results' => [], 'total' => 6];
                }

                $this->assertSame('false', $query['active'], 'The only other value may be the string false.');

                return ['results' => [], 'total' => 4];
            }
        );

        $response = $this->controller()->stats();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [
                'total'    => 10,
                'active'   => 6,
                'inactive' => 4,
            ],
            $response->getData()
        );

    }//end testStatsUsesPaginatedTotals()

    /**
     * tools() returns the ToolRegistryFacade descriptor list in OR's
     * {results: [...]} envelope.
     *
     * @return void
     *
     * @spec openspec/changes/agent-engine-port/tasks.md#task-4-1
     */
    public function testToolsReturnsFacadeCatalogue(): void
    {
        $descriptor = [
            'name'        => 'decidesk_listMeetings',
            'description' => 'List meetings',
            'parameters'  => [],
            'mcpId'       => 'decidesk.listMeetings',
        ];
        $this->toolRegistry->expects($this->once())->method('listTools')->willReturn([$descriptor]);

        $response = $this->controller()->tools();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([$descriptor], $response->getData()['results']);

    }//end testToolsReturnsFacadeCatalogue()
}//end class
