<?php

/**
 * Runs eval datasets against agents on their schedule.
 *
 * Hourly and never parallel. Each enabled `EvalSchedule` whose next run has come
 * is advanced to its following run and saved BEFORE the eval starts, as the
 * agent schedule dispatcher does, so a run that crashes or outlasts the tick is
 * never started twice. The run itself goes through `EvalRunService::run()`, so
 * the kill switch and budget gates apply exactly as to a run started by hand, and
 * every run is kept as an ordinary `EvalRun`. When the run's regression gate
 * fails, the schedule's owner is notified with a link to the run.
 *
 * The schedule runs only while its owner still owns both the dataset and the
 * agent (`SeedCustodyService::actsAsOwner()`, the same rule the run endpoint
 * applies), so a schedule never runs someone else's agent.
 *
 * @category BackgroundJob
 * @package  OCA\Hermiq\BackgroundJob
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

namespace OCA\Hermiq\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Service\EvalScheduleClock;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly runner of due eval schedules.
 */
class EvalScheduleTask extends TimedJob {

	private const REGISTER = 'hermiq';

	private const SCHEMA = 'evalschedule';

	private const DATASET_SCHEMA = 'evaldataset';

	/**
	 * The notification subject for a scheduled run whose regression gate failed.
	 */
	public const SUBJECT_REGRESSION = 'eval_regression';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                $time          The clock.
	 * @param ObjectService               $objectService Reads and saves schedules and datasets.
	 * @param AgentMapper                 $agentMapper   Resolves the target agent.
	 * @param EvalRunServiceInterface     $evalRuns      Runs the dataset, gates included.
	 * @param SeedCustodyServiceInterface $seedCustody   The owner rule of the run endpoint.
	 * @param EvalScheduleClock           $clock         Due selection and the next run.
	 * @param IManager                    $notifications Tells the owner about a failed gate.
	 * @param LoggerInterface             $logger        Records a schedule that could not run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly AgentMapper $agentMapper,
		private readonly EvalRunServiceInterface $evalRuns,
		private readonly SeedCustodyServiceInterface $seedCustody,
		private readonly EvalScheduleClock $clock,
		private readonly IManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Hourly: a monthly schedule then runs within the hour it is due.
		$this->setInterval(seconds: 3600);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run every due schedule; one failing schedule never stops the others.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	protected function run($argument): void {
		$now = new DateTimeImmutable('@' . $this->time->getTime());
		foreach ($this->schedules() as $schedule) {
			try {
				$this->runIfDue(schedule: $schedule, now: $now);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[hermiq] A scheduled eval run could not run: ' . $e->getMessage(),
					context: ['schedule' => (string)$schedule->getUuid()]
				);
			}
		}
	}//end run()

	/**
	 * Run one schedule when it is due: advance it, run the dataset, record the run.
	 *
	 * @param ObjectEntity      $schedule The `EvalSchedule` object.
	 * @param DateTimeImmutable $now      The current moment.
	 *
	 * @return array<string, mixed>|null The run result, or null when nothing ran.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function runIfDue(ObjectEntity $schedule, DateTimeImmutable $now): ?array {
		$data = $schedule->getObject();
		if ($this->clock->isDue(schedule: $data, now: $now) === false) {
			return null;
		}

		$owner = (string)($schedule->getOwner() ?? '');
		$next  = $this->clock->nextRun(cronExpr: (string)($data['cronExpr'] ?? ''), now: $now);
		if ($next === null) {
			$this->logger->warning(
				message: '[hermiq] An eval schedule has a cron expression that is not valid; it was switched off.',
				context: ['schedule' => (string)$schedule->getUuid()]
			);
			$data['enabled'] = false;
			$this->save(schedule: $schedule, data: $data);
			return null;
		}

		// Commit before run: the next run is stored first, so this one is never started twice.
		$data['nextRun'] = $next->format(DateTimeInterface::ATOM);
		$this->save(schedule: $schedule, data: $data);

		$dataset = $this->ownedDataset(datasetId: (string)($data['datasetId'] ?? ''), owner: $owner);
		$agent   = $this->ownedAgent(agentId: (string)($data['agentId'] ?? ''), owner: $owner);
		if ($dataset === null || $agent === null) {
			$this->logger->info(
				message: '[hermiq] An eval schedule was skipped: its owner no longer owns the dataset or the agent.',
				context: ['schedule' => (string)$schedule->getUuid()]
			);
			return null;
		}

		$threshold = $data['regressionThresholdPercent'] ?? null;
		$result    = $this->evalRuns->run(
			dataset: $dataset,
			agent: $agent,
			regressionThresholdOverride: (is_int($threshold) === true ? $threshold : null)
		);

		$data['lastRunId'] = $result['evalRunId'];
		$this->save(schedule: $schedule, data: $data);

		if ($result['regressionGateResult'] === 'failed') {
			$this->notifyRegression(owner: $owner, agent: $agent, dataset: $dataset, result: $result, now: $now);
		}

		return $result;
	}//end runIfDue()

	/**
	 * The enabled schedules, read as the system: the job has no user session.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function schedules(): array {
		$found = $this->objectService->setRegister(self::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => ['enabled' => true], 'limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter($found, static fn (mixed $row): bool => $row instanceof ObjectEntity));
	}//end schedules()

	/**
	 * Store a schedule's payload under its own uuid.
	 *
	 * @param ObjectEntity         $schedule The schedule.
	 * @param array<string, mixed> $data     The payload to store.
	 *
	 * @return void
	 */
	private function save(ObjectEntity $schedule, array $data): void {
		unset($data['@self'], $data['id']);
		$this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
				uuid: (string)$schedule->getUuid()
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The dataset, only when the schedule's owner may act as its owner.
	 *
	 * @param string $datasetId The dataset uuid.
	 * @param string $owner     The schedule's owner.
	 *
	 * @return ObjectEntity|null The dataset, or null when absent or not owned.
	 */
	private function ownedDataset(string $datasetId, string $owner): ?ObjectEntity {
		if ($datasetId === '' || $owner === '') {
			return null;
		}

		try {
			$dataset = $this->objectService->find(
				id: $datasetId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::DATASET_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}

		if ($dataset === null || $this->seedCustody->actsAsOwner(owner: $dataset->getOwner(), uid: $owner) === false) {
			return null;
		}

		return $dataset;
	}//end ownedDataset()

	/**
	 * The agent, only when the schedule's owner may act as its owner.
	 *
	 * @param string $agentId The agent uuid.
	 * @param string $owner   The schedule's owner.
	 *
	 * @return Agent|null The agent, or null when absent or not owned.
	 */
	private function ownedAgent(string $agentId, string $owner): ?Agent {
		if ($agentId === '' || $owner === '') {
			return null;
		}

		try {
			$agent = $this->agentMapper->findByUuid($agentId);
		} catch (Throwable) {
			return null;
		}

		if ($this->seedCustody->actsAsOwner(owner: $agent->getOwner(), uid: $owner) === false) {
			return null;
		}

		return $agent;
	}//end ownedAgent()

	/**
	 * Tell the schedule's owner that the run fell below its regression threshold.
	 *
	 * @param string               $owner   The schedule's owner.
	 * @param Agent                $agent   The agent the dataset ran against.
	 * @param ObjectEntity         $dataset The dataset.
	 * @param array<string, mixed> $result  The run result.
	 * @param DateTimeImmutable    $now     The current moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#scenario-the-monthly-bias-check-drops-and-the-owner-is-told
	 */
	private function notifyRegression(
		string $owner,
		Agent $agent,
		ObjectEntity $dataset,
		array $result,
		DateTimeImmutable $now,
	): void {
		$previous = $result['previousPassRate'];

		$notification = $this->notifications->createNotification();
		$notification->setApp('hermiq')
			->setUser($owner)
			->setDateTime(DateTime::createFromImmutable($now))
			->setObject('evalrun', (string)$result['evalRunId'])
			->setSubject(
				self::SUBJECT_REGRESSION,
				[
					'name'      => (string)($agent->getName() ?? ''),
					'dataset'   => (string)($dataset->getObject()['name'] ?? ''),
					'datasetId' => (string)$dataset->getUuid(),
					'previous'  => (is_numeric($previous) === true ? (int)round((float)$previous * 100) : null),
					'current'   => (int)round((float)$result['passRate'] * 100),
				]
			);
		$this->notifications->notify($notification);
	}//end notifyRegression()
}//end class
