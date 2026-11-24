<?php

/**
 * Unit tests for EvalScheduleEditor and EvalOwnership (reuse-scheduled-bias-evals).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Eval
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Eval;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Hermiq\Service\Eval\EvalOwnership;
use OCA\Hermiq\Service\Eval\EvalScheduleEditor;
use OCA\Hermiq\Service\EvalScheduleClock;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Contract\ObjectFindOptions as PublishedObjectFindOptions;
use OCA\OpenRegister\Contract\ObjectSaveOptions as PublishedObjectSaveOptions;

/**
 * @covers \OCA\Hermiq\Service\Eval\EvalScheduleEditor
 * @covers \OCA\Hermiq\Service\Eval\EvalOwnership
 * @uses   \OCA\Hermiq\Service\EvalScheduleClock
 * @uses   \OCA\Hermiq\Service\SeedCustodyService
 */
class EvalScheduleEditorTest extends TestCase {

	/**
	 * The object service double: objects by uuid, rows listed, and what was saved.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objects;

	/**
	 * The editor over alice's dataset ds-1, alice's agent a-1, bob's agent a-2 and the given schedules.
	 *
	 * @param array<int, ObjectEntity> $schedules The stored schedules.
	 *
	 * @return EvalScheduleEditor
	 */
	private function editor(array $schedules = []): EvalScheduleEditor {
		$byId = ['ds-1' => self::entity(uuid: 'ds-1', owner: 'alice', data: ['name' => 'Bias check'])];
		foreach ($schedules as $schedule) {
			$byId[(string)$schedule->getUuid()] = $schedule;
		}

		$this->objects = new class($byId, $schedules) extends ObjectService {
			/**
			 * The saves, payload and uuid.
			 *
			 * @var array<int, array{object: array, uuid: string|null}>
			 */
			public array $saved = [];

			/**
			 * @param array<string, ObjectEntity> $byId Objects by uuid.
			 * @param array<int, ObjectEntity>    $rows What a list returns.
			 */
			public function __construct(private array $byId, private array $rows) {
			}

			/**
			 * The object, or a not-found.
			 *
			 * @param string|int                                        $id            The uuid.
			 * @param ObjectFindOptions|PublishedObjectFindOptions|null $options       Ignored.
			 * @param bool                                              $_rbac         Ignored.
			 * @param bool                                              $_multitenancy Ignored.
			 *
			 * @return ObjectEntity|null
			 */
			public function find(
				string|int $id,
				ObjectFindOptions|PublishedObjectFindOptions|null $options = null,
				bool $_rbac = true,
				bool $_multitenancy = true
			): ?ObjectEntity {
				if (isset($this->byId[(string)$id]) === false) {
					throw new DoesNotExistException('no such object');
				}

				return $this->byId[(string)$id];
			}

			/**
			 * Ignore the register.
			 *
			 * @param mixed $register The register.
			 *
			 * @return static
			 */
			public function setRegister(mixed $register): static {
				return $this;
			}

			/**
			 * Ignore the schema.
			 *
			 * @param mixed $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(mixed $schema): static {
				return $this;
			}

			/**
			 * The stored schedules.
			 *
			 * @param array $config        Ignored.
			 * @param bool  $_rbac         Ignored.
			 * @param bool  $_multitenancy Ignored.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rows;
			}

			/**
			 * Record the save and hand back the stored schedule.
			 *
			 * @param array|ObjectEntity $object        The payload.
			 * @param ObjectSaveOptions  $options       The target and uuid.
			 * @param bool               $_rbac         Ignored.
			 * @param bool               $_multitenancy Ignored.
			 *
			 * @return ObjectEntity
			 */
			public function saveObject(
				array|ObjectEntity $object,
				ObjectSaveOptions|PublishedObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntity {
				$this->saved[] = ['object' => $object, 'uuid' => $options->uuid];
				$entity        = new ObjectEntity();
				$entity->setUuid($options->uuid ?? 'new-schedule');
				$entity->setOwner('alice');
				$entity->setObject($object);
				return $entity;
			}
		};

		$agents = $this->createMock(originalClassName: AgentMapper::class);
		$agents->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Agent {
				$owners = ['a-1' => 'alice', 'a-2' => 'bob'];
				if (isset($owners[$uuid]) === false) {
					throw new DoesNotExistException('no such agent');
				}

				$agent = new Agent();
				$agent->setUuid($uuid);
				$agent->setOwner($owners[$uuid]);
				return $agent;
			}
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable('2026-11-20T12:00:00Z'))->getTimestamp());

