<?php

/**
 * Unit tests for DepartmentSwitch: which departments are off, which agents they hold, and switching one.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\DepartmentSwitch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The per-department AI switch.
 */
class DepartmentSwitchTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Stored departments.
	 *
	 * @var array<int,ObjectEntity>
	 */
	private array $departments = [];

	/**
	 * Stored agents by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $agents = [];

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willReturnCallback(fn (): array => $this->departments);
		$this->objectService->method('find')->willReturnCallback(
			function ($id) {
				if (isset($this->agents[$id]) === false) {
					throw new RuntimeException('not found');
				}

				$agent = new ObjectEntity();
				$agent->setUuid($id);
				$agent->setObject($this->agents[$id]);
				return $agent;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->departments = [
			$this->department(uuid: 'd1', group: 'burgerzaken', off: false),
			$this->department(uuid: 'd2', group: 'sociaal-domein', off: true),
		];
		$this->agents = [
			'agent-bz' => ['name' => 'Burgerzaken helper', 'department' => 'burgerzaken'],
			'agent-sd' => ['name' => 'Sociaal domein helper', 'department' => 'sociaal-domein'],
			'agent-none' => ['name' => 'No department'],
			'agent-unmarked' => ['name' => 'Unmarked group', 'department' => 'finance'],
		];
	}

	/**
	 * A department object.
	 *
	 * @param string $uuid  The uuid.
	 * @param string $group The group id.
	 * @param mixed  $off   The aiOff value.
	 *
	 * @return ObjectEntity
	 */
	private function department(string $uuid, string $group, mixed $off): ObjectEntity {
		$department = new ObjectEntity();
		$department->setUuid($uuid);
		$department->setObject(['group' => $group, 'label' => ucfirst($group), 'aiOff' => $off]);
		return $department;
	}//end department()

	/**
	 * The service under test.
	 *
	 * @return DepartmentSwitch
	 */
	private function switch(): DepartmentSwitch {
		return new DepartmentSwitch(objectService: $this->objectService, logger: $this->logger);
	}//end switch()

	/**
	 * Only departments whose aiOff is exactly true are off.
	 *
	 * @return void
	 */
	public function testOnlyDepartmentsSwitchedOffAreOff(): void {
		$this->departments[] = $this->department(uuid: 'd3', group: 'archief', off: 'true');

		$this->assertSame(['sociaal-domein'], $this->switch()->offDepartments());
	}//end testOnlyDepartmentsSwitchedOffAreOff()

	/**
	 * The off department's agent is held; the other department's agent runs.
	 *
	 * @return void
	 */
	public function testAnOffDepartmentHoldsItsAgentAndNotTheOthers(): void {
		$switch = $this->switch();

		$this->assertSame('sociaal-domein', $switch->offDepartmentOf(agentId: 'agent-sd'));
		$this->assertNull($switch->offDepartmentOf(agentId: 'agent-bz'));
	}//end testAnOffDepartmentHoldsItsAgentAndNotTheOthers()

	/**
	 * An agent without a department, in an unmarked group, unknown, or unnamed is not held.
	 *
	 * @return void
	 */
	public function testAgentsOutsideAnOffDepartmentAreNotHeld(): void {
		$switch = $this->switch();

		$this->assertNull($switch->offDepartmentOf(agentId: 'agent-none'));
		$this->assertNull($switch->offDepartmentOf(agentId: 'agent-unmarked'));
		$this->assertNull($switch->offDepartmentOf(agentId: 'agent-gone'));
		$this->assertNull($switch->offDepartmentOf(agentId: ''));
	}//end testAgentsOutsideAnOffDepartmentAreNotHeld()

	/**
	 * An agent the object service answers with null for (not found without an
	 * exception) is not held, rather than failing the run's gate check.
	 *
	 * @return void
	 */
	public function testAnAgentFoundAsNullIsNotHeld(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(fn (): array => $this->departments);
		$objectService->expects($this->once())->method('find')->willReturn(null);

		$switch = new DepartmentSwitch(objectService: $objectService, logger: $this->logger);

		$this->assertNull($switch->offDepartmentOf(agentId: 'agent-sd'));
	}//end testAnAgentFoundAsNullIsNotHeld()

	/**
	 * A department list that cannot be read is logged as an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableDepartmentListIsLogged(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('db down'));

		$this->assertSame([], (new DepartmentSwitch(objectService: $objectService, logger: $this->logger))->offDepartments());
	}//end testAnUnreadableDepartmentListIsLogged()

	/**
	 * Switching a department off stores who, when and why, on that department's object.
	 *
	 * @return void
	 */
	public function testSwitchingOffRecordsWhoWhenAndWhy(): void {
		$saved = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
				$saved = [$object, $register, $schema, $uuid];
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);

		$department = $this->switch()->switch(group: 'burgerzaken', off: true, userId: 'admin', reason: 'incident 42');

		$this->assertSame(['hermiq', 'departmentcontrol', 'd1'], array_slice($saved, 1));
		$this->assertTrue($department['aiOff']);
		$this->assertSame('admin', $department['switchedBy']);
		$this->assertSame('incident 42', $department['reason']);
		$this->assertSame('burgerzaken', $department['group']);
		$this->assertNotFalse(strtotime($department['switchedAt']));
	}//end testSwitchingOffRecordsWhoWhenAndWhy()

	/**
	 * A group nobody marked as a department is not switched.
	 *
	 * @return void
	 */
	public function testAnUnmarkedGroupIsNotSwitched(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertNull($this->switch()->switch(group: 'finance', off: true, userId: 'admin'));
	}//end testAnUnmarkedGroupIsNotSwitched()
}//end class
