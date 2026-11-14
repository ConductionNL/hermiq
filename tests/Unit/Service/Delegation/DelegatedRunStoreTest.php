<?php

/**
 * Hermiq DelegatedRunStoreTest
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

use OCA\Hermiq\Service\Delegation\DelegatedRunStore;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A part runs once: a claim moves it from queued to running under its lock.
 */
#[CoversClass(DelegatedRunStore::class)]
#[UsesClass(\OCA\Hermiq\Service\Delegation\PollClock::class)]
final class DelegatedRunStoreTest extends TestCase {

	/**
	 * @var DelegatedRunsDouble
	 */
	private DelegatedRunsDouble $runs;

	/**
	 * @var ILockingProvider&MockObject
	 */
	private ILockingProvider&MockObject $locking;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->runs    = new DelegatedRunsDouble();
		$this->locking = $this->createMock(ILockingProvider::class);
	}//end setUp()

	/**
	 * @return DelegatedRunStore
	 */
	private function store(): DelegatedRunStore {
		return new DelegatedRunStore(
			objectService: $this->runs->objectService(test: $this),
			locking: $this->locking,
			clock: $this->runs->clock(test: $this)
		);
	}//end store()

	/**
	 * Queueing writes the part as queued with the time.
	 *
	 * @return void
	 */
	public function testQueueWritesAQueuedPart(): void {
		$id = $this->store()->queue(record: ['targetAgentId' => 'agent-b', 'task' => 'Sum it up', 'state' => 'done']);

		self::assertSame('part-1', $id);
		self::assertSame('queued', $this->runs->records[$id]['state']);
		self::assertSame('agent-b', $this->runs->records[$id]['targetAgentId']);
		self::assertSame(gmdate('Y-m-d\TH:i:s\Z', 1000), $this->runs->records[$id]['queuedAt']);
	}//end testQueueWritesAQueuedPart()

	/**
	 * A claim of a queued part wins once; the second claim loses.
	 *
	 * @return void
	 */
	public function testAQueuedPartIsClaimedOnce(): void {
		$store = $this->store();
		$id    = $store->queue(record: ['targetAgentId' => 'agent-b']);
		$this->locking->expects($this->exactly(2))->method('acquireLock')->with('hermiq/delegated-run/'.$id, ILockingProvider::LOCK_EXCLUSIVE);
		$this->locking->expects($this->exactly(2))->method('releaseLock')->with('hermiq/delegated-run/'.$id, ILockingProvider::LOCK_EXCLUSIVE);

		$claimed = $store->claim(id: $id, claimant: 'worker');

		self::assertSame('running', $claimed['state']);
		self::assertSame('worker', $claimed['claimedBy']);
		self::assertSame($id, $claimed['id']);
		self::assertSame('running', $this->runs->records[$id]['state']);
		self::assertNull($store->claim(id: $id, claimant: 'parent'));
		self::assertSame('worker', $this->runs->records[$id]['claimedBy']);
	}//end testAQueuedPartIsClaimedOnce()

	/**
	 * A part whose lock another claim holds is not claimed, and its record is untouched.
	 *
	 * @return void
	 */
	public function testAClaimThatFindsTheLockHeldLoses(): void {
		$store = $this->store();
		$id    = $store->queue(record: ['targetAgentId' => 'agent-b']);
		$this->locking->method('acquireLock')->willThrowException(new LockedException('hermiq/delegated-run/'.$id));
		$this->locking->expects($this->never())->method('releaseLock');

		self::assertNull($store->claim(id: $id, claimant: 'parent'));
		self::assertSame('queued', $this->runs->records[$id]['state']);
	}//end testAClaimThatFindsTheLockHeldLoses()

	/**
	 * A part that is gone cannot be claimed or read.
	 *
	 * @return void
	 */
	public function testAMissingPartIsNotClaimed(): void {
		self::assertNull($this->store()->claim(id: 'nope', claimant: 'worker'));
		self::assertNull($this->store()->read(id: 'nope'));
	}//end testAMissingPartIsNotClaimed()

	/**
	 * Finishing a running part records its outcome and the end time.
	 *
	 * @return void
	 */
	public function testFinishRecordsTheOutcomeOfARunningPart(): void {
		$store = $this->store();
		$id    = $store->queue(record: ['targetAgentId' => 'agent-b']);
		$store->claim(id: $id, claimant: 'worker');
		$this->runs->now = 1060;

		$store->finish(id: $id, fields: ['state' => 'done', 'result' => 'Three findings.', 'runId' => 'run-b']);

		self::assertSame('done', $this->runs->records[$id]['state']);
		self::assertSame('Three findings.', $this->runs->records[$id]['result']);
		self::assertSame(gmdate('Y-m-d\TH:i:s\Z', 1060), $this->runs->records[$id]['endedAt']);
	}//end testFinishRecordsTheOutcomeOfARunningPart()

	/**
	 * A part that timed out stays timed out when its run ends late.
	 *
	 * @return void
	 */
	public function testATimedOutPartStaysTimedOut(): void {
		$store = $this->store();
		$id    = $store->queue(record: ['targetAgentId' => 'agent-b']);
		$store->claim(id: $id, claimant: 'worker');

		self::assertTrue($store->timeOut(id: $id));
		$store->finish(id: $id, fields: ['state' => 'done', 'result' => 'Late.']);

		self::assertSame('timed-out', $this->runs->records[$id]['state']);
		self::assertArrayNotHasKey('result', $this->runs->records[$id]);
		self::assertFalse($store->timeOut(id: $id));
	}//end testATimedOutPartStaysTimedOut()

	/**
	 * A part that is done does not time out.
	 *
	 * @return void
	 */
	public function testADonePartDoesNotTimeOut(): void {
		$store = $this->store();
		$id    = $store->queue(record: ['targetAgentId' => 'agent-b']);
		$store->claim(id: $id, claimant: 'worker');
		$store->finish(id: $id, fields: ['state' => 'done', 'result' => 'ok']);

		self::assertFalse($store->timeOut(id: $id));
		self::assertSame('done', $this->runs->records[$id]['state']);
	}//end testADonePartDoesNotTimeOut()
}//end class
