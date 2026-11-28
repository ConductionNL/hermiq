<?php

/**
 * Unit tests for OccurrenceClaim.
 *
 * The claim is OpenRegister's advisory lock on the schedule: taken once, refused to a
 * second holder, released, and judged stale only past its time-to-live.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Run
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Run;

use DateTimeImmutable;
use OCA\Hermiq\Service\Run\OccurrenceClaim;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Kernel\Contracts\Value\LockRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\UnlockRequest;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/specs/agent-schedule/spec.md#requirement-each-occurrence-is-claimed-once-whatever-the-number-of-workers-req-prun-001
 */
#[CoversClass(OccurrenceClaim::class)]
final class OccurrenceClaimTest extends TestCase {

	private ObjectService&MockObject $objectService;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function claim(): OccurrenceClaim {
		return new OccurrenceClaim(objectService: $this->objectService, logger: $this->logger);
	}//end claim()

	/**
	 * The claim takes the advisory lock on the schedule, with the occurrence as its process and the time-to-live as its duration.
	 *
	 * @return void
	 */
	public function testTheClaimTakesTheAdvisoryLockOnTheSchedule(): void {
		$seen = [];
		$this->objectService->expects($this->once())->method('lockObject')->willReturnCallback(
			static function (LockRequest $request, bool $advisory = false) use (&$seen): array {
				$seen = [$request->identifier, $request->process, $request->duration, $advisory];
				return ['uuid' => $request->identifier];
			}
		);

		$this->assertTrue($this->claim()->claim(scheduleUuid: 'sched-1', occurrenceKey: 'sched-1:2026-09-28T02:00:00+00:00', ttlSeconds: 1800));
		$this->assertSame(['hermiq-schedule-run:sched-1', 'sched-1:2026-09-28T02:00:00+00:00', 1800, true], $seen);
	}//end testTheClaimTakesTheAdvisoryLockOnTheSchedule()

	/**
	 * A lock another worker holds is a lost claim, quietly.
	 *
	 * @return void
	 */
	public function testAHeldLockIsALostClaim(): void {
		$this->objectService->method('lockObject')->willThrowException(new LockedException('held'));
		$this->logger->expects($this->never())->method('warning');

		$this->assertFalse($this->claim()->claim(scheduleUuid: 'sched-1', occurrenceKey: 'k', ttlSeconds: 1800));
	}//end testAHeldLockIsALostClaim()

