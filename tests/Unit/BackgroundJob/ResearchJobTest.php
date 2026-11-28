<?php

/**
 * Unit tests for ResearchJob and ResearchAsUser (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\ResearchJob;
use OCA\Hermiq\Service\Handoff\HandoffService;
use OCA\Hermiq\Service\Research\ResearchAsUser;
use OCA\Hermiq\Service\Research\ResearchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\BackgroundJob\ResearchJob
 * @covers \OCA\Hermiq\Service\Research\ResearchAsUser
 */
final class ResearchJobTest extends TestCase {

	/**
	 * The runner.
	 *
	 * @var ResearchRunner&MockObject
	 */
	private ResearchRunner&MockObject $runner;

	/**
	 * The handoffs.
	 *
	 * @var HandoffService&MockObject
	 */
	private HandoffService&MockObject $handoffs;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The users the session was set to, in order.
	 *
	 * @var list<IUser|null>
	 */
	private array $set = [];

	/**
	 * Doubles for the runner, the handoffs and the session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->runner   = $this->createMock(originalClassName: ResearchRunner::class);
		$this->handoffs = $this->createMock(originalClassName: HandoffService::class);
		$this->users    = $this->createMock(originalClassName: IUserManager::class);
		$this->session  = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('setUser')->willReturnCallback(function (?IUser $user): void {
			$this->set[] = $user;
		});
	}//end setUp()

	/**
	 * The run runs as the person who started it, and the prior session comes back.
	 *
	 * @return void
	 */
	public function testTheRunRunsAsThePersonWhoStartedItAndTheSessionIsRestored(): void {
		$anne  = $this->createMock(originalClassName: IUser::class);
		$prior = $this->createMock(originalClassName: IUser::class);
		$this->handoffs->method('find')->with('r-1')->willReturn(['requestedBy' => 'anne']);
		$this->users->method('get')->with('anne')->willReturn($anne);
		$this->session->method('getUser')->willReturn($prior);
		$this->runner->expects(self::once())->method('run')->with('r-1')->willReturnCallback(function () use ($anne): string {
			self::assertSame(expected: [$anne], actual: $this->set);
			return 'done';
		});

		$this->asUser()->run(handoffId: 'r-1');

		self::assertSame(expected: [$anne, $prior], actual: $this->set);
	}//end testTheRunRunsAsThePersonWhoStartedItAndTheSessionIsRestored()

	/**
	 * The session comes back also when the run throws.
	 *
	 * @return void
	 */
	public function testTheSessionIsRestoredWhenTheRunThrows(): void {
		$anne = $this->createMock(originalClassName: IUser::class);
		$this->handoffs->method('find')->willReturn(['requestedBy' => 'anne']);
		$this->users->method('get')->willReturn($anne);
		$this->session->method('getUser')->willReturn(null);
		$this->runner->method('run')->willThrowException(new RuntimeException('boom'));

		try {
			$this->asUser()->run(handoffId: 'r-1');
			self::fail(message: 'The run\'s exception should surface.');
		} catch (RuntimeException $e) {
			self::assertSame(expected: [$anne, null], actual: $this->set);
		}
	}//end testTheSessionIsRestoredWhenTheRunThrows()

	/**
	 * Nothing runs without the handoff or its person.
	 *
	 * @return void
	 */
	public function testNothingRunsWithoutTheHandoffOrItsPerson(): void {
		$this->runner->expects(self::never())->method('run');
		$this->handoffs->method('find')->willReturnCallback(fn (string $id): ?array => ['r-1' => ['requestedBy' => 'ghost']][$id] ?? null);
		$this->users->method('get')->willReturn(null);

		$this->asUser()->run(handoffId: 'r-1');
		$this->asUser()->run(handoffId: 'gone');

		self::assertSame(expected: [], actual: $this->set);
	}//end testNothingRunsWithoutTheHandoffOrItsPerson()

	/**
	 * The job hands its handoff id over; an argument without one runs nothing.
	 *
	 * @return void
	 */
	public function testTheJobRunsTheHandoffItNames(): void {
		$research = $this->createMock(originalClassName: ResearchAsUser::class);
		$research->expects(self::once())->method('run')->with('r-1');
		$job = new ResearchJob(time: $this->createMock(originalClassName: ITimeFactory::class), research: $research);
		$run = new ReflectionMethod($job, 'run');

		$run->invoke($job, ['handoffId' => 'r-1']);
		$run->invoke($job, ['handoffId' => ['r-2']]);
		$run->invoke($job, 'r-3');
		$run->invoke($job, null);
	}//end testTheJobRunsTheHandoffItNames()

	/**
	 * The impersonating runner over the doubles.
	 *
	 * @return ResearchAsUser
	 */
	private function asUser(): ResearchAsUser {
		return new ResearchAsUser(runner: $this->runner, handoffs: $this->handoffs, userManager: $this->users, userSession: $this->session);
	}//end asUser()
}//end class
