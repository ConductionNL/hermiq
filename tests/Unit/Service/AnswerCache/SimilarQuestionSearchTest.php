<?php

/**
 * Unit tests for SimilarQuestionSearch: the guarded seam to OpenRegister's vector facade.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AnswerCache
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
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AnswerCache;

use OCA\Hermiq\Service\AnswerCache\SimilarQuestionSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The seam over fake facades.
 */
#[CoversClass(SimilarQuestionSearch::class)]
class SimilarQuestionSearchTest extends TestCase {

	/**
	 * An available facade is asked for the nearest passages, and only rows come back.
	 *
	 * @return void
	 */
	public function testAnAvailableFacadeIsSearchedSemantically(): void {
		$facade = new class {
			/** @var array<int, mixed> */
			public array $calls = [];

			public function isAvailable(): bool {
				return true;
			}

			public function searchSemantic(string $query, int $limit = 10, array $views = []): array {
				$this->calls[] = [$query, $limit, $views];
				return [['entity_id' => 'entry-1', 'entity_type' => 'object', 'similarity' => 0.97], 'not a row'];
			}
		};
		$search = $this->search(facade: $facade);

		$this->assertTrue($search->isAvailable());
		$this->assertSame(
			[['entity_id' => 'entry-1', 'entity_type' => 'object', 'similarity' => 0.97]],
			$search->nearest(question: 'Wanneer halen jullie oud papier op?', limit: 5)
		);
		$this->assertSame([['Wanneer halen jullie oud papier op?', 5, []]], $facade->calls);
	}//end testAnAvailableFacadeIsSearchedSemantically()

	/**
	 * No facade class, a facade that cannot answer, one without semantic search
	 * or one that cannot be resolved: unavailable, and nothing is searched.
	 *
	 * @return void
	 */
	public function testNoUsableFacadeSearchesNothing(): void {
		$unavailable = new class {
			public int $searches = 0;

			public function isAvailable(): bool {
				return false;
			}

			public function searchSemantic(string $query, int $limit = 10, array $views = []): array {
				$this->searches++;
				return [['entity_id' => 1]];
			}
		};
		$noSemantic = new class {
			public function isAvailable(): bool {
				return true;
			}
		};
		$throwing = new class {
			public function isAvailable(): bool {
				throw new RuntimeException('settings unreadable');
			}

			public function searchSemantic(string $query, int $limit = 10, array $views = []): array {
				return [['entity_id' => 1]];
			}
		};

		$absent = new SimilarQuestionSearch($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), 'OCA\\Nowhere\\Facade');
		$this->assertFalse($absent->isAvailable());
		$this->assertSame([], $absent->nearest(question: 'q', limit: 5));

		$off = $this->search(facade: $unavailable);
		$this->assertFalse($off->isAvailable());
		$this->assertSame([], $off->nearest(question: 'q', limit: 5));
		$this->assertSame(0, $unavailable->searches);

		$this->assertFalse($this->search(facade: $noSemantic)->isAvailable());
		$this->assertFalse($this->search(facade: $throwing)->isAvailable());

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$unresolved = new SimilarQuestionSearch($container, $this->createMock(LoggerInterface::class), get_class($noSemantic));
		$this->assertFalse($unresolved->isAvailable());
	}//end testNoUsableFacadeSearchesNothing()

	/**
	 * A failing search is logged and gives no rows; empty input asks nothing.
	 *
	 * @return void
	 */
	public function testAFailingSearchAndEmptyInputGiveNoRows(): void {
		$facade = new class {
			public int $searches = 0;

			public function isAvailable(): bool {
				return true;
			}

			public function searchSemantic(string $query, int $limit = 10, array $views = []): array {
				$this->searches++;
				throw new RuntimeException('embedding provider down');
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('embedding provider down'));
		$search = $this->search(facade: $facade, logger: $logger);

		$this->assertSame([], $search->nearest(question: '  ', limit: 5));
		$this->assertSame([], $search->nearest(question: 'q', limit: 0));
		$this->assertSame(0, $facade->searches);
		$this->assertSame([], $search->nearest(question: 'q', limit: 5));
		$this->assertSame(1, $facade->searches);
	}//end testAFailingSearchAndEmptyInputGiveNoRows()

	/**
	 * The seam over a fake facade resolved from a container.
	 *
	 * @param object               $facade The fake facade.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return SimilarQuestionSearch
	 */
	private function search(object $facade, ?LoggerInterface $logger = null): SimilarQuestionSearch {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(get_class($facade))->willReturn($facade);

		return new SimilarQuestionSearch($container, ($logger ?? $this->createMock(LoggerInterface::class)), get_class($facade));
	}//end search()
}//end class
