<?php

/**
 * Unit tests for AgentVersionController (agent-versioning).
 *
 * Focuses on the ADR-005 IDOR guard: a private agent's version history and
 * rollback are denied to a non-owner/non-invited user, and rollback is
 * owner-only even for a user who CAN read a shared (non-private) agent.
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
 * @spec openspec/changes/agent-versioning/tasks.md#task-2-agentversioncontroller-routes-owner-scoped-readrollback-endpoints
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentVersionController;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-versioning AgentVersionController.
 *
 * @spec openspec/changes/agent-versioning/tasks.md#task-2-agentversioncontroller-routes-owner-scoped-readrollback-endpoints
 */
class AgentVersionControllerTest extends TestCase
{

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock AgentVersionService.
     *
     * @var AgentVersionService&MockObject
     */
    private AgentVersionService $agentVersionService;

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService       = $this->createMock(ObjectService::class);
        $this->agentVersionService = $this->createMock(AgentVersionService::class);

    }//end setUp()

    /**
     * Build an agent ObjectEntity.
     *
     * @param string $owner     The owner UID.
     * @param bool   $isPrivate Whether the agent is private.
     *
     * @return ObjectEntity
     */
    private function agent(string $owner, bool $isPrivate=true): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('agent-1');
        $entity->setOwner($owner);
        $entity->setObject(['name' => 'Support triage', 'isPrivate' => $isPrivate]);
        return $entity;

    }//end agent()

    /**
     * A session with the given user.
     *
     * @param string $uid The requesting user's UID.
     *
     * @return IUserSession
     */
    private function session(string $uid): IUserSession
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * Build the controller with the given user session and query params.
     *
     * @param IUserSession        $userSession The user session.
     * @param array<string,mixed> $params      Query params returned by getParam().
     *
     * @return AgentVersionController
     */
    private function controller(IUserSession $userSession, array $params=[]): AgentVersionController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) use ($params) {
                return ($params[$key] ?? $default);
            }
        );

        return new AgentVersionController(
            $request,
            $this->objectService,
            $this->agentVersionService,
            $userSession,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * The agent's owner can list its version history.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function testOwnerCanListVersions(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice'));
        $this->agentVersionService->method('listVersions')->willReturn(
            [['id' => 'e1', 'timestamp' => '2026-01-01T00:00:00+00:00', 'user' => 'alice', 'action' => 'create', 'changedFields' => []]]
        );

        $controller = $this->controller($this->session('alice'));
        $response   = $controller->index('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);

    }//end testOwnerCanListVersions()

    /**
     * A non-owner, non-invited user cannot list a PRIVATE agent's version
     * history — no version data is returned.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
     */
    public function testNonOwnerCannotListPrivateAgentVersions(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice', true));
        $this->agentVersionService->expects($this->never())->method('listVersions');

        $controller = $this->controller($this->session('mallory'));
        $response   = $controller->index('agent-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertArrayNotHasKey('results', $response->getData());

    }//end testNonOwnerCannotListPrivateAgentVersions()

    /**
     * A non-owner CAN read a non-private (shared) agent's version history.
     *
     * @return void
     */
    public function testNonOwnerCanListSharedAgentVersions(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice', false));
        $this->agentVersionService->method('listVersions')->willReturn([]);

        $controller = $this->controller($this->session('bob'));
        $response   = $controller->index('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testNonOwnerCanListSharedAgentVersions()

    /**
     * A user with read access can diff two versions.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
     */
    public function testOwnerCanDiffVersions(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice'));
        $this->agentVersionService->method('diff')->willReturn(['prompt' => ['old' => 'a', 'new' => 'b']]);

        $controller = $this->controller($this->session('alice'), ['from' => 'e1', 'to' => 'e2']);
        $response   = $controller->diff('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['prompt' => ['old' => 'a', 'new' => 'b']], $response->getData()['results']);

    }//end testOwnerCanDiffVersions()

    /**
     * A missing `from`/`to` query param is rejected with 400 before ever
     * calling the service.
     *
     * @return void
     */
    public function testDiffRequiresFromAndTo(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice'));
        $this->agentVersionService->expects($this->never())->method('diff');

        $controller = $this->controller($this->session('alice'), ['from' => 'e1']);
        $response   = $controller->diff('agent-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testDiffRequiresFromAndTo()

    /**
     * The agent's owner can roll it back.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
     */
    public function testOwnerCanRollback(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice'));
        $this->agentVersionService->method('rollback')->willReturn($this->agent('alice'));

        $controller = $this->controller($this->session('alice'));
        $response   = $controller->rollback('agent-1', 'e1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testOwnerCanRollback()

    /**
     * A non-owner cannot roll back another user's agent — even one they can
     * otherwise READ (a shared, non-private agent) — and the agent is never
     * touched.
     *
     * @return void
     *
     * @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
     */
    public function testNonOwnerCannotRollback(): void
    {
        $this->objectService->method('find')->willReturn($this->agent('alice', false));
        $this->agentVersionService->expects($this->never())->method('rollback');

        $controller = $this->controller($this->session('bob'));
        $response   = $controller->rollback('agent-1', 'e1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testNonOwnerCannotRollback()

    /**
     * A rollback request for an agent that does not exist is a 404.
     *
     * @return void
     */
    public function testRollbackOfMissingAgentIsNotFound(): void
    {
        $this->objectService->method('find')->willReturn(null);
        $this->agentVersionService->expects($this->never())->method('rollback');

        $controller = $this->controller($this->session('alice'));
        $response   = $controller->rollback('agent-1', 'e1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testRollbackOfMissingAgentIsNotFound()
}//end class
