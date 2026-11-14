<?php

/**
 * Hermiq DelegatedRunsDouble
 *
 * An OpenRegister object service double that keeps DelegatedRun records in
 * memory, and a fake clock, for the parallel delegation tests.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Delegation
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

namespace OCA\Hermiq\Tests\Unit\Service\Delegation;

use OCA\Hermiq\Service\Delegation\PollClock;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Builds the doubles a test case needs.
 */
final class DelegatedRunsDouble {

	/**
	 * The records by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $records = [];

	/**
	 * Jobs queued so far (counted by the tests' job list).
	 *
	 * @var int
	 */
	public int $jobs = 0;

	/**
	 * Called after every save with the saved record, to let a test change the world mid-run.
	 *
	 * @var callable|null
	 */
	public $afterSave = null;

	/**
	 * The fake time in seconds.
	 *
	 * @var int
	 */
	public int $now = 1000;

	/**
	 * Called on every pause with the fake time, after it moved on.
	 *
	 * @var callable|null
	 */
	public $onWait = null;

	/**
	 * An object service over $records.
	 *
	 * @param TestCase $test The test building it.
	 *
	 * @return ObjectService&MockObject
	 */
	public function objectService(TestCase $test): ObjectService {
		$builder = new \PHPUnit\Framework\MockObject\MockBuilder($test, ObjectService::class);
		$service = $builder->disableOriginalConstructor()->onlyMethods(['find', 'saveObject'])->getMock();
		$service->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if (isset($this->records[(string)$id]) === false) {
					return null;
				}

				return $this->entity(id: (string)$id);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$id                 = ($uuid ?? 'part-'.(count($this->records) + 1));
				$this->records[$id] = $object;
				if ($this->afterSave !== null) {
					($this->afterSave)($id, $object);
				}

				return $this->entity(id: $id);
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A clock on $now whose pause moves it on by one second.
	 *
	 * @param TestCase $test The test building it.
	 *
	 * @return PollClock&MockObject
	 */
	public function clock(TestCase $test): PollClock {
		$builder = new \PHPUnit\Framework\MockObject\MockBuilder($test, PollClock::class);
		$clock   = $builder->onlyMethods(['now', 'wait'])->getMock();
		$clock->method('now')->willReturnCallback(fn (): int => $this->now);
		$clock->method('wait')->willReturnCallback(
			function (): void {
				$this->now++;
				if ($this->onWait !== null) {
					($this->onWait)($this->now);
				}
			}
		);

		return $clock;
	}//end clock()

	/**
	 * The stored record as an entity.
	 *
	 * @param string $id The id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $id): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setObject($this->records[$id]);

		return $entity;
	}//end entity()
}//end class
