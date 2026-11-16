<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedKnowledgeBases;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The demo knowledge base: dev mode only, once, attached to the demo agent when it exists.
 */
#[CoversClass(SeedKnowledgeBases::class)]
class SeedKnowledgeBasesTest extends TestCase {

	/**
	 * An ObjectService double that serves rows per schema and records saves.
	 *
	 * @param array<string, array<int, ObjectEntity>> $bySchema Schema slug → rows.
	 */
	private function objectService(array $bySchema): ObjectService {
		return new class($bySchema) extends ObjectService {
			private string $schema = '';

			/** @var array<int, array{schema: string, object: array, uuid: ?string, elevated: bool}> */
			public array $saved = [];

			private bool $elevated = false;

			public function __construct(private array $bySchema) {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->elevated = true;
				try {
					return $operation();
				} finally {
					$this->elevated = false;
				}
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				$this->schema = (string)$schema;
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->bySchema[$this->schema] ?? []);
			}

			public function saveObject(
				array|ObjectEntity $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntity {
				$payload = is_array($object) ? $object : $object->getObject();
				$this->saved[] = ['schema' => (string)$options->target->schema, 'object' => $payload, 'uuid' => $options->uuid, 'elevated' => $this->elevated];
				$entity = new ObjectEntity();
				$entity->setUuid('kb-new');
				$entity->setObject($payload);
				return $entity;
			}
		};
	}//end objectService()

	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	private function step(?ObjectService $objects, bool $debug): SeedKnowledgeBases {
		$container = $this->createMock(ContainerInterface::class);
		if ($objects === null) {
			$container->expects($this->never())->method('get');
		} else {
			$container->method('get')->with(ObjectService::class)->willReturn($objects);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->with('debug', false)->willReturn($debug);

		return new SeedKnowledgeBases(container: $container, config: $config, logger: $this->createMock(LoggerInterface::class));
	}//end step()

	public function testWithDevModeOffNothingIsTouched(): void {
		$this->step(objects: null, debug: false)->run($this->createMock(IOutput::class));
		$this->addToAssertionCount(1);
	}//end testWithDevModeOffNothingIsTouched()

	public function testTheKnowledgeBaseIsSeededAndAttachedToTheDemoAgent(): void {
		$objects = $this->objectService([
			'agent' => [
				$this->entity('other', ['name' => 'Inkoopassistent 2']),
				$this->entity('agent-1', ['name' => 'Inkoopassistent', 'knowledgeBaseRefs' => ['kb-old'], '@self' => ['id' => 'x']]),
			],
		]);

		$this->step(objects: $objects, debug: true)->run($this->createMock(IOutput::class));

		self::assertCount(2, $objects->saved);
		self::assertSame('agentknowledgebase', $objects->saved[0]['schema']);
		self::assertSame(SeedKnowledgeBases::knowledgeBase(), $objects->saved[0]['object']);
		self::assertTrue($objects->saved[0]['elevated']);
		self::assertSame('agent', $objects->saved[1]['schema']);
		self::assertSame('agent-1', $objects->saved[1]['uuid']);
		self::assertSame(['name' => 'Inkoopassistent', 'knowledgeBaseRefs' => ['kb-old', 'kb-new']], $objects->saved[1]['object']);
	}//end testTheKnowledgeBaseIsSeededAndAttachedToTheDemoAgent()

	public function testAnExistingKnowledgeBaseIsLeftAlone(): void {
		$objects = $this->objectService(['agentknowledgebase' => [$this->entity('kb-1', ['name' => 'Inkoopbeleid'])]]);

		$this->step(objects: $objects, debug: true)->run($this->createMock(IOutput::class));

		self::assertSame([], $objects->saved);
	}//end testAnExistingKnowledgeBaseIsLeftAlone()

	public function testWithoutTheDemoAgentOnlyTheKnowledgeBaseIsWritten(): void {
		$objects = $this->objectService([]);

		$this->step(objects: $objects, debug: true)->run($this->createMock(IOutput::class));

		self::assertSame(['agentknowledgebase'], array_column($objects->saved, 'schema'));
	}//end testWithoutTheDemoAgentOnlyTheKnowledgeBaseIsWritten()

	public function testTheSeedNamesTheIntegriqSourceAndTheTeamFolder(): void {
		$seed = SeedKnowledgeBases::knowledgeBase();

		self::assertSame('Team Inkoop/Kennisbank', $seed['folderPath']);
		self::assertSame(['Team Inkoop/Kennisbank', 'Integraties/SharePoint/Beleidsdocumenten'], array_column($seed['sources'], 'path'));
		self::assertSame('Synced by integriq from SharePoint', $seed['sources'][1]['label']);
	}//end testTheSeedNamesTheIntegriqSourceAndTheTeamFolder()
}//end class
