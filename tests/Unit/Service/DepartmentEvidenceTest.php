<?php

/**
 * Unit tests for DepartmentEvidence: the per-department list and the evidence file.
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
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\DepartmentEvidence;
use OCA\Hermiq\Service\DepartmentSwitch;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The department list and evidence, read from the audit trail.
 */
class DepartmentEvidenceTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The audit trail double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrailMapper;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The schema the object service was last pointed at.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Stored objects by schema.
	 *
	 * @var array<string,array<int,ObjectEntity>>
	 */
	private array $objects = [];

	/**
	 * Audit entries by action.
	 *
	 * @var array<string,array<int,AuditTrail>>
	 */
	private array $entries = [];

	/**
	 * The audit filters asked for, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $filtersAsked = [];

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnCallback(
			function (string $schema): ObjectService {
				$this->schema = $schema;
				return $this->objectService;
			}
		);
		$this->objectService->method('findAll')->willReturnCallback(fn (): array => ($this->objects[$this->schema] ?? []));

		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = []): array {
				$this->filtersAsked[] = $filters;
				$found = [];
				foreach (explode(',', (string)($filters['action'] ?? '')) as $action) {
					foreach ($this->entries[$action] ?? [] as $entry) {
						if (isset($filters['object_uuid']) === false || $entry->getObjectUuid() === $filters['object_uuid']) {
							$found[] = $entry;
						}
					}
				}

				return $found;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objects = [
			'departmentcontrol' => [
				$this->object(uuid: 'd1', data: ['group' => 'burgerzaken', 'label' => 'Burgerzaken', 'aiOff' => false]),
				$this->object(
					uuid: 'd2',
					data: [
						'group' => 'sociaal-domein',
						'label' => 'Sociaal domein',
						'aiOff' => true,
						'switchedBy' => 'admin',
						'switchedAt' => '2026-11-14T14:00:00+00:00',
						'reason' => 'incident',
					]
				),
			],
			'agent' => [
				$this->object(uuid: 'agent-bz', data: ['name' => 'Burgerzaken helper', 'department' => 'burgerzaken']),
				$this->object(uuid: 'agent-sd', data: ['name' => 'Sociaal domein helper', 'department' => 'sociaal-domein']),
				$this->object(uuid: 'agent-sd2', data: ['name' => 'Second helper', 'department' => 'sociaal-domein']),
				$this->object(uuid: 'agent-none', data: ['name' => 'No department']),
			],
		];

		$this->entries = [
			'department-switch' => [
				$this->entry(object: 'd2', action: 'department-switch', context: ['aiOff' => true, 'switchedBy' => 'admin', 'switchedAt' => '2026-11-14T14:00:00+00:00', 'reason' => 'incident']),
				$this->entry(object: 'd2', action: 'department-switch', context: ['aiOff' => false, 'switchedBy' => 'piet', 'switchedAt' => '2026-11-13T09:00:00+00:00', 'reason' => '']),
				$this->entry(object: 'd1', action: 'department-switch', context: ['aiOff' => true, 'switchedBy' => 'other', 'switchedAt' => '2026-11-12T09:00:00+00:00', 'reason' => '']),
			],
			'run' => [
				$this->entry(object: 's1', action: 'run', context: ['agentId' => 'agent-sd', 'status' => 'skipped_department_off', 'startedAt' => '2026-11-14T15:10:00+00:00']),
				$this->entry(object: 's1', action: 'run', context: ['agentId' => 'agent-sd', 'status' => 'success', 'startedAt' => '2026-11-14T12:00:00+00:00']),
				$this->entry(object: 's2', action: 'run', context: ['agentId' => 'agent-bz', 'status' => 'success', 'startedAt' => '2026-11-14T15:15:00+00:00']),
				$this->entry(object: 's1', action: 'run', context: ['agentId' => 'agent-sd', 'status' => 'skipped_department_off', 'startedAt' => '2026-11-10T08:00:00+00:00']),
			],
			'agent-run' => [
				$this->entry(object: 'o9', action: 'agent-run', context: ['agentId' => 'agent-sd2', 'status' => 'skipped_department_off', 'startedAt' => '2026-11-14T14:30:00+00:00']),
				$this->entry(object: 'o8', action: 'agent-run', context: ['agentId' => 'agent-bz', 'status' => 'skipped_killswitch', 'startedAt' => '2026-11-14T14:40:00+00:00']),
			],
		];
	}

	/**
	 * An object.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The fields.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * An audit entry.
	 *
	 * @param string              $object  The object uuid it hangs on.
	 * @param string              $action  The action.
	 * @param array<string,mixed> $context The recorded context.
	 *
	 * @return AuditTrail
	 */
	private function entry(string $object, string $action, array $context): AuditTrail {
		$entry = new AuditTrail();
		$entry->setObjectUuid($object);
		$entry->setAction($action);
		$entry->setChanged($context);
		return $entry;
	}//end entry()

	/**
	 * The service under test.
	 *
	 * @return DepartmentEvidence
	 */
	private function evidence(): DepartmentEvidence {
		return new DepartmentEvidence(
			departments: new DepartmentSwitch(objectService: $this->objectService, logger: $this->logger),
			objectService: $this->objectService,
			auditTrailMapper: $this->auditTrailMapper,
			logger: $this->logger
		);
	}//end evidence()

	/**
	 * The evidence names the admin, the time switched off and the runs refused while off.
	 *
	 * @return void
	 */
	public function testTheEvidenceListsTheSwitchAndTheRefusedRuns(): void {
		$evidence = $this->evidence()->evidence(group: 'sociaal-domein');

		$this->assertSame(['group' => 'sociaal-domein', 'label' => 'Sociaal domein', 'aiOff' => true], $evidence['department']);
		$this->assertSame(
			[
				['aiOff' => false, 'by' => 'piet', 'at' => '2026-11-13T09:00:00+00:00', 'reason' => ''],
				['aiOff' => true, 'by' => 'admin', 'at' => '2026-11-14T14:00:00+00:00', 'reason' => 'incident'],
			],
			$evidence['switches']
		);
		$this->assertSame(
			[
				['at' => '2026-11-10T08:00:00+00:00', 'agentId' => 'agent-sd', 'agentName' => 'Sociaal domein helper', 'trigger' => 'schedule', 'objectUuid' => 's1'],
				['at' => '2026-11-14T14:30:00+00:00', 'agentId' => 'agent-sd2', 'agentName' => 'Second helper', 'trigger' => 'flow', 'objectUuid' => 'o9'],
				['at' => '2026-11-14T15:10:00+00:00', 'agentId' => 'agent-sd', 'agentName' => 'Sociaal domein helper', 'trigger' => 'schedule', 'objectUuid' => 's1'],
			],
			$evidence['refusedRuns']
		);
		$this->assertNotFalse(strtotime($evidence['generatedAt']));
		$this->assertContains(['object_uuid' => 'd2', 'action' => 'department-switch'], $this->filtersAsked);
	}//end testTheEvidenceListsTheSwitchAndTheRefusedRuns()

	/**
	 * Another department's evidence holds none of the off department's runs.
	 *
	 * @return void
	 */
	public function testOtherDepartmentsEvidenceIsItsOwn(): void {
		$evidence = $this->evidence()->evidence(group: 'burgerzaken');

		$this->assertSame([['aiOff' => true, 'by' => 'other', 'at' => '2026-11-12T09:00:00+00:00', 'reason' => '']], $evidence['switches']);
		$this->assertSame([], $evidence['refusedRuns']);
	}//end testOtherDepartmentsEvidenceIsItsOwn()

	/**
	 * A group that is not a department has no evidence.
	 *
	 * @return void
	 */
	public function testAGroupThatIsNotADepartmentHasNoEvidence(): void {
		$this->assertNull($this->evidence()->evidence(group: 'finance'));
	}//end testAGroupThatIsNotADepartmentHasNoEvidence()

	/**
	 * A switch entry without its own moment falls back to when it was written.
	 *
	 * @return void
	 */
	public function testASwitchWithoutAMomentUsesWhenItWasWritten(): void {
		$entry = $this->entry(object: 'd1', action: 'department-switch', context: ['aiOff' => true]);
		$entry->setUser('admin');
		$entry->setCreated(new DateTime('2026-11-01T10:00:00+00:00'));
		$this->entries['department-switch'] = [$entry];

		$switches = $this->evidence()->evidence(group: 'burgerzaken')['switches'];

		$this->assertSame([['aiOff' => true, 'by' => 'admin', 'at' => '2026-11-01T10:00:00+00:00', 'reason' => '']], $switches);
	}//end testASwitchWithoutAMomentUsesWhenItWasWritten()

	/**
	 * The list shows state, who and when, agent count, last run and the runs refused since the switch.
	 *
	 * @return void
	 */
	public function testTheListShowsWhoSwitchedItOffAndTheRefusedCount(): void {
		$rows = $this->evidence()->overview();

		$this->assertSame(
			[
				[
					'group' => 'burgerzaken',
					'label' => 'Burgerzaken',
					'aiOff' => false,
					'switchedBy' => '',
					'switchedAt' => '',
					'reason' => '',
					'agentCount' => 1,
					'lastRun' => '2026-11-14T15:15:00+00:00',
					'refusedRuns' => 0,
				],
				[
					'group' => 'sociaal-domein',
					'label' => 'Sociaal domein',
					'aiOff' => true,
					'switchedBy' => 'admin',
					'switchedAt' => '2026-11-14T14:00:00+00:00',
					'reason' => 'incident',
					'agentCount' => 2,
					'lastRun' => '2026-11-14T15:10:00+00:00',
					// The 10 Nov refusal was before this switch-off, so only two count.
					'refusedRuns' => 2,
				],
			],
			$rows
		);
	}//end testTheListShowsWhoSwitchedItOffAndTheRefusedCount()

	/**
	 * An agent list that cannot be read is logged and the departments show no agents.
	 *
	 * @return void
	 */
	public function testAnUnreadableAgentListIsLogged(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			function (string $schema) use ($objectService): ObjectService {
				$this->schema = $schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (): array {
				if ($this->schema === 'agent') {
					throw new RuntimeException('agents down');
				}

				return $this->objects[$this->schema];
			}
		);
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('agents down'));

		$evidence = new DepartmentEvidence(
			departments: new DepartmentSwitch(objectService: $objectService, logger: $this->logger),
			objectService: $objectService,
			auditTrailMapper: $this->auditTrailMapper,
			logger: $this->logger
		);

		$this->assertSame([0, 0], array_column($evidence->overview(), 'agentCount'));
	}//end testAnUnreadableAgentListIsLogged()
}//end class