	/**
	 * A lock that fails for another reason is not taken either, and says so.
	 *
	 * @return void
	 */
	public function testAFailedLockIsNotTakenAndIsLogged(): void {
		$this->objectService->method('lockObject')->willThrowException(new RuntimeException('appconfig down'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not be taken'),
			$this->callback(static fn (array $context): bool => $context['error'] === 'appconfig down' && $context['schedule'] === 'sched-1')
		);

		$this->assertFalse($this->claim()->claim(scheduleUuid: 'sched-1', occurrenceKey: 'k', ttlSeconds: 1800));
	}//end testAFailedLockIsNotTakenAndIsLogged()

	/**
	 * A schedule without a uuid cannot be claimed and nothing is locked.
	 *
	 * @return void
	 */
	public function testABlankScheduleIsNeverClaimed(): void {
		$this->objectService->expects($this->never())->method('lockObject');

		$this->assertFalse($this->claim()->claim(scheduleUuid: '  ', occurrenceKey: 'k', ttlSeconds: 1800));
	}//end testABlankScheduleIsNeverClaimed()

	/**
	 * The release drops the advisory lock, and a failed release is only logged.
	 *
	 * @return void
	 */
	public function testTheReleaseDropsTheAdvisoryLock(): void {
		$seen = [];
		$this->objectService->expects($this->exactly(2))->method('unlockObject')->willReturnCallback(
			static function (UnlockRequest $request) use (&$seen): bool {
				$seen[] = [$request->identifier, $request->advisory];
				if (count($seen) === 2) {
					throw new RuntimeException('gone');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning');

		$this->claim()->release(scheduleUuid: 'sched-1');
		$this->claim()->release(scheduleUuid: 'sched-2');

		$this->assertSame([['hermiq-schedule-run:sched-1', true], ['hermiq-schedule-run:sched-2', true]], $seen);
	}//end testTheReleaseDropsTheAdvisoryLock()

	/**
	 * The claim is available on an OpenRegister whose lockObject takes a LockRequest and the advisory flag.
	 *
	 * @return void
	 */
	public function testTheClaimIsAvailableWithTheAdvisoryLockApi(): void {
		$this->assertTrue($this->claim()->isAvailable());
	}//end testTheClaimIsAvailableWithTheAdvisoryLockApi()

	/**
	 * A fresh occurrence is keyed on its due time, a retry on its due time and attempt.
	 *
	 * @return void
	 */
	public function testTheKeyNamesTheDueTimeAndARetryAttempt(): void {
		$this->assertSame(
			'sched-1:2026-09-28T02:00:00+00:00',
			OccurrenceClaim::keyFor(scheduleUuid: 'sched-1', data: ['nextRun' => '2026-09-28T02:00:00+00:00'])
		);
		$this->assertSame(
			'sched-1:2026-09-28T02:05:00+00:00:a3',
			OccurrenceClaim::keyFor(
				scheduleUuid: 'sched-1',
				data: [
					'nextRun' => '2026-09-29T02:00:00+00:00',
					'retryEnabled' => true,
					'retryState' => ['attempt' => 2, 'nextAttemptAt' => '2026-09-28T02:05:00+00:00'],
				]
			)
		);
		// A retry state without retries switched on is a fresh occurrence.
		$this->assertSame(
			'sched-1:2026-09-29T02:00:00+00:00',
			OccurrenceClaim::keyFor(
				scheduleUuid: 'sched-1',
				data: ['nextRun' => '2026-09-29T02:00:00+00:00', 'retryState' => ['attempt' => 2, 'nextAttemptAt' => 'x']]
			)
		);
	}//end testTheKeyNamesTheDueTimeAndARetryAttempt()

	/**
	 * An occurrence in flight is stale only past its time-to-live, or with no time recorded.
	 *
	 * @return void
	 */
	public function testAnOccurrenceIsStaleOnlyPastItsTimeToLive(): void {
		$now = new DateTimeImmutable('2026-09-28T03:00:00+00:00');
		$inFlight = ['pendingOccurrence' => 'k', 'lastStatus' => 'running'];

		$this->assertFalse(OccurrenceClaim::isStale(data: $inFlight + ['pendingSince' => '2026-09-28T02:31:00+00:00'], now: $now, ttlSeconds: 1800));
		$this->assertTrue(OccurrenceClaim::isStale(data: $inFlight + ['pendingSince' => '2026-09-28T02:29:59+00:00'], now: $now, ttlSeconds: 1800));
		$this->assertTrue(OccurrenceClaim::isStale(data: ['pendingOccurrence' => 'k', 'lastStatus' => 'queued'], now: $now, ttlSeconds: 1800));
		// Nothing in flight, or an outcome recorded: never stale.
		$this->assertFalse(OccurrenceClaim::isStale(data: ['lastStatus' => 'running', 'pendingSince' => '2000-01-01T00:00:00+00:00'], now: $now, ttlSeconds: 1800));
		$this->assertFalse(OccurrenceClaim::isStale(data: ['pendingOccurrence' => 'k', 'lastStatus' => 'ok', 'pendingSince' => '2000-01-01T00:00:00+00:00'], now: $now, ttlSeconds: 1800));
	}//end testAnOccurrenceIsStaleOnlyPastItsTimeToLive()

	/**
	 * A live run is running, in flight and within its time-to-live.
	 *
	 * @return void
	 */
	public function testALiveRunIsRunningWithinItsTimeToLive(): void {
		$now = new DateTimeImmutable('2026-09-28T03:00:00+00:00');

		$this->assertTrue(OccurrenceClaim::isLiveRun(data: ['pendingOccurrence' => 'k', 'lastStatus' => 'running', 'pendingSince' => '2026-09-28T02:50:00+00:00'], now: $now, ttlSeconds: 1800));
		$this->assertFalse(OccurrenceClaim::isLiveRun(data: ['pendingOccurrence' => 'k', 'lastStatus' => 'queued', 'pendingSince' => '2026-09-28T02:50:00+00:00'], now: $now, ttlSeconds: 1800));
		$this->assertFalse(OccurrenceClaim::isLiveRun(data: ['pendingOccurrence' => 'k', 'lastStatus' => 'running', 'pendingSince' => '2026-09-28T01:00:00+00:00'], now: $now, ttlSeconds: 1800));
		$this->assertFalse(OccurrenceClaim::isLiveRun(data: ['pendingOccurrence' => 'k', 'lastStatus' => 'running', 'pendingSince' => 'not a date'], now: $now, ttlSeconds: 1800));
		$this->assertSame('', OccurrenceClaim::pendingOf(data: ['pendingOccurrence' => ['not' => 'a key']]));
	}//end testALiveRunIsRunningWithinItsTimeToLive()
}//end class
