<?php

/**
 * Hermiq ParallelDelegationTest
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Delegation;

use OCA\Hermiq\BackgroundJob\DelegatedRunJob;
use OCA\Hermiq\Service\Delegation\DelegatedRunStore;
use OCA\Hermiq\Service\Delegation\ParallelDelegation;
use OCA\Hermiq\Service\Delegation\PollClock;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-DLGPAR-001/002 on a fake clock: parts handed to workers, a grace period
 * after which the parent runs a part itself, and a timeout.
 */
#[CoversClass(ParallelDelegation::class)]
#[UsesClass(DelegatedRunStore::class)]
#[UsesClass(PollClock::class)]
final class ParallelDelegationTest extends TestCase {

	/**
	 * @var DelegatedRunsDouble
	 */
	private DelegatedRunsDouble $runs;

	/**
	 * @var DelegatedRunStore
	 */
	private DelegatedRunStore $store;

	/**
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * Arguments of the queued jobs, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $queued = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->runs    = new DelegatedRunsDouble();
		$this->store   = new DelegatedRunStore(
			objectService: $this->runs->objectService(test: $this),
			locking: $this->createMock(ILockingProvider::class),
			clock: $this->runs->clock(test: $this)
		);
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(
			function (string $job, array $argument): void {
				self::assertSame(DelegatedRunJob::class, $job);
				$this->queued[] = $argument;
			}
		);
	}//end setUp()

	/**
	 * The service under test, with the given settings.
	 *
	 * @param array<string, string> $settings App config values.
	 *
	 * @return ParallelDelegation
	 */
	private function parallel(array $settings = []): ParallelDelegation {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($settings[$key] ?? $default)
		);

