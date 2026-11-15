<?php

/**
 * Unit tests for GraphStore, the knowledge graph's thin persistence layer.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Graph\GraphStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GraphStore.
 *
 * @covers \OCA\Hermiq\Service\Graph\GraphStore
 * @uses   \OCA\Hermiq\Service\Engine\SanitizesForSaveTrait
 */
class GraphStoreTest extends TestCase {

	/**
	 * An entity with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$e = new ObjectEntity();
		$e->setUuid($uuid);
		$e->setObject(['label' => $uuid]);
		return $e;
	}

	/**
	 * Create saves into the hermiq register and returns the new uuid.
	 *
	 * @return void
	 */
	public function testCreateReturnsTheNewUuid(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('saveObject')
			->with(['label' => 'Acme'], [], 'hermiq', 'graphentity')
			->willReturn($this->entity('e-1'));

		self::assertSame('e-1', (new GraphStore($objects, $this->createMock(LoggerInterface::class)))->create('graphentity', ['label' => 'Acme']));
	}

	/**
	 * Update drops identity members, sanitises the payload and saves without RBAC under the uuid.
	 *
	 * @return void
	 */
	public function testUpdateSavesASanitisedPayloadUnderTheUuid(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('saveObject')->with(
			['label' => 'Acme', 'seenAt' => (new \DateTime('2026-01-02 03:04:05'))->format('c'), 'meta' => null],
			[],
			'hermiq',
			'graphrelation',
			'r-1',
			false
		)->willReturn($this->entity('r-1'));

		(new GraphStore($objects, $this->createMock(LoggerInterface::class)))->update('graphrelation', 'r-1', [
			'@self' => ['id' => 'r-1'],
			'id' => 4,
			'label' => 'Acme',
			'seenAt' => '2026-01-02 03:04:05',
			'meta' => ['a' => null],
		]);
	}

	/**
	 * Entity reads one graph entity without RBAC.
	 *
	 * @return void
	 */
	public function testEntityReadsTheEntity(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('find')
			->with('e-1', [], false, 'hermiq', 'graphentity', false)
			->willReturn($this->entity('e-1'));

		self::assertSame('e-1', (new GraphStore($objects, $this->createMock(LoggerInterface::class)))->entity('e-1')?->getUuid());
	}

	/**
	 * An unreadable entity is null and logged at debug.
	 *
	 * @return void
	 */
	public function testAnUnreadableEntityIsNull(): void {
		$objects = $this->createMock(ObjectService::class);
		$failure = new RuntimeException('gone');
		$objects->method('find')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')
			->with('Hermiq graph: an entity could not be read', ['uuid' => 'e-9', 'exception' => $failure]);

		self::assertNull((new GraphStore($objects, $logger))->entity('e-9'));
	}

	/**
	 * Find queries the schema with filters and limit, keeping only entities.
	 *
	 * @return void
	 */
	public function testFindKeepsOnlyEntities(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$objects->expects(self::once())->method('setSchema')->with('graphrelation')->willReturnSelf();
		$objects->expects(self::once())->method('findAll')
			->with(['filters' => ['fromEntity' => 'e-1'], 'limit' => 7], false)
			->willReturn(['k' => $this->entity('r-1'), 'not an entity', $this->entity('r-2')]);

		$found = (new GraphStore($objects, $this->createMock(LoggerInterface::class)))->find('graphrelation', ['fromEntity' => 'e-1'], 7);

		self::assertSame(['r-1', 'r-2'], array_map(static fn (ObjectEntity $e): string => $e->getUuid(), $found));
		self::assertSame([0, 1], array_keys($found));
	}

	/**
	 * Find defaults to a limit of 200; a failing query is empty and logged.
	 *
	 * @return void
	 */
	public function testAFailingFindIsEmpty(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$failure = new RuntimeException('db down');
		$objects->expects(self::once())->method('findAll')
			->with(['filters' => [], 'limit' => 200], false)
			->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('debug')
			->with('Hermiq graph: a graph query failed; treated as empty', ['schema' => 'graphentity', 'exception' => $failure]);

		self::assertSame([], (new GraphStore($objects, $logger))->find('graphentity', []));
	}

	/**
	 * First asks for one match and returns it, or null.
	 *
	 * @return void
	 */
	public function testFirst(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->expects(self::exactly(2))->method('findAll')
			->with(['filters' => ['labelKey' => 'acme'], 'limit' => 1], false)
			->willReturnOnConsecutiveCalls([$this->entity('e-1')], []);
		$store = new GraphStore($objects, $this->createMock(LoggerInterface::class));

		self::assertSame('e-1', $store->first('graphentity', ['labelKey' => 'acme'])?->getUuid());
		self::assertNull($store->first('graphentity', ['labelKey' => 'acme']));
	}
}
