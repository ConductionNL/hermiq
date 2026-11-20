<?php

/**
 * Unit tests for the project layers on the Memory page's API (memory-project-scopes).
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
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\MemoryController;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\MemoryServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Listing projects, reading a project's memory and moving an entry between layers.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-owner-sees-and-moves-entries-between-layers-req-pmem-004
 */
#[CoversClass(MemoryController::class)]
class MemoryControllerProjectsTest extends TestCase {

	private const PROJECTS = [
		['projectKey' => 'aanbesteding-wagenpark', 'projectName' => 'Aanbesteding wagenpark'],
		['projectKey' => 'omgevingsvisie-2040', 'projectName' => 'Omgevingsvisie 2040'],
	];

	/**
	 * The memory store double.
	 *
	 * @var MemoryServiceInterface&MockObject
	 */
	private MemoryServiceInterface $memory;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->memory = $this->createMock(MemoryServiceInterface::class);
		$this->memory->method('listProjects')->willReturn(self::PROJECTS);
	}//end setUp()


	/**
	 * The controller for a caller with these request parameters.
	 *
	 * @param array<string,mixed> $params    The request parameters.
	 * @param string|null         $uid       The signed-in user, or null.
	 * @param bool                $canRead   Whether the caller may use the agent.
	 * @param bool                $canModify Whether the caller owns the agent.
	 *
	 * @return MemoryController
	 */
	private function controller(array $params = [], ?string $uid = 'alice', bool $canRead = true, bool $canModify = true): MemoryController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AgentAccessServiceInterface::class);
		$access->method('loadAccessibleAgent')->willReturn(($canRead === true) ? new ObjectEntity() : null);
		$access->method('loadModifiableAgent')->willReturn(($canModify === true) ? new ObjectEntity() : null);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new MemoryController(
			request: $request,
			memoryService: $this->memory,
			userSession: $session,
			logger: new NullLogger(),
			agentAccess: $access
		);
	}//end controller()


	/**
	 * A Memory object.
	 *
	 * @param string              $uuid Its uuid.
	 * @param array<string,mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end object()


	/**
	 * A reader of the agent gets its projects; others get the usual refusals.
	 *
	 * @return void
	 */
	public function testProjectsAreListedForAReader(): void {
		$listed = $this->controller(canModify: false)->projects(agentId: 'a1');
		self::assertSame([200, ['projects' => self::PROJECTS]], [$listed->getStatus(), $listed->getData()]);

		self::assertSame(401, $this->controller(uid: null)->projects(agentId: 'a1')->getStatus());
		self::assertSame(404, $this->controller(canRead: false)->projects(agentId: 'a1')->getStatus());
	}//end testProjectsAreListedForAReader()


	/**
	 * A failing store answers 500.
	 *
	 * @return void
	 */
	public function testAFailingProjectListIs500(): void {
		$memory = $this->createMock(MemoryServiceInterface::class);
		$memory->method('listProjects')->willThrowException(new RuntimeException('down'));
		$this->memory = $memory;

		$response = $this->controller()->projects(agentId: 'a1');

		self::assertSame([500, ['error' => 'Could not load projects']], [$response->getStatus(), $response->getData()]);
	}//end testAFailingProjectListIs500()


	/**
	 * The memory read names a known project; an unknown one is 404 and creates nothing.
	 *
	 * @return void
	 */
	public function testMemoryReadsTheNamedProjectOnly(): void {
		$this->memory->expects(self::once())
			->method('getMemory')
			->with('a1', 'omgevingsvisie-2040')
			->willReturn($this->object('mem-visie', ['scope' => 'project', 'entries' => []]));

		$read = $this->controller(params: ['project' => 'omgevingsvisie-2040'])->memory(agentId: 'a1');
		self::assertSame(['id' => 'mem-visie', 'scope' => 'project', 'entries' => [], 'uuid' => 'mem-visie'], $read->getData());

		$unknown = $this->controller(params: ['project' => 'iets-anders'])->memory(agentId: 'a1');
		self::assertSame([404, ['error' => 'Project not found']], [$unknown->getStatus(), $unknown->getData()]);
	}//end testMemoryReadsTheNamedProjectOnly()


	/**
	 * The owner moves an entry from a project to shared.
	 *
	 * @return void
	 */
	public function testTheOwnerMovesAnEntryToShared(): void {
		$this->memory->expects(self::once())
			->method('moveMemoryEntry')
			->with('a1', 'e1', 'omgevingsvisie-2040', '')
			->willReturn($this->object('mem-shared', ['scope' => 'shared']));

		$moved = $this->controller(params: ['from' => 'omgevingsvisie-2040', 'to' => ''])->moveEntry(agentId: 'a1', entryId: 'e1');

		self::assertSame([200, ['id' => 'mem-shared', 'scope' => 'shared', 'uuid' => 'mem-shared']], [$moved->getStatus(), $moved->getData()]);
	}//end testTheOwnerMovesAnEntryToShared()


	/**
	 * Only the owner may move; a reader is refused like a stranger.
	 *
	 * @return void
	 */
	public function testAReaderMayNotMove(): void {
		$this->memory->expects(self::never())->method('moveMemoryEntry');

		$refused = $this->controller(params: ['from' => 'omgevingsvisie-2040'], canModify: false)->moveEntry(agentId: 'a1', entryId: 'e1');

		self::assertSame([404, ['error' => 'Agent not found']], [$refused->getStatus(), $refused->getData()]);
		self::assertSame(401, $this->controller(uid: null)->moveEntry(agentId: 'a1', entryId: 'e1')->getStatus());
	}//end testAReaderMayNotMove()


	/**
	 * A move to the same layer, to or from an unknown project, or of a missing entry is refused.
	 *
	 * @return void
	 */
	public function testAMoveThatCannotHappenIsRefused(): void {
		$same = $this->controller(params: ['from' => 'omgevingsvisie-2040', 'to' => 'omgevingsvisie-2040'])->moveEntry(agentId: 'a1', entryId: 'e1');
		self::assertSame(400, $same->getStatus());

		$unknown = $this->controller(params: ['from' => '', 'to' => 'nieuw'])->moveEntry(agentId: 'a1', entryId: 'e1');
		self::assertSame([404, ['error' => 'Project not found']], [$unknown->getStatus(), $unknown->getData()]);

		$this->memory->method('moveMemoryEntry')->willReturn(null);
		$missing = $this->controller(params: ['from' => '', 'to' => 'omgevingsvisie-2040'])->moveEntry(agentId: 'a1', entryId: 'e9');
		self::assertSame([404, ['error' => 'Memory entry not found']], [$missing->getStatus(), $missing->getData()]);
	}//end testAMoveThatCannotHappenIsRefused()


	/**
	 * A failing move answers 500.
	 *
	 * @return void
	 */
	public function testAFailingMoveIs500(): void {
		$this->memory->method('moveMemoryEntry')->willThrowException(new RuntimeException('down'));

		$response = $this->controller(params: ['from' => 'omgevingsvisie-2040'])->moveEntry(agentId: 'a1', entryId: 'e1');

		self::assertSame([500, ['error' => 'Could not move memory']], [$response->getStatus(), $response->getData()]);
	}//end testAFailingMoveIs500()


	/**
	 * Forget and correct on a project tab act on that project.
	 *
	 * @return void
	 */
	public function testForgetAndCorrectActOnTheNamedProject(): void {
		$this->memory->expects(self::once())
			->method('forgetEntry')
			->with('a1', null, 'e1', 'omgevingsvisie-2040')
			->willReturn(['found' => true, 'scope' => 'memory']);
		$this->memory->method('getMemory')->willReturn($this->object('mem-visie', []));
		$this->memory->expects(self::once())
			->method('correctMemoryEntry')
			->with('a1', 'e1', 'Nieuw.', 'omgevingsvisie-2040')
			->willReturn($this->object('mem-visie', []));

		$params = ['project' => 'omgevingsvisie-2040', 'text' => 'Nieuw.'];
		self::assertSame(200, $this->controller(params: $params)->forgetEntry(agentId: 'a1', entryId: 'e1')->getStatus());
		self::assertSame(200, $this->controller(params: $params)->correctEntry(agentId: 'a1', entryId: 'e1')->getStatus());
		self::assertSame(404, $this->controller(params: ['project' => 'x', 'text' => 'y'])->correctEntry(agentId: 'a1', entryId: 'e1')->getStatus());
		self::assertSame(404, $this->controller(params: ['project' => 'x'])->forgetEntry(agentId: 'a1', entryId: 'e1')->getStatus());
	}//end testForgetAndCorrectActOnTheNamedProject()
}//end class
