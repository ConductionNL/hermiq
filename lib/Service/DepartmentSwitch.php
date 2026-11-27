<?php

/**
 * Hermiq DepartmentSwitch.
 *
 * The per-department AI switch. An administrator marks Nextcloud groups as
 * departments (DepartmentControl objects in register `hermiq`, schema
 * `departmentcontrol`) and an agent names the department it runs for in
 * `department`. While a department's `aiOff` is true, every new run of its
 * agents is refused; other departments run on. The organisation kill-switch
 * is checked first and still stops everything.
 *
 * Reads run system-wide, like TenantKillSwitch: the dispatcher runs outside a
 * user session and must see every department's switch.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Which departments have AI switched off, and switching one.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */
final class DepartmentSwitch {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The department schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'departmentcontrol';

	/**
	 * The agent schema.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The run status of a run refused because its department is off.
	 *
	 * @var string
	 */
	public const SKIPPED_STATUS = 'skipped_department_off';

	/**
	 * The audit action of one switch event, on the department's object.
	 *
	 * @var string
	 */
	public const SWITCH_ACTION = 'department-switch';

	/**
	 * What offDepartmentOf() answers when the agent cannot be read: the run is held.
	 *
	 * @var string
	 */
	public const UNREADABLE = '(unreadable)';

	/**
	 * Constructor.
	 *
	 * @param ObjectService         $objectService    Reads departments and agents, writes a switch.
	 * @param LoggerInterface       $logger           Logs a department list that cannot be read.
	 * @param AuditTrailMapper|null $auditTrailMapper Records each switch event; null in the read-only
	 *                                                copy the dispatcher builds.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ?AuditTrailMapper $auditTrailMapper = null,
	) {
	}//end __construct()

	/**
	 * The group ids of the departments whose AI is switched off.
	 *
	 * A department list that cannot be read is logged as an error and lists none
	 * off here, for the screens; the run gate offDepartmentOf() holds the run
	 * instead.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function offDepartments(): array {
		return self::offGroups(departments: $this->departments());
	}//end offDepartments()

	/**
	 * The group ids of the given departments whose AI is switched off.
	 *
	 * @param array<int,ObjectEntity> $departments The departments.
	 *
	 * @return array<int,string>
	 */
	private static function offGroups(array $departments): array {
		$off = [];
		foreach ($departments as $department) {
			$data = $department->getObject();
			$group = (string)($data['group'] ?? '');
			if ($group !== '' && ($data['aiOff'] ?? false) === true) {
				$off[] = $group;
			}
		}

		return array_values(array_unique($off));
	}//end offGroups()

	/**
	 * The department of an agent when its AI is switched off, else null.
	 *
	 * An agent that names no department, a group nobody marked as a
	 * department, or an agent that does not exist, is never held by this switch.
	 * A read that fails holds the run (fail closed): an agent that cannot be read
	 * answers UNREADABLE, and an agent whose department switches cannot be read
	 * answers its own department, so a switched-off department never runs during
	 * a register outage.
	 *
	 * @param string $agentId The agent's uuid.
	 *
	 * @return string|null The department's group id (or UNREADABLE), or null when the agent may run.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function offDepartmentOf(string $agentId): ?string {
		if ($agentId === '') {
			return null;
		}

		$department = $this->agentDepartment(agentId: $agentId);
		if ($department === self::UNREADABLE) {
			return self::UNREADABLE;
		}

		if ($department === '') {
			return null;
		}

		return $this->heldDepartment(department: $department);
	}//end offDepartmentOf()

	/**
	 * The department an agent names: '' when it names none or does not exist,
	 * UNREADABLE when it cannot be read.
	 *
	 * @param string $agentId The agent's uuid.
	 *
	 * @return string
	 */
	private function agentDepartment(string $agentId): string {
		try {
			$agent = $this->objectService->find(
				id: $agentId,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return '';
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not read the agent for the department switch, run held: ' . $e->getMessage(),
				['exception' => $e]
			);
			return self::UNREADABLE;
		}

		if ($agent instanceof ObjectEntity === false) {
			return '';
		}

		return trim((string)($agent->getObject()['department'] ?? ''));
	}//end agentDepartment()