		return new EvalScheduleEditor(
			objectService: $this->objects,
			ownership: new EvalOwnership(objectService: $this->objects, agentMapper: $agents, seedCustody: new SeedCustodyService(groupManager: $groups)),
			clock: new EvalScheduleClock(),
			time: $time
		);
	}//end editor()

	/**
	 * An object.
	 *
	 * @param string               $uuid  The uuid.
	 * @param string               $owner The owner.
	 * @param array<string, mixed> $data  The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, string $owner, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An owner sets a monthly schedule: it is stored as theirs with its next run.
	 *
	 * @return void
	 */
	public function testAnOwnerSetsAMonthlySchedule(): void {
		$input    = ['agentId' => 'a-1', 'cronExpr' => '', 'enabled' => true, 'regressionThresholdPercent' => '10'];
		$schedule = $this->editor()->save(datasetId: 'ds-1', uid: 'alice', input: $input);

		self::assertSame(expected: 'new-schedule', actual: $schedule['id']);
		self::assertSame(expected: '0 6 1 * *', actual: $schedule['cronExpr']);
		self::assertSame(expected: '2026-12-01T06:00:00+00:00', actual: $schedule['nextRun']);
		self::assertTrue(condition: $schedule['enabled']);
		self::assertSame(expected: 10, actual: $schedule['regressionThresholdPercent']);
		self::assertNull(actual: $this->objects->saved[0]['uuid']);
		self::assertSame(expected: 'ds-1', actual: $this->objects->saved[0]['object']['datasetId']);
	}//end testAnOwnerSetsAMonthlySchedule()

	/**
	 * Someone else's agent, a dataset the caller does not own, or a missing one: null (404), nothing stored.
	 *
	 * @return void
	 */
	public function testANonOwnerGetsNothing(): void {
		$editor = $this->editor();

		self::assertNull(actual: $editor->save(datasetId: 'ds-1', uid: 'alice', input: ['agentId' => 'a-2']));
		self::assertNull(actual: $editor->save(datasetId: 'ds-1', uid: 'bob', input: ['agentId' => 'a-2']));
		self::assertNull(actual: $editor->save(datasetId: 'ds-9', uid: 'alice', input: ['agentId' => 'a-1']));
		self::assertNull(actual: $editor->listFor(datasetId: 'ds-1', uid: 'bob'));
		self::assertSame(expected: [], actual: $this->objects->saved);
	}//end testANonOwnerGetsNothing()

	/**
	 * A cron expression or threshold that is not valid is refused.
	 *
	 * @return void
	 */
	public function testAnInvalidScheduleIsRefused(): void {
		$editor = $this->editor();

		try {
			$editor->save(datasetId: 'ds-1', uid: 'alice', input: ['agentId' => 'a-1', 'cronExpr' => 'every month']);
			self::fail(message: 'A cron expression that is not valid must be refused.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString(needle: 'cron', haystack: $e->getMessage());
		}

		$this->expectException(exception: InvalidArgumentException::class);
		$editor->save(datasetId: 'ds-1', uid: 'alice', input: ['agentId' => 'a-1', 'regressionThresholdPercent' => 150]);
	}//end testAnInvalidScheduleIsRefused()

	/**
	 * Changing a schedule keeps its uuid and last run; another dataset's schedule is not changed.
	 *
	 * @return void
	 */
	public function testChangingAScheduleKeepsItsLastRun(): void {
		$stored = self::entity(uuid: 's-1', owner: 'alice', data: ['datasetId' => 'ds-1', 'agentId' => 'a-1', 'lastRunId' => 'run-7', 'enabled' => true]);
		$other  = self::entity(uuid: 's-2', owner: 'alice', data: ['datasetId' => 'ds-2', 'agentId' => 'a-1']);
		$editor = $this->editor(schedules: [$stored, $other]);

		$input   = ['agentId' => 'a-1', 'cronExpr' => '0 6 * * 1', 'enabled' => false];
		$changed = $editor->save(datasetId: 'ds-1', uid: 'alice', input: $input, scheduleId: 's-1');

		self::assertSame(expected: 's-1', actual: $this->objects->saved[0]['uuid']);
		self::assertSame(expected: 'run-7', actual: $changed['lastRunId']);
		self::assertFalse(condition: $changed['enabled']);
		self::assertSame(expected: '2026-11-23T06:00:00+00:00', actual: $changed['nextRun']);
		self::assertNull(actual: $editor->save(datasetId: 'ds-1', uid: 'alice', input: ['agentId' => 'a-1'], scheduleId: 's-2'));
	}//end testChangingAScheduleKeepsItsLastRun()

	/**
	 * The list holds the caller's schedules of this dataset only.
	 *
	 * @return void
	 */
	public function testTheListHoldsTheCallersSchedulesOfThisDataset(): void {
		$mine   = self::entity(uuid: 's-1', owner: 'alice', data: ['datasetId' => 'ds-1', 'agentId' => 'a-1', 'enabled' => true]);
		$theirs = self::entity(uuid: 's-3', owner: 'bob', data: ['datasetId' => 'ds-1', 'agentId' => 'a-2']);
		$other  = self::entity(uuid: 's-2', owner: 'alice', data: ['datasetId' => 'ds-2', 'agentId' => 'a-1']);

		$list = $this->editor(schedules: [$mine, $theirs, $other])->listFor(datasetId: 'ds-1', uid: 'alice');

		self::assertSame(expected: ['s-1'], actual: array_column($list, 'id'));
		self::assertSame(expected: '0 6 1 * *', actual: $list[0]['cronExpr']);
	}//end testTheListHoldsTheCallersSchedulesOfThisDataset()
}//end class
