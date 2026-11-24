<?php

/**
 * When a scheduled eval run is due, and when the next one is.
 *
 * Pure: no storage, no clock of its own. `EvalScheduleTask` asks it about one
 * `EvalSchedule` payload at a time, so due selection is tested without a job.
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
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Due selection and next-run computation for eval schedules.
 *
 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleClock {

	/**
	 * The default cadence: 06:00 UTC on the first of every month.
	 */
	public const DEFAULT_CRON = '0 6 1 * *';

	/**
	 * Whether a schedule should run now: enabled, and its next run has come.
	 *
	 * A schedule with no next run yet is due, so a new schedule runs on the first
	 * tick after it is enabled and then settles on its cadence. A next run that
	 * cannot be read is not due: running an eval on a guess costs model calls.
	 *
	 * @param array<string, mixed> $schedule The `EvalSchedule` payload.
	 * @param DateTimeImmutable    $now      The current moment.
	 *
	 * @return bool True when the schedule should run now.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function isDue(array $schedule, DateTimeImmutable $now): bool {
		if (($schedule['enabled'] ?? false) !== true) {
			return false;
		}

		$nextRun = $schedule['nextRun'] ?? null;
		if ($nextRun === null || $nextRun === '') {
			return true;
		}

		if (is_string(value: $nextRun) === false) {
			return false;
		}

		try {
			$moment = new DateTimeImmutable(datetime: $nextRun);
		} catch (Throwable) {
			return false;
		}

		return $moment <= $now;
	}//end isDue()

	/**
	 * The next run after now, in UTC, for a cron expression.
	 *
	 * @param string            $cronExpr The schedule's cron expression; empty means the default.
	 * @param DateTimeImmutable $now      The current moment.
	 *
	 * @return DateTimeImmutable|null The next run, or null when the expression is not valid cron.
	 *
	 * @spec openspec/changes/archive/2026-11-24-reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
	 */
	public function nextRun(string $cronExpr, DateTimeImmutable $now): ?DateTimeImmutable {
		$expression = trim(string: $cronExpr);
		if ($expression === '') {
			$expression = self::DEFAULT_CRON;
		}

		try {
			$cron = new CronExpression(expression: $expression);
		} catch (InvalidArgumentException) {
			return null;
		}

		$utc  = new DateTimeZone(timezone: 'UTC');
		$next = $cron->getNextRunDate(
			currentTime: $now->setTimezone(timezone: $utc),
			nth: 0,
			allowCurrentDate: false,
			timeZone: 'UTC'
		);

		return (new DateTimeImmutable(datetime: '@' . $next->getTimestamp()))->setTimezone(timezone: $utc);
	}//end nextRun()
}//end class
