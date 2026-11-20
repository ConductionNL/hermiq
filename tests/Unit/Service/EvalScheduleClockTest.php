<?php

/**
 * Due selection and the next run of an eval schedule (reuse-scheduled-bias-evals, task 3).
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
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Hermiq\Service\EvalScheduleClock;
use PHPUnit\Framework\TestCase;

/**
 * EvalScheduleClock.
 *
 * @spec openspec/changes/reuse-scheduled-bias-evals/specs/agent-evals/spec.md#requirement-a-dataset-can-run-against-an-agent-on-a-schedule-req-biaseval-003
 */
class EvalScheduleClockTest extends TestCase {

	/**
	 * A fixed moment: 1 December 2026, 07:00 UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable(datetime: '2026-12-01T07:00:00+00:00');
	}//end now()

	/**
	 * An enabled schedule whose next run has passed is due; one in the future is not.
	 *
	 * @return void
	 */
	public function testAPassedNextRunIsDueAndAFutureOneIsNot(): void {
		$clock = new EvalScheduleClock();

		$this->assertTrue(condition: $clock->isDue(schedule: ['enabled' => true, 'nextRun' => '2026-12-01T06:00:00+00:00'], now: $this->now()));
		$this->assertTrue(condition: $clock->isDue(schedule: ['enabled' => true, 'nextRun' => '2026-12-01T07:00:00+00:00'], now: $this->now()));
		$this->assertFalse(condition: $clock->isDue(schedule: ['enabled' => true, 'nextRun' => '2027-01-01T06:00:00+00:00'], now: $this->now()));
	}//end testAPassedNextRunIsDueAndAFutureOneIsNot()

	/**
	 * A disabled schedule is never due, and enabled must be a real true.
	 *
	 * @return void
	 */
	public function testADisabledScheduleIsNeverDue(): void {
		$clock = new EvalScheduleClock();

		$this->assertFalse(condition: $clock->isDue(schedule: ['enabled' => false, 'nextRun' => '2026-01-01T00:00:00+00:00'], now: $this->now()));
		$this->assertFalse(condition: $clock->isDue(schedule: ['nextRun' => '2026-01-01T00:00:00+00:00'], now: $this->now()));
		$this->assertFalse(condition: $clock->isDue(schedule: ['enabled' => 'yes'], now: $this->now()));
	}//end testADisabledScheduleIsNeverDue()

	/**
	 * A schedule without a next run is due; an unreadable next run is not.
	 *
	 * @return void
	 */
	public function testNoNextRunIsDueAndAnUnreadableOneIsNot(): void {
		$clock = new EvalScheduleClock();

		$this->assertTrue(condition: $clock->isDue(schedule: ['enabled' => true], now: $this->now()));
		$this->assertTrue(condition: $clock->isDue(schedule: ['enabled' => true, 'nextRun' => ''], now: $this->now()));
		$this->assertFalse(condition: $clock->isDue(schedule: ['enabled' => true, 'nextRun' => 'not a date'], now: $this->now()));
		$this->assertFalse(condition: $clock->isDue(schedule: ['enabled' => true, 'nextRun' => 42], now: $this->now()));
	}//end testNoNextRunIsDueAndAnUnreadableOneIsNot()

	/**
	 * The default cadence is monthly at 06:00 UTC on the first.
	 *
	 * @return void
	 */
	public function testTheDefaultCadenceIsTheFirstOfTheNextMonth(): void {
		$next = (new EvalScheduleClock())->nextRun(cronExpr: '', now: $this->now());

		$this->assertNotNull(actual: $next);
		$this->assertSame(expected: '2027-01-01T06:00:00+00:00', actual: $next->format(DATE_ATOM));
	}//end testTheDefaultCadenceIsTheFirstOfTheNextMonth()

	/**
	 * A given cron expression is honoured, strictly after now.
	 *
	 * @return void
	 */
	public function testACronExpressionIsHonouredStrictlyAfterNow(): void {
		$next = (new EvalScheduleClock())->nextRun(cronExpr: '0 7 * * *', now: $this->now());

		$this->assertNotNull(actual: $next);
		$this->assertSame(expected: '2026-12-02T07:00:00+00:00', actual: $next->format(DATE_ATOM));
	}//end testACronExpressionIsHonouredStrictlyAfterNow()

	/**
	 * An expression that is not cron has no next run.
	 *
	 * @return void
	 */
	public function testAnInvalidExpressionHasNoNextRun(): void {
		$this->assertNull(actual: (new EvalScheduleClock())->nextRun(cronExpr: 'every month', now: $this->now()));
	}//end testAnInvalidExpressionHasNoNextRun()
}//end class
