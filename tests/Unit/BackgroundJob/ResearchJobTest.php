<?php

/**
 * Unit tests for ResearchJob (tools-deep-research-report).
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
use OCA\Hermiq\Service\Research\ResearchRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\BackgroundJob\ResearchJob
 */
final class ResearchJobTest extends TestCase {

	/**
	 * @var ResearchRunner&MockObject
	 */
	private ResearchRunner&MockObject $runner;

	/**
	 * @var HandoffService&MockObject
	 */
	private HandoffService&MockObject $handoffs;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	protected function setUp(): void {
		$this->runner = $this->createMock(ResearchRunner::class);
		$this->handoffs = $this->createMock(HandoffService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->session = $this->createMock(IUserSession::class);
	}

	public function testTheRunRunsAsThePersonWhoStartedItAndTheSessionIsRestored(): void {
		$anne = $this->createMock(IUser::class);
		$prior = $this->createMock(IUser::class);
		$this->handoffs->method('find')->with('r-1')->willReturn(['requestedBy' => 'anne']);
		$this->users->method('get')->with('anne')->willReturn($anne);
		$this->session->method('getUser')->willReturn($prior);
		$set = [];
		$this->session->method('setUser')->willReturnCallback(function (?IUser $user) use (&$set): void {
			$set[] = $user;
		});
		$this->runner->expects(self::once())->method('run')->with('r-1')->willReturnCallback(function () use (&$set, $anne): string {
			self::assertSame([$anne], $set);
			return 'done';
		});

		$this->job()->runAs('r-1');

		self::assertSame([$anne, $prior], $set);
	}

	public function testTheSessionIsRestoredWhenTheRunThrows(): void {
		$anne = $this->createMock(IUser::class);
		$this->handoffs->method('find')->willReturn(['requestedBy' => 'anne']);
		$this->users->method('get')->willReturn($anne);
		$this->session->method('getUser')->willReturn(null);
		$set = [];
		$this->session->method('setUser')->willReturnCallback(function (?IUser $user) use (&$set): void {
			$set[] = $user;
		});
		$this->runner->method('run')->willThrowException(new RuntimeException('boom'));

		try {
			$this->job()->runAs('r-1');
			self::fail('The run\'s exception should surface.');
		} catch (RuntimeException $e) {
			self::assertSame([$anne, null], $set);
		}
	}

	public function testNothingRunsWithoutTheHandoffOrItsPerson(): void {
		$this->runner->expects(self::never())->method('run');
		$this->session->expects(self::never())->method('setUser');
		$this->handoffs->method('find')->willReturnCallback(fn (string $id): ?array => ($id === 'r-1') ? ['requestedBy' => 'ghost'] : null);
		$this->users->method('get')->willReturn(null);

		$this->job()->runAs('r-1');
		$this->job()->runAs('gone');
		$this->job()->runAs('');
	}

	/**
	 * The job over the doubles.
	 *
	 * @return ResearchJob
	 */
	private function job(): ResearchJob {
		return new ResearchJob($this->createMock(ITimeFactory::class), $this->runner, $this->handoffs, $this->users, $this->session);
	}
}
