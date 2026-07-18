<?php

/**
 * Unit tests for MemoryController (agent-memory).
 *
 * Covers the read + manage memory surface: memory() read, addMemory() append (happy path +
 * the empty-text 400 guard + the unauthenticated 401), and sessions() list. Each mutating /
 * reading endpoint is asserted for the 401-before-service contract shared across Hermiq's
 * controllers.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\MemoryController;
use OCA\Hermiq\Service\MemoryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the agent-memory controller.
 *
 * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-3
 */
class MemoryControllerTest extends TestCase
{
    /**
     * A Memory ObjectEntity with the given payload.
     *
     * @param array<string, mixed> $data The memory object payload.
     *
     * @return ObjectEntity
     */
    private function memoryObject(array $data=[]): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('mem-1');
        $entity->setObject($data);
        return $entity;

    }//end memoryObject()

    /**
     * A request mock returning the given params.
     *
     * @param array<string, mixed> $params The request params keyed by name.
     *
     * @return IRequest
     */
    private function request(array $params=[]): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return $params[$key] ?? $default;
            }
        );
        return $request;

    }//end request()

    /**
     * A session with the given (or no) user.
     *
     * @param string|null $uid The UID, or null for unauthenticated.
     *
     * @return IUserSession
     */
    private function session(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * Build the controller with the given collaborators.
     *
     * @param MemoryService $service The memory service.
     * @param IUserSession  $session The user session.
     * @param IRequest|null $request An optional request mock.
     *
     * @return MemoryController
     */
    private function controller(MemoryService $service, IUserSession $session, ?IRequest $request=null): MemoryController
    {
        return new MemoryController(
            ($request ?? $this->request()),
            $service,
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * memory() returns 200 with the agent's memory payload (uuid injected).
     *
     * @return void
     */
    public function testMemoryReturnsPayload(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->method('getMemory')->willReturn($this->memoryObject(['entries' => [], 'charBudget' => 8000]));

        $response = $this->controller($service, $this->session('alice'))->memory('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('mem-1', $response->getData()['uuid']);

    }//end testMemoryReturnsPayload()

    /**
     * memory() returns 401 for an unauthenticated caller, never calling the service.
     *
     * @return void
     */
    public function testMemoryUnauthenticated(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->expects($this->never())->method('getMemory');

        $response = $this->controller($service, $this->session(null))->memory('agent-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testMemoryUnauthenticated()

    /**
     * addMemory() appends a fact and returns 200 with the updated memory.
     *
     * @return void
     */
    public function testAddMemoryAppendsEntry(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->expects($this->once())
            ->method('appendMemoryEntry')
            ->with('agent-1', 'Remember this')
            ->willReturn($this->memoryObject(['entries' => [['text' => 'Remember this']]]));

        $request  = $this->request(['text' => 'Remember this']);
        $response = $this->controller($service, $this->session('alice'), $request)->addMemory('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testAddMemoryAppendsEntry()

    /**
     * addMemory() rejects an empty text with 400, never calling the service.
     *
     * @return void
     */
    public function testAddMemoryEmptyTextIsBadRequest(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->expects($this->never())->method('appendMemoryEntry');

        $request  = $this->request(['text' => '   ']);
        $response = $this->controller($service, $this->session('alice'), $request)->addMemory('agent-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testAddMemoryEmptyTextIsBadRequest()

    /**
     * addMemory() returns 401 for an unauthenticated caller.
     *
     * @return void
     */
    public function testAddMemoryUnauthenticated(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->expects($this->never())->method('appendMemoryEntry');

        $response = $this->controller($service, $this->session(null))->addMemory('agent-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAddMemoryUnauthenticated()

    /**
     * sessions() returns 200 with the tenant-scoped session list.
     *
     * @return void
     */
    public function testSessionsReturnsList(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->method('listSessions')->willReturn([$this->memoryObject(['title' => 'Chat'])]);

        $response = $this->controller($service, $this->session('alice'))->sessions('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['results']);

    }//end testSessionsReturnsList()

    /**
     * forget() soft-deletes the matching entry, scoping to the ACTING user's own
     * subjectUid (never a caller-supplied one — the same IDOR posture as the
     * `hermiq.forgetMemory` MCP tool).
     *
     * @return void
     */
    public function testForgetSoftDeletesEntry(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->expects($this->once())
            ->method('forgetEntry')
            ->with('agent-1', 'alice', 'entry-1')
            ->willReturn(['found' => true, 'scope' => 'memory']);

        $response = $this->controller($service, $this->session('alice'))->forget('agent-1', 'entry-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['found' => true, 'scope' => 'memory'], $response->getData());

    }//end testForgetSoftDeletesEntry()

    /**
     * forget() returns a soft not-found envelope (still 200) when no entry matches —
     * never a 404, mirroring MemoryService::forgetEntry()'s not-found contract.
     *
     * @return void
     */
    public function testForgetReturnsNotFoundEnvelopeForUnknownId(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->method('forgetEntry')->willReturn(['found' => false, 'scope' => null]);

        $response = $this->controller($service, $this->session('alice'))->forget('agent-1', 'missing');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->getData()['found']);

    }//end testForgetReturnsNotFoundEnvelopeForUnknownId()

    /**
     * forget() returns 401 for an unauthenticated caller, never calling the service.
     *
     * @return void
     */
    public function testForgetUnauthenticated(): void
    {
        $service = $this->createMock(MemoryService::class);
        $service->expects($this->never())->method('forgetEntry');

        $response = $this->controller($service, $this->session(null))->forget('agent-1', 'entry-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testForgetUnauthenticated()
}//end class
