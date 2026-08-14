<?php

/**
 * Unit tests for MemoryController (agent-memory).
 *
 * Covers the read + manage memory surface: memory() read, addMemory() append (happy path +
 * the empty-text 400 guard + the unauthenticated 401), and sessions() list. Each mutating /
 * reading endpoint is asserted for the 401-before-service contract shared across Hermiq's
 * controllers.
 *
 * Plus the per-agent IDOR guard (hermiq#187): all six routes take a caller-supplied
 * `agentId` off the URL, so each is asserted to 404 — and to leave the service
 * UNCALLED — for a caller who may not read (reads) or may not own (writes) that
 * agent. The guard is exercised through the REAL `AgentAccessService` over a mocked
 * `ObjectService`, so the predicate itself is under test rather than a double of it.
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
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\MemoryService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
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
class MemoryControllerTest extends TestCase {
	/**
	 * A Memory ObjectEntity with the given payload.
	 *
	 * @param array<string, mixed> $data The memory object payload.
	 *
	 * @return ObjectEntity
	 */
	private function memoryObject(array $data = []): ObjectEntity {
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
	private function request(array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($params) {
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
	private function session(?string $uid): IUserSession {
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
	 * An Agent ObjectEntity with the given owner and privacy.
	 *
	 * @param string $owner The owning uid.
	 * @param bool $isPrivate Whether the agent is private.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $owner = 'alice', bool $isPrivate = true): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner($owner);
		$entity->setObject(['isPrivate' => $isPrivate, 'invitedUsers' => []]);
		return $entity;
	}//end agent()

	/**
	 * A REAL AgentAccessService over an ObjectService mock resolving to $agent —
	 * the predicate under test is the production one, not a double of it.
	 *
	 * @param ObjectEntity|null $agent The agent the lookup resolves to, or null.
	 *
	 * @return AgentAccessService
	 */
	private function agentAccess(?ObjectEntity $agent): AgentAccessService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($agent);
		return new AgentAccessService($objectService, $this->createMock(LoggerInterface::class));
	}//end agentAccess()

	/**
	 * Build the controller with the given collaborators.
	 *
	 * @param MemoryService $service The memory service.
	 * @param IUserSession $session The user session.
	 * @param IRequest|null $request An optional request mock.
	 * @param ObjectEntity|null $agent The agent the guard resolves; defaults to one owned by `alice`.
	 *
	 * @return MemoryController
	 */
	private function controller(
		MemoryService $service,
		IUserSession $session,
		?IRequest $request = null,
		?ObjectEntity $agent = null,
	): MemoryController {
		return new MemoryController(
			($request ?? $this->request()),
			$service,
			$session,
			$this->createMock(LoggerInterface::class),
			$this->agentAccess(($agent ?? $this->agent('alice')))
		);

	}//end controller()

	/**
	 * memory() returns 200 with the agent's memory payload (uuid injected).
	 *
	 * @return void
	 */
	public function testMemoryReturnsPayload(): void {
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
	public function testMemoryUnauthenticated(): void {
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
	public function testAddMemoryAppendsEntry(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->once())
			->method('appendMemoryEntry')
			->with('agent-1', 'Remember this')
			->willReturn($this->memoryObject(['entries' => [['text' => 'Remember this']]]));

		$request = $this->request(['text' => 'Remember this']);
		$response = $this->controller($service, $this->session('alice'), $request)->addMemory('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAddMemoryAppendsEntry()

	/**
	 * addMemory() rejects an empty text with 400, never calling the service.
	 *
	 * @return void
	 */
	public function testAddMemoryEmptyTextIsBadRequest(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('appendMemoryEntry');

		$request = $this->request(['text' => '   ']);
		$response = $this->controller($service, $this->session('alice'), $request)->addMemory('agent-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAddMemoryEmptyTextIsBadRequest()

	/**
	 * addMemory() returns 401 for an unauthenticated caller.
	 *
	 * @return void
	 */
	public function testAddMemoryUnauthenticated(): void {
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
	public function testSessionsReturnsList(): void {
		$service = $this->createMock(MemoryService::class);
		$service->method('listSessions')->willReturn([$this->memoryObject(['title' => 'Chat'])]);

		$response = $this->controller($service, $this->session('alice'))->sessions('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['results']);

	}//end testSessionsReturnsList()

	/**
	 * IDOR (hermiq#187): a non-owner, non-invited caller cannot READ a private
	 * agent's Memory — 404, and `getMemory()` is never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testMemoryIsRefusedForAForeignPrivateAgent(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('getMemory');

		$response = $this->controller($service, $this->session('mallory'), null, $this->agent('alice', true))
			->memory('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testMemoryIsRefusedForAForeignPrivateAgent()

	/**
	 * IDOR (hermiq#187): appending to another user's agent memory is
	 * OWNER-guarded, not read-guarded — a shared (non-private) agent that
	 * `mallory` may READ is still refused for a WRITE, because the entry is
	 * folded into that agent's system-prompt preamble on its next run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testAddMemoryIsRefusedForANonOwnerOfASharedAgent(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('appendMemoryEntry');
		$request = $this->request(['text' => 'ATTACKER INJECTED FACT']);

		$response = $this->controller($service, $this->session('mallory'), $request, $this->agent('alice', false))
			->addMemory('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAddMemoryIsRefusedForANonOwnerOfASharedAgent()

	/**
	 * IDOR (hermiq#187): `consolidate()` REPLACES the entry array, so a non-owner
	 * call is a wipe. Owner-guarded; the service is never reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testConsolidateIsRefusedForANonOwner(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('consolidateMemory');
		$service->expects($this->never())->method('getMemory');
		$request = $this->request(['entries' => []]);

		$response = $this->controller($service, $this->session('mallory'), $request, $this->agent('alice', false))
			->consolidate('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testConsolidateIsRefusedForANonOwner()

	/**
	 * IDOR (hermiq#187): per-subject learned profiles are the most PII-dense
	 * objects in the app — refused for a foreign private agent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testUserProfilesAreRefusedForAForeignPrivateAgent(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('listUserProfiles');

		$response = $this->controller($service, $this->session('mallory'), null, $this->agent('alice', true))
			->userProfiles('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUserProfilesAreRefusedForAForeignPrivateAgent()

	/**
	 * IDOR (hermiq#187): session listing is refused for a foreign private agent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testSessionsAreRefusedForAForeignPrivateAgent(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('listSessions');

		$response = $this->controller($service, $this->session('mallory'), null, $this->agent('alice', true))
			->sessions('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSessionsAreRefusedForAForeignPrivateAgent()

	/**
	 * IDOR (hermiq#187): free-text recall across another agent's conversation
	 * turns is refused for a foreign private agent.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testRecallIsRefusedForAForeignPrivateAgent(): void {
		$service = $this->createMock(MemoryService::class);
		$service->expects($this->never())->method('recallSessions');

		$response = $this->controller($service, $this->session('mallory'), null, $this->agent('alice', true))
			->recall('agent-1', 'bank');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRecallIsRefusedForAForeignPrivateAgent()

	/**
	 * POSITIVE CONTROL: the same six routes still work for the agent's OWNER, so
	 * the tests above are measuring the guard and not a broken controller.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testOwnerStillReachesTheGuardedRoutes(): void {
		$service = $this->createMock(MemoryService::class);
		$service->method('getMemory')->willReturn($this->memoryObject(['entries' => []]));
		$service->method('appendMemoryEntry')->willReturn($this->memoryObject(['entries' => []]));
		$service->method('consolidateMemory')->willReturn($this->memoryObject(['entries' => []]));
		$service->method('listUserProfiles')->willReturn([]);
		$service->method('listSessions')->willReturn([]);
		$service->method('recallSessions')->willReturn([]);

		$request = $this->request(['text' => 'a fact', 'entries' => []]);
		$controller = $this->controller($service, $this->session('alice'), $request, $this->agent('alice', true));

		$this->assertSame(Http::STATUS_OK, $controller->memory('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->addMemory('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->userProfiles('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->sessions('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->consolidate('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->recall('agent-1', 'q')->getStatus());

	}//end testOwnerStillReachesTheGuardedRoutes()

	/**
	 * A non-owner CAN still read a SHARED (non-private) agent's memory surface —
	 * the guard scopes by access, it does not close the org-shared case.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-memory/spec.md#requirement-per-tenant-memory-scoping
	 */
	public function testNonOwnerCanReadASharedAgentsMemory(): void {
		$service = $this->createMock(MemoryService::class);
		$service->method('getMemory')->willReturn($this->memoryObject(['entries' => []]));

		$response = $this->controller($service, $this->session('bob'), null, $this->agent('alice', false))
			->memory('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testNonOwnerCanReadASharedAgentsMemory()
}//end class
