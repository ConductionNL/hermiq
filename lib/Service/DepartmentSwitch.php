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
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Which departments have AI switched off, and switching one.
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
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
	 * A department list that cannot be read is logged as an error and reads as
	 * none off, the same rule TenantKillSwitch applies to the organisation switch.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function offDepartments(): array {
		$off = [];
		foreach ($this->departments() as $department) {
			$data = $department->getObject();
			$group = (string)($data['group'] ?? '');
			if ($group !== '' && ($data['aiOff'] ?? false) === true) {
				$off[] = $group;
			}
		}

		return array_values(array_unique($off));
	}//end offDepartments()

	/**
	 * The department of an agent when its AI is switched off, else null.
	 *
	 * An agent that names no department, or a group nobody marked as a
	 * department, is never held by this switch.
	 *
	 * @param string $agentId The agent's uuid.
	 *
	 * @return string|null The department's group id, or null when the agent may run.
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function offDepartmentOf(string $agentId): ?string {
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(
				id: $agentId,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return null;
		}

		// An agent that cannot be found is not held by a department switch.
		if ($agent instanceof ObjectEntity === false) {
			return null;
		}

		$department = trim((string)($agent->getObject()['department'] ?? ''));
		if ($department === '' || in_array($department, $this->offDepartments(), true) === false) {
			return null;
		}

		return $department;
	}//end offDepartmentOf()

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
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
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
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA,
			uuid: (string)$department->getUuid(),
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
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
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
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
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
			$found = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA)
				->findAll(config: [], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq could not load the department switches: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}

		return array_values(array_filter((array)$found, static fn (mixed $object): bool => $object instanceof ObjectEntity));
	}//end departments()
}//end class
