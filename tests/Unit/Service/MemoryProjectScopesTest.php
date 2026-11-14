<?php

/**
 * Unit tests for the project layers of an agent's memory (memory-project-scopes).
 *
 * Three Memory objects for one agent: the shared memory and two projects. The
 * ObjectService double answers findAll with the stored objects whose data
 * equals every filter, as OpenRegister's equality filters do, so the tests
 * show which objects each read can reach.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-recall-in-a-project-reads-the-project-and-the-shared-memory-only-req-pmem-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\MemoryLookup;
use OCA\Hermiq\Service\MemoryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The shared memory and one memory per project.
 *
 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-an-agent-has-one-shared-memory-and-a-memory-per-project-req-pmem-001
 */
#[CoversClass(MemoryService::class)]
#[UsesClass(MemoryLookup::class)]
#[UsesClass(RedactionService::class)]
class MemoryProjectScopesTest extends TestCase {

	/**
	 * The stored Memory objects.
	 *
	 * @var array<int,ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * Every object saved through the service.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = [
			$this->memory('mem-shared', ['agentId' => 'agent-1', 'scope' => 'shared'], 'Beleidsstukken gebruiken B1-taalniveau.'),
			$this->memory('mem-visie', ['agentId' => 'agent-1', 'scope' => 'project', 'projectKey' => 'omgevingsvisie-2040'], 'De raad behandelt het ontwerp op 14 november 2026.'),
			$this->memory('mem-wagen', ['agentId' => 'agent-1', 'scope' => 'project', 'projectKey' => 'aanbesteding-wagenpark'], 'Het perceel elektrische bestelbussen is gegund.'),
			$this->memory('mem-other', ['agentId' => 'agent-2', 'scope' => 'shared'], 'Een ander agent.'),
		];
	}//end setUp()


	/**
	 * A Memory object holding one entry.
	 *
	 * @param string              $uuid  Its uuid.
	 * @param array<string,mixed> $layer Its agent and layer.
	 * @param string              $text  Its one entry.
	 *
	 * @return ObjectEntity
	 */
	private function memory(string $uuid, array $layer, string $text): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($layer + ['entries' => [['id' => $uuid . '-e1', 'text' => $text, 'createdAt' => '2026-10-01T00:00:00+00:00']]]);
		return $entity;
	}//end memory()


	/**
	 * The service over an ObjectService that filters the stored objects by equality.
	 *
	 * @return MemoryService
	 */
	private function service(): MemoryService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$found = [];
				foreach ($this->stored as $object) {
					$data = $object->getObject();
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($data[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = $object;
				}

				return array_slice($found, 0, (int)($config['limit'] ?? 100));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('mem-new');
				$entity->setObject($object);
				return $entity;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		return new MemoryService($objects, new RedactionService($config), $this->createMock(IUserSession::class));
	}//end service()


	/**
	 * Outside a project the shared memory is the one returned, never a project's.
	 *
	 * @return void
	 */
	public function testWithoutAProjectGetMemoryReturnsTheSharedObject(): void {
		$this->assertSame('mem-shared', $this->service()->getMemory(agentId: 'agent-1')->getUuid());
		$this->assertSame('mem-shared', $this->service()->getMemory(agentId: 'agent-1', projectKey: '  ')->getUuid());
	}//end testWithoutAProjectGetMemoryReturnsTheSharedObject()


	/**
	 * With a project key the project's own memory is returned.
	 *
	 * @return void
	 */
	public function testWithAProjectGetMemoryReturnsThatProjectsObject(): void {
		$this->assertSame('mem-wagen', $this->service()->getMemory(agentId: 'agent-1', projectKey: 'aanbesteding-wagenpark')->getUuid());
		$this->assertSame([], $this->saved);
	}//end testWithAProjectGetMemoryReturnsThatProjectsObject()


	/**
	 * A project without a memory gets a new one, scoped to it.
	 *
	 * @return void
	 */
	public function testANewProjectGetsItsOwnMemory(): void {
		$this->service()->getMemory(agentId: 'agent-1', projectKey: 'begroting-2027');

		$this->assertCount(1, $this->saved);
		$this->assertSame('project', $this->saved[0]['scope']);
		$this->assertSame('begroting-2027', $this->saved[0]['projectKey']);
		$this->assertSame('agent-1', $this->saved[0]['agentId']);
		$this->assertSame([], $this->saved[0]['entries']);
	}//end testANewProjectGetsItsOwnMemory()


	/**
	 * An agent whose memory has no shared object yet gets one marked shared.
	 *
	 * @return void
	 */
	public function testAMissingSharedMemoryIsCreatedMarkedShared(): void {
		$this->stored = [$this->stored[1]];
		$this->service()->getMemory(agentId: 'agent-1');

		$this->assertSame('shared', $this->saved[0]['scope']);
		$this->assertArrayNotHasKey('projectKey', $this->saved[0]);
	}//end testAMissingSharedMemoryIsCreatedMarkedShared()


	/**
	 * Recall in a project reads that project and the shared memory, each labelled, and no other project.
	 *
	 * @return void
	 */
	public function testRecallInAProjectReadsThatProjectAndSharedOnly(): void {
		$entries = $this->service()->recallEntries(agentId: 'agent-1', subjectUid: null, query: '', projectKey: 'omgevingsvisie-2040')['memoryEntries'];

		$this->assertSame(
			[['mem-visie-e1', 'project'], ['mem-shared-e1', 'shared']],
			array_map(static fn (array $entry): array => [$entry['id'], $entry['layer']], $entries)
		);
	}//end testRecallInAProjectReadsThatProjectAndSharedOnly()


	/**
	 * Another project's fact stays out, even when it is the only match.
	 *
	 * @return void
	 */
	public function testAnotherProjectsFactStaysOut(): void {
		$entries = $this->service()->recallEntries(agentId: 'agent-1', subjectUid: null, query: 'bestelbussen', projectKey: 'omgevingsvisie-2040')['memoryEntries'];

		$this->assertSame([], $entries);
	}//end testAnotherProjectsFactStaysOut()


	/**
	 * Outside a project only the shared memory is searched.
	 *
	 * @return void
	 */
	public function testRecallOutsideAProjectReadsSharedOnly(): void {
		$entries = $this->service()->recallEntries(agentId: 'agent-1', subjectUid: null, query: '')['memoryEntries'];

		$this->assertSame(['mem-shared-e1'], array_column($entries, 'id'));
		$this->assertSame(['shared'], array_column($entries, 'layer'));
	}//end testRecallOutsideAProjectReadsSharedOnly()


	/**
	 * The layer filters name the shared memory by its explicit scope.
	 *
	 * @return void
	 */
	public function testLayerFilters(): void {
		$this->assertSame(['agentId' => 'a', 'scope' => 'shared'], MemoryService::layerFilters(agentId: 'a', projectKey: null));
		$this->assertSame(['agentId' => 'a', 'scope' => 'project', 'projectKey' => 'p'], MemoryService::layerFilters(agentId: 'a', projectKey: ' p '));
	}//end testLayerFilters()

	/**
	 * A fact remembered in a project lands in the project memory; the shared memory is unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
	 */
	public function testAppendInAProjectWritesTheProjectMemory(): void {
		$this->service()->appendMemoryEntry(agentId: 'agent-1', text: 'De raad behandelt het ontwerp op 14 november 2026.', projectKey: 'omgevingsvisie-2040');

		$this->assertCount(1, $this->saved);
		$this->assertSame('project', $this->saved[0]['scope']);
		$this->assertSame('omgevingsvisie-2040', $this->saved[0]['projectKey']);
		$this->assertCount(2, $this->saved[0]['entries']);
	}//end testAppendInAProjectWritesTheProjectMemory()

	/**
	 * Without a project key the fact goes to the shared memory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
	 */
	public function testAppendWithoutAProjectWritesTheSharedMemory(): void {
		$this->service()->appendMemoryEntry(agentId: 'agent-1', text: 'Een naamconventie.');

		$this->assertSame('shared', $this->saved[0]['scope']);
		$this->assertArrayNotHasKey('projectKey', $this->saved[0]);
	}//end testAppendWithoutAProjectWritesTheSharedMemory()

	/**
	 * Forget in a project finds the entry in the project first, then in the shared memory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
	 */
	public function testForgetInAProjectSearchesTheProjectThenShared(): void {
		$service = $this->service();

		$this->assertSame(['found' => true, 'scope' => 'memory'], $service->forgetEntry(agentId: 'agent-1', subjectUid: null, entryId: 'mem-visie-e1', projectKey: 'omgevingsvisie-2040'));
		$this->assertSame('omgevingsvisie-2040', $this->saved[0]['projectKey']);

		$this->assertSame(['found' => true, 'scope' => 'memory'], $service->forgetEntry(agentId: 'agent-1', subjectUid: null, entryId: 'mem-shared-e1', projectKey: 'omgevingsvisie-2040'));
		$this->assertSame('shared', $this->saved[1]['scope']);
	}//end testForgetInAProjectSearchesTheProjectThenShared()

	/**
	 * Forget never reaches another project's memory, nor a project's from outside one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/memory-project-scopes/specs/agent-memory/spec.md#requirement-writes-go-to-the-project-memory-unless-marked-shared-req-pmem-003
	 */
	public function testForgetNeverReachesAnotherProject(): void {
		$service = $this->service();

		$this->assertSame(['found' => false, 'scope' => null], $service->forgetEntry(agentId: 'agent-1', subjectUid: null, entryId: 'mem-wagen-e1', projectKey: 'omgevingsvisie-2040'));
		$this->assertSame(['found' => false, 'scope' => null], $service->forgetEntry(agentId: 'agent-1', subjectUid: null, entryId: 'mem-visie-e1'));
		$this->assertSame([], $this->saved);
	}//end testForgetNeverReachesAnotherProject()
}//end class
