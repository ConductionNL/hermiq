<?php

/**
 * Unit tests for the department gate in ScheduleService::gateFor().
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

use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * A run of an agent in a department that is off is held; the kill-switch still wins.
 */
class ScheduleDepartmentGateTest extends TestCase {

	/**
	 * Stored objects per schema.
	 *
	 * @var array<string,array<int,ObjectEntity>>
	 */
	private array $objects = [];

	/**
	 * The schema the object service is set to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The service with every collaborator doubled and a register held in memory.
	 *
	 * @return ScheduleService
	 */
	private function service(): ScheduleService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function ($schema) use ($objectService): ObjectService&MockObject {
				$this->schema = (string)$schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$found = $this->objects[$this->schema] ?? [];
				if (($config['filters']['engaged'] ?? null) === true) {
					$found = array_filter($found, static fn (ObjectEntity $o): bool => ($o->getObject()['engaged'] ?? false) === true);
				}

				return array_values($found);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function ($id): ObjectEntity {
				foreach ($this->objects['agent'] ?? [] as $agent) {
					if ($agent->getUuid() === $id) {
						return $agent;
					}
				}

				throw new RuntimeException('not found');
			}
		);

		$arguments = [];
		foreach ((new ReflectionClass(ScheduleService::class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($parameter->isOptional() === true) {
				break;
			}

			if ($type instanceof ReflectionNamedType && $type->getName() === ObjectService::class) {
				$arguments[] = $objectService;
				continue;
			}

			$arguments[] = $this->createMock($type->getName());
		}

		return new ScheduleService(...$arguments);
	}//end service()

	/**
	 * An object entity.
	 *
	 * @param string              $uuid         The uuid.
	 * @param array<string,mixed> $data         The data.
	 * @param string|null         $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data, ?string $organisation = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$entity->setOrganisation($organisation);
		return $entity;
	}//end entity()

	protected function setUp(): void {
		$this->objects = [
			'departmentcontrol' => [
				$this->entity(uuid: 'd1', data: ['group' => 'burgerzaken', 'aiOff' => false]),
				$this->entity(uuid: 'd2', data: ['group' => 'sociaal-domein', 'aiOff' => true]),
			],
			'agent' => [
				$this->entity(uuid: 'agent-bz', data: ['name' => 'Burgerzaken', 'department' => 'burgerzaken', 'active' => true]),
				$this->entity(uuid: 'agent-sd', data: ['name' => 'Sociaal domein', 'department' => 'sociaal-domein', 'active' => true]),
			],
			'tenantcontrol' => [],
		];
	}

	/**
	 * The off department's agent is held, the other department's agent passes.
	 *
	 * @return void
	 */
	public function testAnOffDepartmentHoldsOnlyItsOwnAgents(): void {
		$service = $this->service();

		$this->assertSame('skipped_department_off', $service->gateFor(organisation: 'org-1', agentId: 'agent-sd'));
		$this->assertNull($service->gateFor(organisation: 'org-1', agentId: 'agent-bz'));
		$this->assertTrue($service->isAgentDepartmentOff(agentId: 'agent-sd'));
		$this->assertFalse($service->isAgentDepartmentOff(agentId: 'agent-bz'));
	}//end testAnOffDepartmentHoldsOnlyItsOwnAgents()

	/**
	 * The organisation kill-switch stops an agent whose department is on.
	 *
	 * @return void
	 */
	public function testTheKillSwitchWinsOverADepartmentThatIsOn(): void {
		$this->objects['tenantcontrol'] = [$this->entity(uuid: 't1', data: ['engaged' => true], organisation: 'org-1')];

		$this->assertSame('skipped_killswitch', $this->service()->gateFor(organisation: 'org-1', agentId: 'agent-bz'));
	}//end testTheKillSwitchWinsOverADepartmentThatIsOn()
}//end class
