<?php

/**
 * Unit tests for starting a session in a project (memory-project-scopes).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SessionController;
use OCA\Hermiq\Kernel\Contracts\MemoryServiceInterface;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Chat\SessionCollaborators;
use OCA\Hermiq\Service\Chat\SessionPendingApprovals;
use OCA\Hermiq\Service\Chat\SessionSubject;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A session names its project when it starts and keeps it.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
#[CoversClass(SessionController::class)]
#[UsesClass(SessionCollaborators::class)]
#[UsesClass(SessionSubject::class)]
#[UsesClass(MemoryService::class)]
#[UsesClass(ConversationParticipation::class)]
class SessionControllerProjectTest extends TestCase {

	/**
	 * The memory store double.
	 *
	 * @var MemoryServiceInterface&MockObject
	 */
	private MemoryServiceInterface $memory;

	/**
	 * The object store double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The saved session data.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $saved = null;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->memory = $this->createMock(MemoryServiceInterface::class);
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('conv-new');
				$entity->setObject($object);
				return $entity;
			}
		);
	}//end setUp()


	/**
	 * The controller for alice, with these request parameters.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return SessionController
	 */
	private function controller(array $params): SessionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$access = $this->createMock(AgentAccessService::class);
		$access->method('loadAccessibleAgent')->willReturn($agent);

		$room = $this->createMock(TalkSessionRoom::class);
		$room->method('attachToSession')->willReturnArgument(0);

		return new SessionController(
			request: $request,
			engine: $this->createMock(Engine::class),
			objectService: $this->objects,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
			collaborators: new SessionCollaborators(
				sessionRoom: $room,
				agentAccess: $access,
				subject: new SessionSubject(objectService: $this->objects),
				pendingApprovals: $this->createMock(SessionPendingApprovals::class)
			),
			memoryService: $this->memory
		);
	}//end controller()


	/**
	 * A session started in an existing project carries its key and shows it.
	 *
	 * @return void
	 */
	public function testASessionStartsInAProject(): void {
		$this->memory->expects(self::once())
			->method('ensureProjectMemory')
			->with('agent-1', 'omgevingsvisie-2040', 'Omgevingsvisie 2040');

		$response = $this->controller(['agentId' => 'agent-1', 'title' => 'Visie', 'projectKey' => 'omgevingsvisie-2040', 'projectName' => 'Omgevingsvisie 2040'])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('omgevingsvisie-2040', $this->saved['projectKey']);
		self::assertSame('omgevingsvisie-2040', $response->getData()['projectKey']);
	}//end testASessionStartsInAProject()


	/**
	 * A new project named without a key gets a key made from its name.
	 *
	 * @return void
	 */
	public function testANewProjectGetsAKeyFromItsName(): void {
		$this->memory->expects(self::once())
			->method('ensureProjectMemory')
			->with('agent-1', 'begroting-2027-wijk-oost', 'Begroting 2027: wijk Oost');

		$this->controller(['agentId' => 'agent-1', 'title' => 't', 'projectName' => 'Begroting 2027: wijk Oost'])->create();

		self::assertSame('begroting-2027-wijk-oost', $this->saved['projectKey']);
	}//end testANewProjectGetsAKeyFromItsName()


	/**
	 * Without a project the session is saved and shown as before.
	 *
	 * @return void
	 */
	public function testASessionWithoutAProjectIsUnchanged(): void {
		$this->memory->expects(self::never())->method('ensureProjectMemory');

		$response = $this->controller(['agentId' => 'agent-1', 'title' => 't'])->create();

		self::assertSame(201, $response->getStatus());
		self::assertArrayNotHasKey('projectKey', $this->saved);
		self::assertArrayNotHasKey('projectKey', $response->getData());
	}//end testASessionWithoutAProjectIsUnchanged()


	/**
	 * A malformed key is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testAMalformedProjectKeyIsRefused(): void {
		$this->memory->expects(self::never())->method('ensureProjectMemory');

		$response = $this->controller(['agentId' => 'agent-1', 'projectKey' => 'Andere Agent/Project'])->create();

		self::assertSame(400, $response->getStatus());
		self::assertSame('Invalid project', $response->getData()['error']);
		self::assertNull($this->saved);
	}//end testAMalformedProjectKeyIsRefused()


	/**
	 * The project parameters resolve to none, a project, or a refusal.
	 *
	 * @return void
	 */
	public function testRequestedProject(): void {
		self::assertNull(SessionController::requestedProject(data: []));
		self::assertNull(SessionController::requestedProject(data: ['projectKey' => ' ', 'projectName' => '']));
		self::assertSame(['projectKey' => 'p-1', 'projectName' => ''], SessionController::requestedProject(data: ['projectKey' => 'p-1']));
		self::assertFalse(SessionController::requestedProject(data: ['projectName' => '!!!']));
		self::assertFalse(SessionController::requestedProject(data: ['projectKey' => 'UPPER']));
	}//end testRequestedProject()


	/**
	 * Keys made from names: lower case, transliterated, one hyphen per gap, at most 64 characters.
	 *
	 * @return void
	 */
	public function testProjectKeyFromName(): void {
		self::assertSame('omgevingsvisie-2040', SessionController::projectKeyFromName(name: 'Omgevingsvisie 2040'));
		self::assertSame('plan-fase-2', SessionController::projectKeyFromName(name: '  Plan — fase 2! '));
		self::assertSame('', SessionController::projectKeyFromName(name: '???'));
		self::assertSame(str_repeat('a', 64), SessionController::projectKeyFromName(name: str_repeat('a', 80)));
		self::assertSame(str_repeat('a', 63), SessionController::projectKeyFromName(name: str_repeat('a', 63) . ' bbb'));
	}//end testProjectKeyFromName()
}//end class
