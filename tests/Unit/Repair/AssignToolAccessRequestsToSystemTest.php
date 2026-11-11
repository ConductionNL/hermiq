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
	 * The register and schema the step set before reading, in order.
	 *
	 * @var array<int, array{string, mixed}>
	 */
	private array $context = [];

	/**
	 * Whether OpenRegister fails to resolve the register or schema.
	 *
	 * @var bool
	 */
	private bool $failRead = false;

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
		$this->context = [];
		$this->failRead = false;
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
		$objects->method('setRegister')->willReturnCallback(
			function (mixed $register) use (&$objects): ObjectService {
				$this->context[] = ['register', $register];
				return $objects;
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			function (mixed $schema) use (&$objects): ObjectService {
				$this->context[] = ['schema', $schema];
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failRead === true) {
					throw new RuntimeException('Schema toolAccessRequest not found in register hermiq');
				}

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
				if (str_starts_with((string)$entity->getUuid(), 'fail-') === true) {
					throw new RuntimeException(message: 'row locked');
				}

				$this->updated[] = [(string)$entity->getUuid(), $entity->getOwner()];
				return $entity;
			}
		};

		$organisations = $this->createMock(originalClassName: OrganisationService::class);
		$organisations->method('getSystemUserId')->willReturn('__system__');

		$services = [
			ObjectService::class => $objects,
			OrganisationService::class => $organisations,
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
		$before = array_map(static fn (ObjectEntity $request): array => $request->getObject(), $this->requests);
		$this->step()->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [['req-alice', '__system__'], ['req-bob', '__system__']], actual: $this->updated);
		$this->assertSame(expected: '__system__', actual: $this->requests['req-alice']->getOwner());
		$this->assertFalse(condition: $this->reads[0][1], message: 'the step reads every request, not those the run identity may see');
		$this->assertFalse(condition: $this->reads[0][2], message: 'the step reads every organisation');
		$this->assertSame(expected: [['register', 'hermiq'], ['schema', 'toolAccessRequest']], actual: $this->context);
		foreach ($this->requests as $uuid => $request) {
			$this->assertSame(
				expected: $before[$uuid],
				actual: $request->getObject(),
				message: 'only the owner of ' . $uuid . ' may change'
			);
		}

	}//end testRequesterOwnedRequestsBecomeSystemOwned()

	/**
	 * A non-object entry is skipped, a failing update is reported and the rest
	 * carry on, and the summary counts only the requests handed over.
	 *
	 * @return void
	 */
	public function testAFailedHandOverIsReportedAndTheRestCarryOn(): void {
		$failing = new ObjectEntity();
		$failing->setUuid('fail-1');
		$failing->setOwner('carol');
		$this->requests = ['junk' => 'not an object', 'fail-1' => $failing] + $this->requests;
		$lines = [];
		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['warning', 'info'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $message) use (&$lines, $level): void {
					$lines[] = $level . ' ' . $message;
				}
			);
		}

		$this->step()->run($output);

		$this->assertSame(expected: [['req-alice', '__system__'], ['req-bob', '__system__']], actual: $this->updated);
		$this->assertSame(
			expected: [
				'warning Could not hand tool-access request fail-1 to the system: row locked',
				'info 2 tool-access request(s) given the system owner.',
			],
			actual: $lines
		);

	}//end testAFailedHandOverIsReportedAndTheRestCarryOn()

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
	 * A register or schema OpenRegister cannot resolve warns and skips, so an
	 * upgrade is never aborted by this step.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterWarnsAndSkips(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('warning');
		$this->failRead = true;

		$this->step()->run($output);

		$this->assertSame(expected: [], actual: $this->updated);

	}//end testAnUnresolvableRegisterWarnsAndSkips()

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
