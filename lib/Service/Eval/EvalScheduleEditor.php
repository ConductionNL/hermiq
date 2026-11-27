<?php

/**
 * Hermiq EvalScheduleEditor.
 *
 * "Run on a schedule" (reuse-scheduled-bias-evals, REQ-BIASEVAL-003): an owner lists,
 * adds and changes the eval schedules of a dataset they own, against an agent they
 * own. A schedule is stored as the caller, so `EvalScheduleTask` runs it as them.
 * Its next run is stored when the schedule is saved, so the page can show it and the
 * first run waits for the schedule instead of starting at the next tick.
 *
 * A dataset, agent or schedule the caller does not own reads as null (the controller's
 * 404); a cron expression that is not valid, or a threshold outside 0 to 100, is
 * refused with an InvalidArgumentException (400).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Eval
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

namespace OCA\Hermiq\Service\Eval;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Hermiq\Service\EvalScheduleClock;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lists and saves a dataset's eval schedules for its owner.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleEditor {

	/**
	 * The schedule schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'evalschedule';

	/**
	 * The dataset schema slug.
	 *
	 * @var string
	 */
	private const DATASET_SCHEMA = 'evaldataset';

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService Lists and stores schedules as the caller.
	 * @param EvalOwnership     $ownership     The owner rule for the dataset, agent and schedule.
	 * @param EvalScheduleClock $clock         Validates the cron expression and gives the next run.
	 * @param ITimeFactory      $time          The moment the next run is counted from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly EvalOwnership $ownership,
		private readonly EvalScheduleClock $clock,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The caller's schedules of a dataset they own.
	 *
	 * @param string $datasetId The dataset uuid.
	 * @param string $uid       The caller.
	 *
	 * @return array<int, array<string, mixed>>|null The schedules, or null when the caller does not own the dataset.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function listFor(string $datasetId, string $uid): ?array {
		if ($this->ownership->owned(schema: self::DATASET_SCHEMA, id: $datasetId, uid: $uid) === null) {
			return null;
		}

		$rows = $this->objectService->setRegister(EvalOwnership::REGISTER)->setSchema(self::SCHEMA)->findAll(
			config: ['filters' => ['datasetId' => $datasetId], 'limit' => 100]
		);

		$schedules = [];
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === false || ($row->getObject()['datasetId'] ?? '') !== $datasetId) {
				continue;
			}

			if ($this->ownership->owns(object: $row, uid: $uid) === true) {
				$schedules[] = self::view(schedule: $row);
			}
		}

		return $schedules;
	}//end listFor()

	/**
	 * Add a schedule to a dataset, or change one, for an agent the caller owns.
	 *
	 * @param string               $datasetId  The dataset uuid.
	 * @param string               $uid        The caller.
	 * @param array<string, mixed> $input      agentId, cronExpr, enabled, regressionThresholdPercent.
	 * @param string|null          $scheduleId The schedule to change; null adds one.
	 *
	 * @return array<string, mixed>|null The stored schedule, or null when the caller does not own
	 *                                   the dataset, the agent or the schedule.
	 *
	 * @throws InvalidArgumentException When the cron expression or the threshold is not valid.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function save(string $datasetId, string $uid, array $input, ?string $scheduleId = null): ?array {
		$dataset = $this->ownership->owned(schema: self::DATASET_SCHEMA, id: $datasetId, uid: $uid);
		$agent   = $this->ownership->ownedAgent(agentId: (string)($input['agentId'] ?? ''), uid: $uid);
		if ($dataset === null || $agent === null) {
			return null;
		}

		$stored = [];
		if ($scheduleId !== null) {
			$existing = $this->ownership->owned(schema: self::SCHEMA, id: $scheduleId, uid: $uid);
			if ($existing === null || ($existing->getObject()['datasetId'] ?? '') !== $datasetId) {
				return null;
			}

			$stored = $existing->getObject();
		}

		$data = array_merge(
			$this->scheduleFields(input: $input),
			['datasetId' => $datasetId, 'agentId' => (string)$agent->getUuid()]
		);
		if (isset($stored['lastRunId']) === true) {
			$data['lastRunId'] = $stored['lastRunId'];
		}

		$saved = $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: EvalOwnership::REGISTER, schema: self::SCHEMA),
				uuid: $scheduleId
			)
		);

		return self::view(schedule: $saved);
	}//end save()

	/**
	 * The validated cron expression, switch, threshold and next run of a save.
	 *
	 * @param array<string, mixed> $input The request fields.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the cron expression or the threshold is not valid.
	 */
	private function scheduleFields(array $input): array {
		$cronExpr = trim((string)($input['cronExpr'] ?? ''));
		if ($cronExpr === '') {
			$cronExpr = EvalScheduleClock::DEFAULT_CRON;
		}

		$now  = new DateTimeImmutable('@' . $this->time->getTime());
		$next = $this->clock->nextRun(cronExpr: $cronExpr, now: $now);
		if ($next === null) {
			throw new InvalidArgumentException('The schedule is not a valid cron expression.');
		}

		$fields = [
			'cronExpr' => $cronExpr,
			'enabled'  => filter_var(($input['enabled'] ?? true), FILTER_VALIDATE_BOOLEAN),
			'nextRun'  => $next->format(DateTimeInterface::ATOM),
		];

		$threshold = ($input['regressionThresholdPercent'] ?? null);
		if ($threshold === null || $threshold === '') {
			return $fields;
		}

		if (is_numeric($threshold) === false || (int)$threshold < 0 || (int)$threshold > 100) {
			throw new InvalidArgumentException('The regression threshold is a number of points from 0 to 100.');
		}

		$fields['regressionThresholdPercent'] = (int)$threshold;
		return $fields;
	}//end scheduleFields()

	/**
	 * What the page shows of a schedule.
	 *
	 * @param ObjectEntity $schedule The schedule.
	 *
	 * @return array<string, mixed>
	 */
	private static function view(ObjectEntity $schedule): array {
		$data = $schedule->getObject();

		return [
			'id'                         => (string)$schedule->getUuid(),
			'agentId'                    => (string)($data['agentId'] ?? ''),
			'cronExpr'                   => (string)($data['cronExpr'] ?? EvalScheduleClock::DEFAULT_CRON),
			'enabled'                    => (($data['enabled'] ?? false) === true),
			'nextRun'                    => ($data['nextRun'] ?? null),
			'lastRunId'                  => ($data['lastRunId'] ?? null),
			'regressionThresholdPercent' => ($data['regressionThresholdPercent'] ?? null),
		];
	}//end view()
}//end class
