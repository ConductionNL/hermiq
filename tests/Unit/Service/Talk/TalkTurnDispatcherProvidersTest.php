<?php

/**
 * Unit tests for how TalkTurnDispatcher reads the TaskProcessing providers:
 * whether a triggerable runner exists, and that a failing manager or runner
 * never loses the queued turn.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\BackgroundJob\TalkTurnJob;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCP\BackgroundJob\IJobList;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Provider detection and the failure paths of the hand-off.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnDispatcher
 */
class TalkTurnDispatcherProvidersTest extends TestCase {

	/**
	 * Core's triggerable-provider interface, matched by name.
	 *
	 * @var string
	 */
	private const TRIGGERABLE_PROVIDER = 'OCP\\TaskProcessing\\ITriggerableProvider';

	/**
	 * A mock of the triggerable-provider interface, declared here when the pinned OCP lacks it.
	 *
	 * A mock rather than a hand-written class, because on a real Nextcloud the
	 * interface extends IProvider and a class declaring only trigger() fatals.
	 *
	 * @return object
	 */
	private function triggerable(): object {
		if (interface_exists(self::TRIGGERABLE_PROVIDER) === false) {
			eval('namespace OCP\\TaskProcessing; interface ITriggerableProvider { public function trigger(): void; }');
		}

		return $this->createMock(originalClassName: self::TRIGGERABLE_PROVIDER);

	}//end triggerable()

	/**
	 * A TaskProcessing manager answering the given providers.
	 *
	 * @param array $providers The providers.
	 *
	 * @return ITaskProcessingManager
	 */
	private function manager(array $providers): ITaskProcessingManager {
		$manager = $this->createMock(ITaskProcessingManager::class);
		$manager->method('getProviders')->willReturn($providers);
		return $manager;

	}//end manager()

	/**
	 * Without a TaskProcessing manager there is no triggerable runner.
	 *
	 * @return void
	 */
	public function testWithoutAManagerNothingIsTriggerable(): void {
		$dispatcher = new TalkTurnDispatcher($this->createMock(IJobList::class), $this->createMock(LoggerInterface::class));

		self::assertFalse($dispatcher->hasTriggerableProvider());

	}//end testWithoutAManagerNothingIsTriggerable()

	/**
	 * Only a provider of the triggerable interface counts.
	 *
	 * @return void
	 */
	public function testOnlyATriggerableProviderCounts(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$jobs = $this->createMock(IJobList::class);

		self::assertFalse((new TalkTurnDispatcher($jobs, $logger, $this->manager([new stdClass()])))->hasTriggerableProvider());
		self::assertFalse((new TalkTurnDispatcher($jobs, $logger, $this->manager([])))->hasTriggerableProvider());
		self::assertTrue((new TalkTurnDispatcher($jobs, $logger, $this->manager([new stdClass(), $this->triggerable()])))->hasTriggerableProvider());

	}//end testOnlyATriggerableProviderCounts()

	/**
	 * A manager that cannot list its providers reads as no triggerable runner, and says why.
	 *
	 * @return void
	 */
	public function testAFailingManagerReadsAsNotTriggerable(): void {
		$manager = $this->createMock(ITaskProcessingManager::class);
		$manager->method('getProviders')->willThrowException(new RuntimeException('providers unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[TalkTurnDispatcher] Could not enumerate TaskProcessing providers; using the queued fallback',
			$this->callback(static fn (array $context): bool => $context['error'] === 'providers unavailable')
		);

		self::assertFalse((new TalkTurnDispatcher($this->createMock(IJobList::class), $logger, $manager))->hasTriggerableProvider());

	}//end testAFailingManagerReadsAsNotTriggerable()

	/**
	 * A runner whose trigger throws leaves the turn queued and is logged.
	 *
	 * @return void
	 */
	public function testAFailingTriggerLeavesTheTurnQueued(): void {
		$provider = $this->triggerable();
		$provider->expects($this->once())->method('trigger')->willThrowException(new RuntimeException('runner down'));

		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(
			TalkTurnJob::class,
			['conversationUuid' => 'c-1', 'speakerUid' => 'ada', 'message' => 'Hi', 'roomToken' => 'room-1']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with(
			'[TalkTurnDispatcher] Could not nudge a triggerable runner; the queued turn stands',
			$this->callback(static fn (array $context): bool => $context['error'] === 'runner down')
		);

		$path = (new TalkTurnDispatcher($jobs, $logger, $this->manager([$provider])))->dispatch(
			conversationUuid: 'c-1',
			speakerUid: 'ada',
			message: 'Hi',
			roomToken: 'room-1'
		);

		self::assertSame('queued', $path);

	}//end testAFailingTriggerLeavesTheTurnQueued()

	/**
	 * Every triggerable runner is nudged, and other providers are skipped.
	 *
	 * @return void
	 */
	public function testEveryTriggerableRunnerIsNudged(): void {
		$first = $this->triggerable();
		$first->expects($this->once())->method('trigger');
		$second = $this->triggerable();
		$second->expects($this->once())->method('trigger');

		$path = (new TalkTurnDispatcher(
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class),
			$this->manager([$first, new stdClass(), $second])
		))->dispatch(conversationUuid: 'c-1', speakerUid: 'ada', message: 'Hi', roomToken: 'room-1');

		self::assertSame('triggered', $path);

	}//end testEveryTriggerableRunnerIsNudged()
}//end class
