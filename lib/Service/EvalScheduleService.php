<?php

/**
 * Runs an eval dataset against an agent on a schedule.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Cron\CronExpression;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\EvalRunServiceInterface;
use OCA\Hermiq\Kernel\Contracts\EvalScheduleServiceInterface;
use OCA\Hermiq\Repair\SeedBiasEvalTemplate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Eval schedules (REQ-BIASEVAL-003).
 *
 * Only someone who owns both the dataset and the agent sets a schedule, and the
 * schedule runs as that person: `EvalRunService::run()` applies the kill switch
 * and the budget gates and keeps every run, exactly as for a run started by
 * hand. A due schedule's next run is written BEFORE its run, like the agent
 * schedule dispatcher, so a run that crashes is not repeated on the next tick.
 * When a run fails its regression gate the owner is notified with the drop.
 * A schedule whose owner no longer owns the dataset or the agent is skipped.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleService implements EvalScheduleServiceInterface {

	/**
	 * Schema slug for EvalSchedule objects.
	 *
	 * @var string
	 */
	public const SCHEMA = 'evalschedule';

	/**
	 * The notification subject of a run that failed its regression gate.
	 *
	 * @var string
	 */
	public const SUBJECT_REGRESSED = 'eval_schedule_regressed';

	/**
	 * Monthly, at 06:00 UTC on the first.
	 *
	 * @var string
	 */
	public const DEFAULT_CRON = '0 6 1 * *';

	/**
	 * Constructor.
	 *
	 * @param ObjectService           $objectService Reads and writes the schedules.
	 * @param EvalOwnership           $ownership     Who owns a dataset or an agent.
	 * @param EvalRunServiceInterface $evalRuns      Runs a dataset against an agent.
	 * @param INotificationManager    $notifications Tells the owner about a drop.
	 * @param IURLGenerator           $urlGenerator  Links the notification to the dataset.
	 * @param ITimeFactory            $time          The clock.
	 * @param LoggerInterface         $logger        Logs a skipped or failed schedule.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI, one collaborator per concern.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EvalOwnership $ownership,
		private readonly EvalRunServiceInterface $evalRuns,
		private readonly INotificationManager $notifications,
		private readonly IURLGenerator $urlGenerator,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create or change a schedule of the dataset for the caller.
	 *
	 * @param string               $datasetId The dataset.
	 * @param array<string, mixed> $input     agentId, cronExpr, enabled, regressionThresholdPercent and, to change one, id.
	 * @param string               $uid       The caller.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws EvalActionException 404 when the dataset, the agent or the schedule is not the caller's; 400 on an unreadable schedule.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function save(string $datasetId, array $input, string $uid): array {
		$dataset = $this->ownership->ownedDataset(datasetId: $datasetId, uid: $uid);
		if ($dataset === null) {
			throw new EvalActionException('Dataset not found', 404);
		}

		$agent = $this->ownership->ownedAgent(agentId: (string)($input['agentId'] ?? ''), uid: $uid);
		if ($agent === null) {
			throw new EvalActionException('Agent not found', 404);
		}

		$existing = null;
		$scheduleId = (string)($input['id'] ?? '');
		if ($scheduleId !== '') {
			$existing = $this->ownedSchedule(scheduleId: $scheduleId, datasetId: (string)$dataset->getUuid(), uid: $uid);
		}

		$cronExpr = trim((string)($input['cronExpr'] ?? self::DEFAULT_CRON));
		$nextRun = $this->nextRunAfter(cronExpr: $cronExpr, after: $this->now());
		if ($nextRun === null) {
			throw new EvalActionException('This schedule cannot be read. Use five cron fields, for example 0 6 1 * *.', 400);
		}

		$data = [];
		if ($existing !== null) {
			$data = $existing->getObject();
			unset($data['@self'], $data['id']);
		}

		$data = array_merge(
			$data,
			[
				'datasetId' => (string)$dataset->getUuid(),
				'agentId' => (string)$agent->getUuid(),
				'cronExpr' => $cronExpr,
				'enabled' => (($input['enabled'] ?? true) !== false),
				'nextRun' => $nextRun->format(DateTimeInterface::ATOM),
				'regressionThresholdPercent' => self::threshold(value: ($input['regressionThresholdPercent'] ?? null)),
				'owner' => $uid,
			]
		);

		// No RBAC: EvalSchedule lists no write grant, so writes are owner-only;
		// the caller owns the dataset and the agent (checked above).
		$saved = $this->objectService->saveObject(
			object: $data,
			register: EvalOwnership::REGISTER,
			schema: self::SCHEMA,
			uuid: $existing?->getUuid(),
			_rbac: false,
			_owner: $uid
		);

		return self::view(schedule: $saved, data: $data);
	}//end save()

	/**
	 * The caller's schedules of the dataset.
	 *
	 * @param string $datasetId The dataset.
	 * @param string $uid       The caller.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws EvalActionException 404 when the dataset is not the caller's.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function listForDataset(string $datasetId, string $uid): array {
		$dataset = $this->ownership->ownedDataset(datasetId: $datasetId, uid: $uid);
		if ($dataset === null) {
			throw new EvalActionException('Dataset not found', 404);
		}

		$views = [];
		foreach ($this->schedules(filters: ['datasetId' => (string)$dataset->getUuid()]) as $schedule) {
			if (self::ownerOf(schedule: $schedule) === $uid) {
				$views[] = self::view(schedule: $schedule, data: $schedule->getObject());
			}
		}

		return $views;
	}//end listForDataset()

	/**
	 * Run every enabled schedule that is due, advancing each one's next run first.
	 *
	 * @return int How many runs were started.
	 *
	 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function runDue(): int {
		$now = $this->now();
		$started = 0;
		foreach ($this->schedules(filters: ['enabled' => true]) as $schedule) {
			$data = $schedule->getObject();
			if (($data['enabled'] ?? false) !== true || self::isDue(data: $data, now: $now) === false) {
				continue;
			}

			try {
				if ($this->runOne(schedule: $schedule, now: $now) === true) {
					$started++;
				}
			} catch (Throwable $e) {
				$this->logger->error(
					'[hermiq] Eval schedule ' . (string)$schedule->getUuid() . ' failed: ' . $e->getMessage(),
					['exception' => $e]
				);
			}
		}

		return $started;
	}//end runDue()

	/**
	 * Advance one due schedule, then run it as its owner and record the run.
	 *
	 * @param ObjectEntity      $schedule The due schedule.
	 * @param DateTimeImmutable $now      This tick.
	 *
	 * @return bool Whether a run was started.
	 */
	private function runOne(ObjectEntity $schedule, DateTimeImmutable $now): bool {
		$data = $schedule->getObject();
		unset($data['@self'], $data['id']);

		// Commit before run: the next run is stored first, so a crash in the run
		// does not make the same occurrence fire again on the next tick.
		$next = $this->nextRunAfter(cronExpr: (string)($data['cronExpr'] ?? self::DEFAULT_CRON), after: $now);
		$data['nextRun'] = $next?->format(DateTimeInterface::ATOM);
		if ($next === null) {
			$data['enabled'] = false;
		}

		$this->persist(schedule: $schedule, data: $data);
		if ($next === null) {
			$this->logger->warning('[hermiq] Eval schedule ' . (string)$schedule->getUuid() . ' has an unreadable cron expression; disabled.');
			return false;
		}

		$owner = self::ownerOf(schedule: $schedule);
		$dataset = $this->ownership->ownedDataset(datasetId: (string)($data['datasetId'] ?? ''), uid: $owner);
		$agent = $this->ownership->ownedAgent(agentId: (string)($data['agentId'] ?? ''), uid: $owner);
		if ($dataset === null || $agent === null) {
			$this->logger->warning('[hermiq] Eval schedule ' . (string)$schedule->getUuid() . ' skipped: its owner no longer owns the dataset or the agent.');
			return false;
		}

		$threshold = self::threshold(value: ($data['regressionThresholdPercent'] ?? null));
		$result = $this->evalRuns->run(dataset: $dataset, agent: $agent, regressionThresholdOverride: $threshold);

		$data['lastRunId'] = (string)($result['evalRunId'] ?? '');
		$data['lastRunAt'] = $now->format(DateTimeInterface::ATOM);
		$this->persist(schedule: $schedule, data: $data);

		if (($result['regressionGateResult'] ?? '') === 'failed') {
			$this->notifyDrop(owner: $owner, drop: new EvalScheduleDrop(dataset: $dataset, agent: $agent, result: $result));
		}

		return true;
	}//end runOne()

	/**
	 * Tell the owner a scheduled run's pass rate dropped past its threshold.
	 *
	 * @param string           $owner The schedule's owner.
	 * @param EvalScheduleDrop $drop  What dropped.
	 *
	 * @return void
	 */
	private function notifyDrop(string $owner, EvalScheduleDrop $drop): void {
		try {
			$datasetData = $drop->dataset->getObject();
			$notification = $this->notifications->createNotification();
			$notification->setApp(AppIdentity::APP_ID)
				->setUser($owner)
				->setDateTime(new DateTime('@' . $this->time->getTime()))
				->setObject('evalrun', (string)($drop->result['evalRunId'] ?? ''))
				->setSubject(
					self::SUBJECT_REGRESSED,
					[
						'agent' => (string)($drop->agent->getName() ?? ''),
						'dataset' => (string)($datasetData['name'] ?? ''),
						'bias' => (($datasetData['templateKey'] ?? '') === SeedBiasEvalTemplate::TEMPLATE_KEY),
						'previous' => self::percent(rate: ($drop->result['previousPassRate'] ?? null)),
						'current' => self::percent(rate: ($drop->result['passRate'] ?? null)),
					]
				)
				->setLink(
					$this->urlGenerator->getAbsoluteURL('/index.php/apps/hermiq/evals/' . rawurlencode((string)$drop->dataset->getUuid()))
				);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('[hermiq] Eval schedule notification failed: ' . $e->getMessage());
		}
	}//end notifyDrop()

	/**
	 * A schedule of the dataset that the caller owns.
	 *
	 * @param string $scheduleId The schedule.
	 * @param string $datasetId  The dataset it must belong to.
	 * @param string $uid        The caller.
	 *
	 * @return ObjectEntity
	 *
	 * @throws EvalActionException 404 when it is not the caller's schedule of this dataset.
	 */
	private function ownedSchedule(string $scheduleId, string $datasetId, string $uid): ObjectEntity {
		try {
			$schedule = $this->objectService->find(
				id: $scheduleId,
				register: EvalOwnership::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			$schedule = null;
		}

		if (($schedule instanceof ObjectEntity) === false
			|| self::ownerOf(schedule: $schedule) !== $uid
			|| (string)($schedule->getObject()['datasetId'] ?? '') !== $datasetId
		) {
			throw new EvalActionException('Schedule not found', 404);
		}

		return $schedule;
	}//end ownedSchedule()

	/**
	 * The schedules matching the filters, read without RBAC.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return list<ObjectEntity>
	 */
	private function schedules(array $filters): array {
		$objects = $this->objectService
			->setRegister(EvalOwnership::REGISTER)
			->setSchema(self::SCHEMA)
			->findAll(config: ['filters' => $filters], _rbac: false, _multitenancy: false);

		return array_values(array_filter($objects, static fn ($object): bool => $object instanceof ObjectEntity));
	}//end schedules()

	/**
	 * Write a schedule's fields back.
	 *
	 * @param ObjectEntity         $schedule The schedule.
	 * @param array<string, mixed> $data     Its fields.
	 *
	 * @return void
	 */
	private function persist(ObjectEntity $schedule, array $data): void {
		$this->objectService->saveObject(
			object: $data,
			register: EvalOwnership::REGISTER,
			schema: self::SCHEMA,
			uuid: (string)$schedule->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end persist()

	/**
	 * The next moment the cron expression fires after the given one, or null when it cannot be read.
	 *
	 * @param string            $cronExpr The expression.
	 * @param DateTimeImmutable $after    The moment.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function nextRunAfter(string $cronExpr, DateTimeImmutable $after): ?DateTimeImmutable {
		if ($cronExpr === '' || CronExpression::isValidExpression($cronExpr) === false) {
			return null;
		}

		try {
			$next = (new CronExpression($cronExpr))->getNextRunDate(
				currentTime: $after,
				nth: 0,
				allowCurrentDate: false,
				timeZone: 'UTC'
			);
		} catch (Throwable) {
			return null;
		}

		return DateTimeImmutable::createFromInterface($next)->setTimezone(new DateTimeZone('UTC'));
	}//end nextRunAfter()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}//end now()

	/**
	 * Whether a schedule is due: no next run yet, or one that has passed.
	 *
	 * @param array<string, mixed> $data The schedule.
	 * @param DateTimeImmutable    $now  This tick.
	 *
	 * @return bool
	 */
	private static function isDue(array $data, DateTimeImmutable $now): bool {
		$nextRun = trim((string)($data['nextRun'] ?? ''));
		if ($nextRun === '') {
			return true;
		}

		try {
			return new DateTimeImmutable($nextRun) <= $now;
		} catch (Throwable) {
			return true;
		}
	}//end isDue()

	/**
	 * Who a schedule runs as: its stored owner field, else the object's owner.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return string
	 */
	private static function ownerOf(ObjectEntity $schedule): string {
		$owner = trim((string)($schedule->getObject()['owner'] ?? ''));
		if ($owner !== '') {
			return $owner;
		}

		return (string)($schedule->getOwner() ?? '');
	}//end ownerOf()

	/**
	 * A threshold in percentage points, or null for the app default.
	 *
	 * @param mixed $value The stored or requested threshold.
	 *
	 * @return int|null
	 */
	private static function threshold(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return max(0, min(100, (int)$value));
	}//end threshold()

	/**
	 * A 0–1 pass rate as a whole percentage, or null.
	 *
	 * @param mixed $rate The rate.
	 *
	 * @return int|null
	 */
	private static function percent(mixed $rate): ?int {
		if (is_numeric($rate) === false) {
			return null;
		}

		return (int)round(((float)$rate) * 100);
	}//end percent()

	/**
	 * A schedule as the page shows it.
	 *
	 * @param ObjectEntity         $schedule The stored schedule.
	 * @param array<string, mixed> $data     Its fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function view(ObjectEntity $schedule, array $data): array {
		return [
			'id' => (string)$schedule->getUuid(),
			'datasetId' => (string)($data['datasetId'] ?? ''),
			'agentId' => (string)($data['agentId'] ?? ''),
			'cronExpr' => (string)($data['cronExpr'] ?? self::DEFAULT_CRON),
			'enabled' => (($data['enabled'] ?? false) === true),
			'nextRun' => ($data['nextRun'] ?? null),
			'lastRunId' => ($data['lastRunId'] ?? null),
			'lastRunAt' => ($data['lastRunAt'] ?? null),
			'regressionThresholdPercent' => ($data['regressionThresholdPercent'] ?? null),
		];
	}//end view()
}//end class
