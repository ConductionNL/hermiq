<?php

/**
 * Hermiq DepartmentEvidence.
 *
 * What the approvals page shows per department, and the evidence file an
 * administrator downloads for one: each switch event with who and when, and
 * every run refused while the department was off. Both are read from the
 * audit trail: switch events are the `department-switch` entries on the
 * department's object, refused runs the `run` (schedule) and `agent-run`
 * (flow) entries whose status is `skipped_department_off` and whose agent
 * runs for the department.
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
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The per-department list and evidence file, read from the audit trail.
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
 */
final class DepartmentEvidence {

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The agent schema.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The audit actions of a run: a scheduled run and a flow run.
	 *
	 * @var string
	 */
	private const RUN_ACTIONS = 'run,agent-run';

	/**
	 * Constructor.
	 *
	 * @param DepartmentSwitch $departments      The marked departments.
	 * @param ObjectService    $objectService    Reads the agents of a department.
	 * @param AuditTrailMapper $auditTrailMapper Reads switch events and runs.
	 * @param LoggerInterface  $logger           Logs an agent list that cannot be read.
	 */
	public function __construct(
		private readonly DepartmentSwitch $departments,
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One row per marked department, for the "Per afdeling" list.
	 *
	 * Each row has the department's state, who switched it last and when, its
	 * agent count, when one of its agents last ran, and how many runs it refused
	 * since it was switched off (0 while it is on).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	public function overview(): array {
		$agents = $this->agentsByDepartment();
		$runs = $this->runEntries();

		$rows = [];
		foreach ($this->departments->marked() as $department) {
			$data = $department->getObject();
			$group = (string)($data['group'] ?? '');
			if ($group === '') {
				continue;
			}

			$names = ($agents[$group] ?? []);
			$aiOff = (($data['aiOff'] ?? false) === true);
			$switchedAt = $this->parseTime(value: (string)($data['switchedAt'] ?? ''));

			$lastRun = null;
			$refused = 0;
			foreach ($runs as $run) {
				if (isset($names[$run['agentId']]) === false) {
					continue;
				}

				if ($lastRun === null || $run['at'] > $lastRun) {
					$lastRun = $run['at'];
				}

				if ($aiOff === true && $run['status'] === DepartmentSwitch::SKIPPED_STATUS
					&& ($switchedAt === null || $run['at'] >= $switchedAt)
				) {
					$refused++;
				}
			}

			$rows[] = [
				'group' => $group,
				'label' => (string)($data['label'] ?? $group),
				'aiOff' => $aiOff,
				'switchedBy' => (string)($data['switchedBy'] ?? ''),
				'switchedAt' => (string)($data['switchedAt'] ?? ''),
				'reason' => (string)($data['reason'] ?? ''),
				'agentCount' => count($names),
				'lastRun' => $lastRun?->format(DateTimeInterface::ATOM),
				'refusedRuns' => $refused,
			];
		}//end foreach

		return $rows;
	}//end overview()

	/**
	 * The evidence file for one department, or null when the group is not a department.
	 *
	 * `switches` lists every switch event, oldest first, with who, when and
	 * why. `refusedRuns` lists every run of the department's agents refused
	 * because the department was off, oldest first.
	 *
	 * @param string $group The department's group id.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	public function evidence(string $group): ?array {
		$department = $this->departments->markedFor(group: $group);
		if ($department === null) {
			return null;
		}

		$data = $department->getObject();
		$names = ($this->agentsByDepartment()[$group] ?? []);

		$refused = [];
		foreach ($this->runEntries() as $run) {
			if ($run['status'] !== DepartmentSwitch::SKIPPED_STATUS || isset($names[$run['agentId']]) === false) {
				continue;
			}

			$refused[] = [
				'at' => $run['at']->format(DateTimeInterface::ATOM),
				'agentId' => $run['agentId'],
				'agentName' => $names[$run['agentId']],
				'trigger' => $run['trigger'],
				'objectUuid' => $run['objectUuid'],
			];
		}

		usort($refused, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

		return [
			'department' => [
				'group' => $group,
				'label' => (string)($data['label'] ?? $group),
				'aiOff' => (($data['aiOff'] ?? false) === true),
			],
			'generatedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'switches' => $this->switchEvents(department: $department),
			'refusedRuns' => $refused,
		];
	}//end evidence()

	/**
	 * The switch events of one department, oldest first.
	 *
	 * @param ObjectEntity $department The department.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function switchEvents(ObjectEntity $department): array {
		$entries = $this->auditTrailMapper->findAll(
			filters: [
				'object_uuid' => (string)$department->getUuid(),
				'action' => DepartmentSwitch::SWITCH_ACTION,
			]
		);

		$events = [];
		foreach ($entries as $entry) {
			if ($entry instanceof AuditTrail === false) {
				continue;
			}

			$context = ($entry->getChanged() ?? []);
			$at = (string)($context['switchedAt'] ?? '');
			if ($at === '' && $entry->getCreated() !== null) {
				$at = $entry->getCreated()->format(DateTimeInterface::ATOM);
			}

			$events[] = [
				'aiOff' => (($context['aiOff'] ?? false) === true),
				'by' => (string)($context['switchedBy'] ?? $entry->getUser() ?? ''),
				'at' => $at,
				'reason' => (string)($context['reason'] ?? ''),
			];
		}

		usort($events, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

		return $events;
	}//end switchEvents()

	/**
	 * Every run in the audit trail, as agent, status, moment, trigger and object.
	 *
	 * @return array<int,array{agentId:string,status:string,at:DateTimeImmutable,trigger:string,objectUuid:string}>
	 */
	private function runEntries(): array {
		$runs = [];
		foreach ($this->auditTrailMapper->findAll(filters: ['action' => self::RUN_ACTIONS]) as $entry) {
			if ($entry instanceof AuditTrail === false) {
				continue;
			}

			$context = ($entry->getChanged() ?? []);
			$agentId = (string)($context['agentId'] ?? '');
			$at = $this->parseTime(value: (string)($context['startedAt'] ?? ''));
			if ($at === null && $entry->getCreated() !== null) {
				$at = DateTimeImmutable::createFromInterface($entry->getCreated());
			}

			if ($agentId === '' || $at === null) {
				continue;
			}

			$trigger = 'flow';
			if ($entry->getAction() === 'run') {
				$trigger = 'schedule';
			}

			$runs[] = [
				'agentId' => $agentId,
				'status' => (string)($context['status'] ?? ''),
				'at' => $at,
				'trigger' => $trigger,
				'objectUuid' => (string)$entry->getObjectUuid(),
			];
		}//end foreach

		return $runs;
	}//end runEntries()

	/**
	 * The agents of every department: group id to agent uuid to agent name.
	 *
	 * An agent list that cannot be read is logged and reads as no agents.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function agentsByDepartment(): array {
		try {
			$found = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::AGENT_SCHEMA)
				->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq could not load the agents of the departments: ' . $e->getMessage(), ['exception' => $e]);
			return [];
		}

		$byDepartment = [];
		foreach ((array)$found as $agent) {
			if ($agent instanceof ObjectEntity === false) {
				continue;
			}

			$data = $agent->getObject();
			$department = trim((string)($data['department'] ?? ''));
			if ($department === '') {
				continue;
			}

			$uuid = (string)$agent->getUuid();
			$byDepartment[$department][$uuid] = (string)($data['name'] ?? $uuid);
		}

		return $byDepartment;
	}//end agentsByDepartment()

	/**
	 * A stored timestamp, or null when it is empty or unreadable.
	 *
	 * @param string $value The timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseTime(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parseTime()
}//end class