		return new ParallelDelegation(store: $this->store, jobList: $this->jobList, clock: $this->runs->clock(test: $this), appConfig: $appConfig);
	}//end parallel()

	/**
	 * A worker that, on each poll pause, claims every queued part and finishes it.
	 *
	 * @param int $seconds How long each part takes once claimed.
	 *
	 * @return array<string, int> When each part was claimed, by id (filled as it runs).
	 */
	private function workers(int $seconds): \ArrayObject {
		$claimedAt = new \ArrayObject();
		$this->runs->onWait = function (int $now) use ($claimedAt, $seconds): void {
			foreach ($this->runs->records as $id => $record) {
				if ($record['state'] === 'queued' && $this->store->claim(id: $id, claimant: 'worker') !== null) {
					$claimedAt[$id] = $now;
				}

				if ($record['state'] === 'running' && isset($claimedAt[$id]) === true && $now - $claimedAt[$id] >= $seconds) {
					$this->store->finish(id: $id, fields: ['state' => 'done', 'result' => 'Result of '.$record['targetAgentId']]);
				}
			}
		};

		return $claimedAt;
	}//end workers()

	/**
	 * Three parts on workers overlap and come back in call order.
	 *
	 * @return void
	 */
	public function testThreePartsRunAtTheSameTimeAndAnswerInCallOrder(): void {
		$claimedAt = $this->workers(seconds: 60);
		$inline    = 0;

		$outcomes = $this->parallel()->run(
			records: [0 => ['targetAgentId' => 'B', 'effectiveReach' => 'instance'], 1 => ['targetAgentId' => 'C', 'effectiveReach' => 'instance'], 2 => ['targetAgentId' => 'D', 'effectiveReach' => 'external']],
			runInline: function () use (&$inline): array {
				$inline++;
				return [];
			}
		);

		self::assertSame([0, 1, 2], array_keys($outcomes));
		self::assertSame(['result' => 'Result of B', 'effectiveReach' => 'instance'], $outcomes[0]);
		self::assertSame('Result of D', $outcomes[2]['result']);
		self::assertSame('external', $outcomes[2]['effectiveReach']);
		self::assertSame(0, $inline);
		self::assertCount(3, $this->queued);
		self::assertSame(['delegatedRunId' => 'part-1'], $this->queued[0]);
		// All three were claimed in the same second, so their runs overlap.
		self::assertSame(1, count(array_unique($claimedAt->getArrayCopy())));
		// About the time of the slowest part, not the sum.
		self::assertLessThan(1000 + 70, $this->runs->now);
	}//end testThreePartsRunAtTheSameTimeAndAnswerInCallOrder()

	/**
	 * With no worker the parent claims each part after the grace period and runs it itself.
	 *
	 * @return void
	 */
	public function testWithoutAWorkerTheParentRunsThePartsAfterTheGracePeriod(): void {
		$ranAt = [];

		$outcomes = $this->parallel()->run(
			records: [0 => ['targetAgentId' => 'B'], 1 => ['targetAgentId' => 'C']],
			runInline: function (array $claimed) use (&$ranAt): array {
				$ranAt[] = [$claimed['targetAgentId'], $claimed['claimedBy'], $this->runs->now];
				$this->store->finish(id: $claimed['id'], fields: ['state' => 'done', 'result' => 'inline']);
				return ['result' => 'inline '.$claimed['targetAgentId'], 'effectiveReach' => 'instance'];
			}
		);

		self::assertSame([['B', 'parent', 1010], ['C', 'parent', 1010]], $ranAt);
		self::assertSame('inline B', $outcomes[0]['result']);
		self::assertSame('inline C', $outcomes[1]['result']);
		self::assertSame('done', $this->runs->records['part-1']['state']);
	}//end testWithoutAWorkerTheParentRunsThePartsAfterTheGracePeriod()

	/**
	 * A worker that claims the part as the grace period ends wins; the parent does not run it.
	 *
	 * @return void
	 */
	public function testAWorkerThatClaimsFirstWinsTheRace(): void {
		$this->runs->onWait = function (int $now): void {
			if ($now === 1010) {
				$this->store->claim(id: 'part-1', claimant: 'worker');
			}

			if ($now === 1012) {
				$this->store->finish(id: 'part-1', fields: ['state' => 'done', 'result' => 'from the worker']);
			}
		};

		$outcomes = $this->parallel()->run(
			records: [0 => ['targetAgentId' => 'B']],
			runInline: static fn (): array => self::fail('The parent must not run a part a worker claimed.')
		);

		self::assertSame('from the worker', $outcomes[0]['result']);
		self::assertSame('worker', $this->runs->records['part-1']['claimedBy']);
	}//end testAWorkerThatClaimsFirstWinsTheRace()

	/**
	 * A part still running at the timeout returns delegation_timeout and is recorded as timed out.
	 *
	 * @return void
	 */
	public function testAPartStillRunningAtTheTimeoutTimesOut(): void {
		$this->workers(seconds: 10000);

		$outcomes = $this->parallel(settings: ['delegation.parallelTimeoutSeconds' => '30'])->run(
			records: [0 => ['targetAgentId' => 'B']],
			runInline: static fn (): array => []
		);

		self::assertSame(ParallelDelegation::TIMEOUT_CODE, $outcomes[0]['error']['code']);
		self::assertSame('timed-out', $this->runs->records['part-1']['state']);
		self::assertSame(1030, $this->runs->now);
	}//end testAPartStillRunningAtTheTimeoutTimesOut()

	/**
	 * A failed part answers delegation_failed and the others still answer.
	 *
	 * @return void
	 */
	public function testAFailedPartDoesNotStopTheOthers(): void {
		$this->runs->onWait = function (): void {
			foreach (array_keys($this->runs->records) as $id) {
				if ($this->store->claim(id: $id, claimant: 'worker') !== null) {
					$this->store->finish(id: $id, fields: ($id === 'part-1' ? ['state' => 'failed'] : ['state' => 'done', 'result' => 'ok']));
				}
			}
		};

		$outcomes = $this->parallel()->run(records: [0 => ['targetAgentId' => 'B'], 1 => ['targetAgentId' => 'C']], runInline: static fn (): array => []);

		self::assertSame('delegation_failed', $outcomes[0]['error']['code']);
		self::assertSame('ok', $outcomes[1]['result']);
	}//end testAFailedPartDoesNotStopTheOthers()

	/**
	 * At most delegation.maxParallel parts are handed out at once; the rest wait for a free slot.
	 *
	 * @return void
	 */
	public function testMaxParallelBoundsThePartsHandedOutAtOnce(): void {
		$handedOut = [];
		$this->workers(seconds: 5);
		$probe = $this->runs->onWait;
		$this->runs->onWait = function (int $now) use ($probe, &$handedOut): void {
			$handedOut[$now] = count($this->queued);
			$probe($now);
		};

		$outcomes = $this->parallel(settings: ['delegation.maxParallel' => '2'])->run(
			records: [0 => ['targetAgentId' => 'B'], 1 => ['targetAgentId' => 'C'], 2 => ['targetAgentId' => 'D']],
			runInline: static fn (): array => []
		);

		self::assertSame(2, $handedOut[1001]);
		self::assertSame(3, max($handedOut));
		self::assertSame(['Result of B', 'Result of C', 'Result of D'], array_column($outcomes, 'result'));
		self::assertSame(2, $this->parallel(settings: ['delegation.maxParallel' => '2'])->maxParallel());
		self::assertSame(3, $this->parallel(settings: ['delegation.maxParallel' => '0'])->maxParallel());
	}//end testMaxParallelBoundsThePartsHandedOutAtOnce()

	/**
	 * A part whose record disappeared answers delegation_failed.
	 *
	 * @return void
	 */
	public function testAPartWhoseRecordIsGoneFails(): void {
		$this->runs->onWait = function (): void {
			$this->runs->records = [];
		};

		$outcomes = $this->parallel()->run(records: [0 => ['targetAgentId' => 'B']], runInline: static fn (): array => []);

		self::assertSame('delegation_failed', $outcomes[0]['error']['code']);
	}//end testAPartWhoseRecordIsGoneFails()
}//end class
