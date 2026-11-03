<?php

/**
 * Tests for the repair step that gives every tool-access request the system owner.
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
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\AssignToolAccessRequestsToSystem;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Requests raised before requests became system-owned are handed to the system.
 *
 * @covers \OCA\Hermiq\Repair\AssignToolAccessRequestsToSystem
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
class AssignToolAccessRequestsToSystemTest extends TestCase {

	/**
	 * The stored requests, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $requests = [];

	/**
	 * The uuids the mapper persisted, with their owner at that moment.
	 *
	 * @var array<int, array{string, string|null}>
	 */
	private array $updated = [];

	/**
	 * The findAll() calls: config, _rbac, _multitenancy.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * Store three requests: two owned by their requester, one by the system.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->requests = [];
		$this->updated = [];
		$this->reads = [];
		foreach (['req-alice' => 'alice', 'req-bob' => 'bob', 'req-system' => '__system__'] as $uuid => $owner) {
			$request = new ObjectEntity();
			$request->setUuid($uuid);
			$request->setOwner($owner);
			$request->setObject(['agentId' => 'agent-1', 'toolId' => 'hermiq.sendMail', 'status' => 'refused']);
			$this->requests[$uuid] = $request;
		}

	}//end setUp()

	/**
	 * The step over a container that serves OpenRegister, or fails for one class.
	 *
	 * @param string|null $missing A class the container cannot provide.
	 *
	 * @return AssignToolAccessRequestsToSystem
	 */
	private function step(?string $missing = null): AssignToolAccessRequestsToSystem {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				return array_values($this->requests);
			}
		);

		$mapper = new class($this->updated) {
			/**
			 * Keep a reference to the test's record of updates.
			 *
			 * @param array<int, array{string, string|null}> $updated The record.
			 */
			public function __construct(private array &$updated) {
			}

			/**
			 * Persist an entity.
			 *
			 * @param ObjectEntity $entity The entity.
			 *
			 * @return ObjectEntity
			 */
			public function update(ObjectEntity $entity): ObjectEntity {
				$this->updated[] = [(string)$entity->getUuid(), $entity->getOwner()];
				return $entity;
			}
		};

		$services = [
			ObjectService::class => $objects,
			OrganisationService::class => new OrganisationService(),
			'OCA\\OpenRegister\\Db\\MagicMapper' => $mapper,
		];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services, $missing): object {
				if ($id === $missing || isset($services[$id]) === false) {
					throw new RuntimeException('not available: ' . $id);
				}

				return $services[$id];
			}
		);

		return new AssignToolAccessRequestsToSystem(container: $container, logger: new NullLogger());

	}//end step()

	/**
	 * A request its requester still owns is handed to the system; one the
	 * system already owns is left alone, and every request is read unfiltered.
	 *
	 * @return void
	 */
	public function testRequesterOwnedRequestsBecomeSystemOwned(): void {
		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [['req-alice', '__system__'], ['req-bob', '__system__']], actual: $this->updated);
		$this->assertSame(expected: '__system__', actual: $this->requests['req-alice']->getOwner());
		$this->assertFalse(condition: $this->reads[0][1], message: 'the step reads every request, not those the run identity may see');
		$this->assertFalse(condition: $this->reads[0][2], message: 'the step reads every organisation');

	}//end testRequesterOwnedRequestsBecomeSystemOwned()

	/**
	 * A second run changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$this->step()->run($this->createMock(originalClassName: IOutput::class));
		$this->updated = [];
		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $this->updated);

	}//end testASecondRunChangesNothing()

	/**
	 * Without OpenRegister the step skips with a warning and never throws.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStepSkips(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step(missing: 'OCA\\OpenRegister\\Db\\MagicMapper')->run($output);

		$this->assertSame(expected: [], actual: $this->updated);

	}//end testWithoutOpenRegisterTheStepSkips()
}//end class
