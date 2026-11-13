<?php

/**
 * Unit tests for ScopeExistingMemories (memory-project-scopes).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
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

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\ScopeExistingMemories;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every existing Memory becomes the shared memory, once.
 */
#[CoversClass(ScopeExistingMemories::class)]
class ScopeExistingMemoriesTest extends TestCase {

	/**
	 * The stored Memory data, by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $stored = [];

	/**
	 * Every patch: [uuid, data, rbac, multitenancy].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $patches = [];

	/**
	 * Whether a patch is lost (the write does not take).
	 *
	 * @var bool
	 */
	private bool $patchesAreLost = false;


	/**
	 * The step over an ObjectService holding the stored memories, or none.
	 *
	 * @param bool $withOpenRegister Whether OpenRegister is available.
	 *
	 * @return ScopeExistingMemories
	 */
	private function step(bool $withOpenRegister = true): ScopeExistingMemories {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $work): mixed => $work());
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$page = array_slice($this->stored, (int)($config['offset'] ?? 0), (int)$config['limit'], true);
				$out  = [];
				foreach ($page as $uuid => $data) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$entity->setObject($data);
					$out[] = $entity;
				}

				return $out;
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->patches[] = [$objectId, $data, $_rbac, $_multitenancy];
				if ($this->patchesAreLost === false) {
					$this->stored[$objectId] = $data + $this->stored[$objectId];
				}

				return new ObjectEntity();
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $withOpenRegister): object {
				if ($withOpenRegister === false) {
					throw new RuntimeException('no OpenRegister');
				}

				return $objects;
			}
		);

		return new ScopeExistingMemories($container, $this->createMock(LoggerInterface::class));
	}//end step()


	/**
	 * Unscoped memories become shared across tenants; scoped ones are left alone.
	 *
	 * @return void
	 */
	public function testUnscopedMemoriesBecomeShared(): void {
		$this->stored = [
			'm1' => ['agentId' => 'a1', 'entries' => [['id' => 'e', 'text' => 'x']]],
			'm2' => ['agentId' => 'a2', 'scope' => 'project', 'projectKey' => 'p'],
			'm3' => ['agentId' => 'a3', 'scope' => ''],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([['m1', ['scope' => 'shared'], false, false], ['m3', ['scope' => 'shared'], false, false]], $this->patches);
		$this->assertSame('shared', $this->stored['m1']['scope']);
		$this->assertSame([['id' => 'e', 'text' => 'x']], $this->stored['m1']['entries']);
		$this->assertSame('project', $this->stored['m2']['scope']);
	}//end testUnscopedMemoriesBecomeShared()


	/**
	 * A rerun changes nothing.
	 *
	 * @return void
	 */
	public function testARerunChangesNothing(): void {
		$this->stored = ['m1' => ['agentId' => 'a1']];
		$this->step()->run($this->createMock(IOutput::class));
		$this->patches = [];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->patches);
	}//end testARerunChangesNothing()


	/**
	 * Memories beyond the first page are reached too.
	 *
	 * @return void
	 */
	public function testEveryPageIsRead(): void {
		for ($i = 0; $i < (ScopeExistingMemories::PAGE + 3); $i++) {
			$this->stored['m' . $i] = ['agentId' => 'a' . $i];
		}

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(ScopeExistingMemories::PAGE + 3, $this->patches);
	}//end testEveryPageIsRead()


	/**
	 * A memory still unscoped after the pass fails the step loudly, by count.
	 *
	 * @return void
	 */
	public function testAMemoryLeftUnscopedFailsTheStep(): void {
		$this->stored = ['m1' => ['agentId' => 'a1'], 'm2' => ['agentId' => 'a2']];
		$this->patchesAreLost = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('left 2 agent memory object(s) without a scope');
		$this->step()->run($this->createMock(IOutput::class));
	}//end testAMemoryLeftUnscopedFailsTheStep()


	/**
	 * Without OpenRegister the step warns and does nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepIsSkipped(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step(withOpenRegister: false)->run($output);

		$this->assertSame([], $this->patches);
	}//end testWithoutOpenRegisterTheStepIsSkipped()
}//end class
