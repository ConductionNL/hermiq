<?php

/**
 * EvalScheduleTask: due selection, commit before run, the owner rule and the
 * regression notification (reuse-scheduled-bias-evals, task 3).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Hermiq\BackgroundJob\EvalScheduleTask;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Service\EvalScheduleClock;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * EvalScheduleTask::runIfDue().
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleTaskTest extends TestCase {

	/**
	 * Every call in order: ['save', data] or ['run', threshold].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $calls = [];

	/**
	 * The notifications sent, as their recorded setter calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The setter calls of every notification created, by object id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $recorded = [];

	/**
	 * A job over recording doubles.
	 *
	 * @param array<string, mixed> $result       What the eval run returns.
	 * @param string               $datasetOwner The dataset's owner.
	 * @param string               $agentOwner   The agent's owner.
	 * @param bool                 $runFails     Whether the eval run throws.
	 *
	 * @return EvalScheduleTask
	 */
	private function job(
		array $result = [],
		string $datasetOwner = 'fenna',
		string $agentOwner = 'fenna',
		bool $runFails = false,
	): EvalScheduleTask {
		$dataset = new ObjectEntity();
		$dataset->setUuid('ds-1');
		$dataset->setOwner($datasetOwner);
		$dataset->setObject(['name' => 'Bias check']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($dataset);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->calls[] = ['save', $object, (string)$options->uuid];
				return new ObjectEntity();
			}
		);

		$agent = new Agent();
		$agent->setUuid('agent-1');
		$agent->setOwner($agentOwner);
		$agent->setName('Permit intake helper');
		$agents = $this->createMock(AgentMapper::class);
		$agents->method('findByUuid')->willReturn($agent);

		$runs = $this->createMock(EvalRunServiceInterface::class);
		$runs->method('run')->willReturnCallback(
			function (ObjectEntity $dataset, Agent $agent, ?string $agentVersionId = null, ?int $regressionThresholdOverride = null) use ($result, $runFails): array {
				$this->calls[] = ['run', $regressionThresholdOverride];
				if ($runFails === true) {
					throw new RuntimeException('engine down');
				}

				return $result + [
					'evalRunId'            => 'run-7',
					'status'               => 'completed',
					'passRate'             => 0.9,
					'regressionGateResult' => 'passed',
					'previousPassRate'     => 0.9,
				];
			}
		);

		$custody = $this->createMock(SeedCustodyServiceInterface::class);
		$custody->method('actsAsOwner')->willReturnCallback(
			static fn (?string $owner, string $uid): bool => $owner === $uid
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = $this->recorded[spl_object_id($notification)];
			}
		);

		return new EvalScheduleTask(
			$this->createMock(ITimeFactory::class),
			$objects,
			$agents,
			$runs,
			$custody,
			new EvalScheduleClock(),
			$manager,
			$this->createMock(LoggerInterface::class)
		);
	}//end job()

	/**
	 * A notification double that records its setter calls.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$id = spl_object_id($notification);
		$this->recorded[$id] = [];
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($notification, $setter, $id): INotification {
					$this->recorded[$id][$setter] = $args;
					return $notification;
				}
			);
		}

		return $notification;
	}//end notification()

	/**
	 * A schedule object.
	 *
	 * @param array<string, mixed> $data  Payload overrides.
	 * @param string               $owner The schedule's owner.
	 *
	 * @return ObjectEntity
	 */
	private static function schedule(array $data, string $owner = 'fenna'): ObjectEntity {
		$schedule = new ObjectEntity();
		$schedule->setUuid('sched-1');
		$schedule->setOwner($owner);
		$schedule->setObject(
			$data + [
				'datasetId' => 'ds-1',
				'agentId'   => 'agent-1',
				'cronExpr'  => '0 6 1 * *',
				'enabled'   => true,
				'nextRun'   => '2026-12-01T06:00:00+00:00',
			]
		);
		return $schedule;
	}//end schedule()

	/**
	 * The tick moment: 1 December 2026, 07:00 UTC, an hour after the monthly run was due.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-12-01T07:00:00+00:00');
	}//end now()

	/**
	 * A due schedule stores its next run BEFORE the eval runs, then the run id after.
	 *
	 * @return void
	 */
	public function testTheNextRunIsStoredBeforeTheRunStarts(): void {
		$result = $this->job()->runIfDue(self::schedule(['regressionThresholdPercent' => 10]), self::now());

		$this->assertSame('run-7', $result['evalRunId']);
		$this->assertSame(['save', 'run', 'save'], array_column($this->calls, 0));
		$this->assertSame('2027-01-01T06:00:00+00:00', $this->calls[0][1]['nextRun']);
		$this->assertArrayNotHasKey('lastRunId', $this->calls[0][1]);
		$this->assertSame('sched-1', $this->calls[0][2]);
		$this->assertSame(10, $this->calls[1][1]);
		$this->assertSame('run-7', $this->calls[2][1]['lastRunId']);
	}//end testTheNextRunIsStoredBeforeTheRunStarts()

	/**
	 * When the run throws, the next run has already been stored: it is not started twice.
	 *
	 * @return void
	 */
	public function testAFailingRunHasAlreadyAdvancedTheSchedule(): void {
		try {
			$this->job(runFails: true)->runIfDue(self::schedule([]), self::now());
			$this->fail('the run failure must reach the caller, which logs it per schedule');
		} catch (RuntimeException) {
			$this->assertSame(['save', 'run'], array_column($this->calls, 0));
			$this->assertSame('2027-01-01T06:00:00+00:00', $this->calls[0][1]['nextRun']);
		}
	}//end testAFailingRunHasAlreadyAdvancedTheSchedule()

	/**
	 * A schedule that is not due, or is disabled, does nothing at all.
	 *
	 * @return void
	 */
	public function testANotDueOrDisabledScheduleDoesNothing(): void {
		$job = $this->job();

		$this->assertNull($job->runIfDue(self::schedule(['nextRun' => '2027-01-01T06:00:00+00:00']), self::now()));
		$this->assertNull($job->runIfDue(self::schedule(['enabled' => false]), self::now()));
		$this->assertSame([], $this->calls);
	}//end testANotDueOrDisabledScheduleDoesNothing()

	/**
	 * A schedule whose owner no longer owns the agent or the dataset does not run it.
	 *
	 * @return void
	 */
	public function testAScheduleNeverRunsAnotherPersonsAgentOrDataset(): void {
		$this->job(agentOwner: 'mallory')->runIfDue(self::schedule([]), self::now());
		$this->assertSame(['save'], array_column($this->calls, 0));

		$this->calls = [];
		$this->job(datasetOwner: 'mallory')->runIfDue(self::schedule([]), self::now());
		$this->assertSame(['save'], array_column($this->calls, 0));
		$this->assertSame([], $this->sent);
	}//end testAScheduleNeverRunsAnotherPersonsAgentOrDataset()

	/**
	 * A cron expression that is not valid switches the schedule off without running it.
	 *
	 * @return void
	 */
	public function testAnInvalidCronSwitchesTheScheduleOff(): void {
		$this->assertNull($this->job()->runIfDue(self::schedule(['cronExpr' => 'monthly']), self::now()));

		$this->assertSame(['save'], array_column($this->calls, 0));
		$this->assertFalse($this->calls[0][1]['enabled']);
	}//end testAnInvalidCronSwitchesTheScheduleOff()

	/**
	 * A failed regression gate tells the owner, with both pass rates and the run.
	 *
	 * @return void
	 */
	public function testAFailedGateNotifiesTheOwner(): void {
		$this->job(['passRate' => 0.8, 'previousPassRate' => 0.95, 'regressionGateResult' => 'failed'])
			->runIfDue(self::schedule(['regressionThresholdPercent' => 10]), self::now());

		$this->assertCount(1, $this->sent);
		$this->assertSame(['hermiq'], $this->sent[0]['setApp']);
		$this->assertSame(['fenna'], $this->sent[0]['setUser']);
		$this->assertSame(['evalrun', 'run-7'], $this->sent[0]['setObject']);
		$this->assertSame(
			[
				'eval_regression',
				[
					'name'      => 'Permit intake helper',
					'dataset'   => 'Bias check',
					'datasetId' => 'ds-1',
					'previous'  => 95,
					'current'   => 80,
				],
			],
			$this->sent[0]['setSubject']
		);
	}//end testAFailedGateNotifiesTheOwner()

	/**
	 * A passed or not applicable gate sends nothing.
	 *
	 * @return void
	 */
	public function testAPassedGateSendsNothing(): void {
		$this->job()->runIfDue(self::schedule([]), self::now());
		$this->job(['regressionGateResult' => 'not_applicable', 'previousPassRate' => null])->runIfDue(self::schedule([]), self::now());

		$this->assertSame([], $this->sent);
	}//end testAPassedGateSendsNothing()
}//end class
