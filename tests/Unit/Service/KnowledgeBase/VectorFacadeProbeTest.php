<?php

/**
 * Tests for VectorFacadeProbe: a knowledge base is searched only through a file scope.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\KnowledgeBase
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md#task-4-the-file-scope-and-indexing-state-on-the-facade
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\KnowledgeBase;

use OCA\Hermiq\Service\KnowledgeBase\VectorFacadeProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The probe over fake facades of each shape.
 */
#[CoversClass(VectorFacadeProbe::class)]
class VectorFacadeProbeTest extends TestCase {

	/**
	 * A facade with the dedicated file-scoped method is searched through it.
	 *
	 * @return void
	 */
	public function testAFacadeWithTheFileScopedMethodIsSearchedThroughIt(): void {
		$facade = new class {
			/** @var array<int, mixed> */
			public array $calls = [];

			public function isAvailable(): bool {
				return true;
			}

			public function searchHybrid(string $query, int $limit = 10, array $views = []): array {
				throw new RuntimeException('the unscoped search must not be called');
			}

			public function searchHybridInFiles(string $query, array $fileIds, int $limit): array {
				$this->calls[] = [$query, $fileIds, $limit];
				return [['entity_id' => 7, 'chunk_text' => 'Betaling binnen 30 dagen'], 'not a row'];
			}
		};
		$probe = $this->probe(facade: $facade);

		$this->assertTrue($probe->canSearchFiles());
		$this->assertSame([['entity_id' => 7, 'chunk_text' => 'Betaling binnen 30 dagen']], $probe->searchFiles(query: 'betalingstermijn', fileIds: [3 => 7, 9 => 8], limit: 4));
		$this->assertSame([['betalingstermijn', [7, 8], 4]], $facade->calls);
	}//end testAFacadeWithTheFileScopedMethodIsSearchedThroughIt()

	/**
	 * A facade whose searchHybrid takes `fileIds` is searched with that argument.
	 *
	 * @return void
	 */
	public function testAFacadeWithAFileIdsArgumentIsSearchedWithIt(): void {
		$facade = new class {
			/** @var array<int, mixed> */
			public array $calls = [];

			public function isAvailable(): bool {
				return true;
			}

			public function searchHybrid(string $query, int $limit = 10, array $views = [], array $fileIds = []): array {
				$this->calls[] = [$query, $limit, $views, $fileIds];
				return [['entity_id' => 5]];
			}
		};
		$probe = $this->probe(facade: $facade);

		$this->assertSame([['entity_id' => 5]], $probe->searchFiles(query: 'q', fileIds: [5], limit: 2));
		$this->assertSame([['q', 2, [], [5]]], $facade->calls);
	}//end testAFacadeWithAFileIdsArgumentIsSearchedWithIt()

	/**
	 * A facade without a file scope is not searched, and that is logged once.
	 *
	 * @return void
	 */
	public function testAFacadeWithoutAFileScopeIsNotSearchedAndLoggedOnce(): void {
		$facade = new class {
			public int $searched = 0;

			public function isAvailable(): bool {
				return true;
			}

			public function searchHybrid(string $query, int $limit = 10, array $views = []): array {
				$this->searched++;
				return [['entity_id' => 1]];
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->stringContains('has no file scope'));
		$probe = $this->probe(facade: $facade, logger: $logger);

		$this->assertFalse($probe->canSearchFiles());
		$this->assertSame([], $probe->searchFiles(query: 'q', fileIds: [1], limit: 3));
		$this->assertSame(0, $facade->searched);
	}//end testAFacadeWithoutAFileScopeIsNotSearchedAndLoggedOnce()

	/**
	 * An unavailable facade, an absent class or a failing container searches nothing.
	 *
	 * @return void
	 */
	public function testNoUsableFacadeSearchesNothing(): void {
		$unavailable = new class {
			public function isAvailable(): bool {
				return false;
			}

			public function searchHybridInFiles(string $query, array $fileIds, int $limit): array {
				return [['entity_id' => 1]];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$this->assertFalse($this->probe(facade: $unavailable)->canSearchFiles());
		$this->assertFalse((new VectorFacadeProbe($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), 'OCA\\Nowhere\\Facade'))->canSearchFiles());
		$this->assertFalse((new VectorFacadeProbe($container, $this->createMock(LoggerInterface::class), get_class($unavailable)))->canSearchFiles());
		$this->assertSame([], (new VectorFacadeProbe($container, $this->createMock(LoggerInterface::class), get_class($unavailable)))->indexStatus(fileIds: [1]));
	}//end testNoUsableFacadeSearchesNothing()

	/**
	 * A failing search and empty input give no rows.
	 *
	 * @return void
	 */
	public function testAFailingSearchAndEmptyInputGiveNoRows(): void {
		$facade = new class {
			public function isAvailable(): bool {
				return true;
			}

			public function searchHybridInFiles(string $query, array $fileIds, int $limit): array {
				throw new RuntimeException('index offline');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('index offline'));
		$probe = $this->probe(facade: $facade, logger: $logger);

		$this->assertSame([], $probe->searchFiles(query: 'q', fileIds: [1], limit: 3));
		$this->assertSame([], $probe->searchFiles(query: 'q', fileIds: [], limit: 3));
		$this->assertSame([], $probe->searchFiles(query: '  ', fileIds: [1], limit: 3));
		$this->assertSame([], $probe->searchFiles(query: 'q', fileIds: [1], limit: 0));
	}//end testAFailingSearchAndEmptyInputGiveNoRows()

	/**
	 * The indexing state keeps the four known states and drops anything else.
	 *
	 * @return void
	 */
	public function testTheIndexingStateKeepsTheKnownStates(): void {
		$facade = new class {
			public function isAvailable(): bool {
				return true;
			}

			public function indexStatus(array $fileIds): array {
				return [4 => 'indexed', 5 => 'pending', 6 => 'failed', 7 => 'unsupported', 8 => 'weird'];
			}
		};
		$without = new class {
			public function isAvailable(): bool {
				return true;
			}
		};

		$this->assertSame([4 => 'indexed', 5 => 'pending', 6 => 'failed', 7 => 'unsupported'], $this->probe(facade: $facade)->indexStatus(fileIds: [4, 5, 6, 7, 8]));
		$this->assertSame([], $this->probe(facade: $facade)->indexStatus(fileIds: []));
		$this->assertSame([], $this->probe(facade: $without)->indexStatus(fileIds: [4]));
	}//end testTheIndexingStateKeepsTheKnownStates()

	/**
	 * The probe resolving a fake facade by its class name.
	 *
	 * @param object               $facade The facade.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return VectorFacadeProbe
	 */
	private function probe(object $facade, ?LoggerInterface $logger = null): VectorFacadeProbe {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(get_class($facade))->willReturn($facade);

		return new VectorFacadeProbe($container, ($logger ?? $this->createMock(LoggerInterface::class)), get_class($facade));
	}//end probe()
}//end class
