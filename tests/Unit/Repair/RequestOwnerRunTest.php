<?php

/**
 * Unit tests for RequestOwnerRun, the hand-over loop behind
 * AssignToolAccessRequestsToSystem.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\RequestOwnerRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for RequestOwnerRun.
 *
 * @covers \OCA\Hermiq\Repair\RequestOwnerRun
 */
class RequestOwnerRunTest extends TestCase {

	/**
	 * A tool-access request owned by $owner.
	 *
	 * @param string      $uuid  The request uuid.
	 * @param string|null $owner The current owner.
	 *
	 * @return ObjectEntity
	 */
	private function request(string $uuid, ?string $owner): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		return $entity;
	}//end request()

	/**
	 * A mapper double recording updates and failing for the listed uuids.
	 *
	 * @param array<int, string> $failUuids Uuids whose update throws.
	 *
	 * @return object
	 */
	private function mapper(array $failUuids = []): object {
		return new class($failUuids) {
			/**
			 * @var array<int, array{uuid: string|null, owner: string|null}>
			 */
			public array $updated = [];

			/**
			 * @param array<int, string> $failUuids Failing uuids.
			 */
			public function __construct(private array $failUuids) {
			}

			public function update(ObjectEntity $entity): ObjectEntity {
				if (in_array($entity->getUuid(), $this->failUuids, true) === true) {
					throw new RuntimeException('row locked');
				}

				$this->updated[] = ['uuid' => $entity->getUuid(), 'owner' => $entity->getOwner()];
				return $entity;
			}
		};
	}//end mapper()

	/**
	 * Requests not yet owned by the system are handed over; system-owned rows
	 * and non-entities are skipped and not counted.
	 *
	 * @return void
	 */
	public function testHandsOverOnlyRequestsTheSystemDoesNotOwn(): void {
		$mapper = $this->mapper();
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');

		$run = new RequestOwnerRun(
			requests: [
				$this->request('r-1', 'alice'),
				$this->request('r-2', '__system__'),
				['uuid' => 'not-an-entity'],
				$this->request('r-3', null),
			],
			system: '__system__',
			mapper: $mapper,
			output: $output
		);

		$this->assertSame(2, $run->handOverAll());
		$this->assertSame(
			[
				['uuid' => 'r-1', 'owner' => '__system__'],
				['uuid' => 'r-3', 'owner' => '__system__'],
			],
			$mapper->updated
		);
	}//end testHandsOverOnlyRequestsTheSystemDoesNotOwn()

	/**
	 * A failed update is warned about, not counted, and does not stop the loop.
	 *
	 * @return void
	 */
	public function testAFailedUpdateIsWarnedAndNotCounted(): void {
		$mapper = $this->mapper(['r-1']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Could not hand tool-access request r-1 to the system: row locked');

		$run = new RequestOwnerRun(
			requests: new \ArrayIterator([$this->request('r-1', 'alice'), $this->request('r-2', 'bob')]),
			system: '__system__',
			mapper: $mapper,
			output: $output
		);

		$this->assertSame(1, $run->handOverAll());
		$this->assertSame([['uuid' => 'r-2', 'owner' => '__system__']], $mapper->updated);
	}//end testAFailedUpdateIsWarnedAndNotCounted()

	/**
	 * Nothing to hand over counts zero.
	 *
	 * @return void
	 */
	public function testAnEmptySetMovesNothing(): void {
		$mapper = $this->mapper();
		$run = new RequestOwnerRun(requests: [], system: '__system__', mapper: $mapper, output: $this->createMock(IOutput::class));

		$this->assertSame(0, $run->handOverAll());
		$this->assertSame([], $mapper->updated);
	}//end testAnEmptySetMovesNothing()
}//end class