	/**
	 * The department when its AI is off or its switches cannot be read, else null.
	 *
	 * @param string $department The department's group id.
	 *
	 * @return string|null
	 */
	private function heldDepartment(string $department): ?string {
		try {
			$off = self::offGroups(departments: $this->readDepartments());
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not load the department switches, run held: ' . $e->getMessage(),
				['exception' => $e]
			);
			return $department;
		}

		if (in_array($department, $off, true) === false) {
			return null;
		}

		return $department;
	}//end heldDepartment()

	/**
	 * Switch a marked department's AI on or off, recording who and when.
	 *
	 * The caller checks that the user is an administrator. A group that is
	 * not marked as a department is not switched.
	 *
	 * @param string $group  The department's group id.
	 * @param bool   $off    True to switch AI off, false to switch it back on.
	 * @param string $userId The administrator.
	 * @param string $reason Why, when switching off.
	 *
	 * @return array<string,mixed>|null The department as stored, or null when the group is not a department.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function switch(string $group, bool $off, string $userId, string $reason = ''): ?array {
		$department = $this->department(group: $group);
		if ($department === null) {
			return null;
		}

		$data = array_merge(
			$department->getObject(),
			[
				'aiOff' => $off,
				'switchedBy' => $userId,
				'switchedAt' => (new DateTimeImmutable())->format('c'),
				'reason' => $reason,
			]
		);

		$saved = $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::SCHEMA),
				uuid: (string)$department->getUuid()
			),
			_rbac: false,
			_multitenancy: false
		);

		$this->recordSwitch(department: $saved, data: $data);

		return $saved->getObject();
	}//end switch()

	/**
	 * Every marked department.
	 *
	 * @return array<int,ObjectEntity>
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	public function marked(): array {
		return $this->departments();
	}//end marked()

	/**
	 * The department marked for a group, or null.
	 *
	 * @param string $group The group id.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	public function markedFor(string $group): ?ObjectEntity {
		return $this->department(group: $group);
	}//end markedFor()

	/**
	 * Record one switch event in the audit trail of the department's object.
	 *
	 * The evidence download reads these entries back. A failed write is logged
	 * as an error and does not undo the switch: a department that was switched
	 * off stays off.
	 *
	 * @param ObjectEntity        $department The department as stored.
	 * @param array<string,mixed> $data       The stored fields.
	 *
	 * @return void
	 */
	private function recordSwitch(ObjectEntity $department, array $data): void {
		if ($this->auditTrailMapper === null) {
			return;
		}

		try {
			$this->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $department,
					action: self::SWITCH_ACTION,
					context: [
						'group' => (string)($data['group'] ?? ''),
						'aiOff' => ($data['aiOff'] === true),
						'switchedBy' => (string)$data['switchedBy'],
						'switchedAt' => (string)$data['switchedAt'],
						'reason' => (string)$data['reason'],
					]
				)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not record the department switch in the audit trail: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}//end recordSwitch()

	/**
	 * The department marked for a group, or null.
	 *
	 * @param string $group The group id.
	 *
	 * @return ObjectEntity|null
	 */
	private function department(string $group): ?ObjectEntity {
		foreach ($this->departments() as $department) {
			if ((string)($department->getObject()['group'] ?? '') === $group) {
				return $department;
			}
		}

		return null;
	}//end department()

	/**
	 * Every marked department.
	 *
	 * @return array<int,ObjectEntity>
	 */
	private function departments(): array {
		try {
			return $this->readDepartments();
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not load the department switches: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}
	}//end departments()

	/**
	 * Every marked department; a read that fails throws.
	 *
	 * @return array<int,ObjectEntity>
	 *
	 * @throws Throwable When the department list cannot be read.
	 */
	private function readDepartments(): array {
		$found = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SCHEMA)
			->findAll(config: [], _rbac: false, _multitenancy: false);

		return array_values(array_filter((array)$found, static fn (mixed $object): bool => $object instanceof ObjectEntity));
	}//end readDepartments()
}//end class
