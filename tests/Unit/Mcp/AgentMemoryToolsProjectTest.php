<?php

/**
 * Unit tests for the project layers in the agent memory tools (memory-project-scopes).
 *
 * The session's project key is injected into the tool arguments by the run
 * (FacadeToolInvoker / the governed MCP endpoint). These tests show which
 * layer each tool call reaches with and without one.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Mcp
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
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Kernel\Contracts\MemoryServiceInterface;
use OCA\Hermiq\Mcp\AgentMemoryTools;
use OCA\Hermiq\Mcp\NativeToolCall;
use OCA\Hermiq\Mcp\ToolErrorEnvelope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which memory layer the remember, recall and forget tools reach.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
 */
#[CoversClass(AgentMemoryTools::class)]
#[UsesClass(NativeToolCall::class)]
#[UsesClass(ToolErrorEnvelope::class)]
class AgentMemoryToolsProjectTest extends TestCase {

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
	}//end setUp()


	/**
	 * Run one memory tool as alice with these arguments.
	 *
	 * @param string              $tool      The tool name after `hermiq.`.
	 * @param array<string,mixed> $arguments The arguments.
	 *
	 * @return array<string,mixed>|null
	 */
	private function call(string $tool, array $arguments): ?array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return (new AgentMemoryTools(memoryService: $this->memory))->invoke(
			toolId: 'hermiq.' . $tool,
			call: new NativeToolCall(user: $user, arguments: $arguments)
		);
	}//end call()


	/**
	 * A Memory object with one entry.
	 *
	 * @return ObjectEntity
	 */
	private function stored(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['entries' => [['id' => 'e-1', 'text' => 'x']], 'needsConsolidation' => false]);
		return $entity;
	}//end stored()


	/**
	 * In a project session scope agent writes to the project memory.
	 *
	 * @return void
	 */
	public function testAgentScopeInAProjectWritesTheProject(): void {
		$this->memory->expects(self::once())
			->method('appendMemoryEntry')
			->with('agent-1', 'De raad behandelt het ontwerp op 14 november 2026.', 'omgevingsvisie-2040')
			->willReturn($this->stored());

		$result = $this->call('rememberMemory', [
			'agentId' => 'agent-1',
			'content' => 'De raad behandelt het ontwerp op 14 november 2026.',
			'scope' => 'agent',
			'projectKey' => 'omgevingsvisie-2040',
		]);

		self::assertSame(['remembered' => true, 'scope' => 'agent', 'entryId' => 'e-1', 'needsConsolidation' => false, 'layer' => 'project'], $result);
	}//end testAgentScopeInAProjectWritesTheProject()


	/**
	 * Scope shared writes to the shared memory, also in a project session.
	 *
	 * @return void
	 */
	public function testSharedScopeWritesTheSharedMemory(): void {
		$this->memory->expects(self::once())
			->method('appendMemoryEntry')
			->with('agent-1', 'Een naamconventie.', null)
			->willReturn($this->stored());

		$result = $this->call('rememberMemory', ['agentId' => 'agent-1', 'content' => 'Een naamconventie.', 'scope' => 'shared', 'projectKey' => 'omgevingsvisie-2040']);

		self::assertSame('shared', $result['scope']);
		self::assertSame('shared', $result['layer']);
	}//end testSharedScopeWritesTheSharedMemory()


	/**
	 * Outside a project scope agent writes to the shared memory.
	 *
	 * @return void
	 */
	public function testAgentScopeOutsideAProjectWritesShared(): void {
		$this->memory->expects(self::once())
			->method('appendMemoryEntry')
			->with('agent-1', 'Feit.', null)
			->willReturn($this->stored());

		$result = $this->call('rememberMemory', ['agentId' => 'agent-1', 'content' => 'Feit.', 'scope' => 'agent', 'projectKey' => '  ']);

		self::assertSame('shared', $result['layer']);
	}//end testAgentScopeOutsideAProjectWritesShared()


	/**
	 * Scope user still writes the acting user's profile and never a memory layer.
	 *
	 * @return void
	 */
	public function testUserScopeWritesTheProfile(): void {
		$this->memory->expects(self::never())->method('appendMemoryEntry');
		$this->memory->expects(self::once())
			->method('appendUserProfileEntry')
			->with('agent-1', 'alice', 'Houdt van korte zinnen.')
			->willReturn($this->stored());

		$result = $this->call('rememberMemory', ['agentId' => 'agent-1', 'content' => 'Houdt van korte zinnen.', 'scope' => 'user', 'projectKey' => 'p']);

		self::assertSame('user', $result['scope']);
		self::assertArrayNotHasKey('layer', $result);
	}//end testUserScopeWritesTheProfile()


	/**
	 * Any other scope is refused and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownScopeIsRefused(): void {
		$this->memory->expects(self::never())->method('appendMemoryEntry');

		$result = $this->call('rememberMemory', ['agentId' => 'agent-1', 'content' => 'x', 'scope' => 'project']);

		self::assertSame(['error' => ['code' => 'invalid_argument', 'message' => 'scope must be "agent", "user" or "shared".']], $result);
	}//end testAnUnknownScopeIsRefused()


	/**
	 * Recall in a project hands the project key to the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-recall-in-a-project-reads-the-project-and-the-shared-memory-only-req-pmem-002
	 */
	public function testRecallPassesTheProjectKey(): void {
		$this->memory->expects(self::once())
			->method('recallEntries')
			->with('agent-1', 'alice', 'bestelbussen', 20, 'omgevingsvisie-2040')
			->willReturn(['memoryEntries' => [['id' => 'e', 'text' => 't', 'layer' => 'shared']], 'userProfileEntries' => []]);
		$this->memory->method('recallSessions')->willReturn([]);

		$result = $this->call('recallMemory', ['agentId' => 'agent-1', 'query' => 'bestelbussen', 'projectKey' => 'omgevingsvisie-2040']);

		self::assertSame([['id' => 'e', 'text' => 't', 'layer' => 'shared']], $result['memoryEntries']);
	}//end testRecallPassesTheProjectKey()


	/**
	 * Recall outside a project passes no project key.
	 *
	 * @return void
	 */
	public function testRecallOutsideAProjectPassesNone(): void {
		$this->memory->expects(self::once())
			->method('recallEntries')
			->with('agent-1', 'alice', 'q', 20, null)
			->willReturn(['memoryEntries' => [], 'userProfileEntries' => []]);
		$this->memory->method('recallSessions')->willReturn([]);

		$this->call('recallMemory', ['agentId' => 'agent-1', 'query' => 'q']);
	}//end testRecallOutsideAProjectPassesNone()


	/**
	 * Forget hands the project key to the service.
	 *
	 * @return void
	 */
	public function testForgetPassesTheProjectKey(): void {
		$this->memory->expects(self::once())
			->method('forgetEntry')
			->with('agent-1', 'alice', 'e-1', 'omgevingsvisie-2040')
			->willReturn(['found' => true, 'scope' => 'memory']);

		$result = $this->call('forgetMemory', ['agentId' => 'agent-1', 'id' => 'e-1', 'projectKey' => 'omgevingsvisie-2040']);

		self::assertSame(['found' => true, 'scope' => 'memory'], $result);
	}//end testForgetPassesTheProjectKey()


	/**
	 * The project key is read only from the injected argument, trimmed.
	 *
	 * @return void
	 */
	public function testProjectKeyOf(): void {
		self::assertSame('p-1', AgentMemoryTools::projectKeyOf(arguments: ['projectKey' => ' p-1 ']));
		self::assertNull(AgentMemoryTools::projectKeyOf(arguments: ['projectKey' => '']));
		self::assertNull(AgentMemoryTools::projectKeyOf(arguments: []));
	}//end testProjectKeyOf()
}//end class
