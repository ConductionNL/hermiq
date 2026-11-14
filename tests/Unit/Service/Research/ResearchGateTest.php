<?php

/**
 * Unit tests for ResearchGate (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-research-run-stops-at-its-caps-the-budget-the-kill-switch-or-the-user-req-research-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use OCA\Hermiq\Service\BudgetService;
use OCA\Hermiq\Service\Research\ResearchGate;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchGate
 * @uses   \OCA\Hermiq\Service\TenantKillSwitch
 */
final class ResearchGateTest extends TestCase {

	/**
	 * @var BudgetService&MockObject
	 */
	private BudgetService&MockObject $budgets;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $controls;

	protected function setUp(): void {
		$this->budgets = $this->createMock(BudgetService::class);
		$this->controls = $this->createMock(ObjectService::class);
		$this->controls->method('setRegister')->willReturnSelf();
		$this->controls->method('setSchema')->willReturnSelf();
	}

	public function testARunGoesOnWhenNothingBlocksIt(): void {
		$this->engaged([]);
		$this->budgets->expects(self::once())->method('isBlocked')->with('org-1', 'agent-1')->willReturn(false);

		self::assertNull($this->gate()->endReason('org-1', 'agent-1', false));
	}

	public function testAStopEndsTheRunWithoutAskingTheBudgetOrTheKillSwitch(): void {
		$this->controls->expects(self::never())->method('findAll');
		$this->budgets->expects(self::never())->method('isBlocked');

		self::assertSame('Research stopped.', $this->gate()->endReason('org-1', 'agent-1', true));
	}

	public function testAnEngagedKillSwitchEndsTheRunBeforeTheBudgetIsRead(): void {
		$this->engaged(['org-1']);
		$this->budgets->expects(self::never())->method('isBlocked');

		self::assertSame(ResearchGate::KILL_SWITCH, $this->gate()->endReason('org-1', null, false));
	}

	public function testAnotherOrganisationsKillSwitchDoesNotEndThisRun(): void {
		$this->engaged(['org-2']);
		$this->budgets->method('isBlocked')->willReturn(false);

		self::assertNull($this->gate()->endReason('org-1', null, false));
	}

	public function testABudgetAtItsHardCapEndsTheRun(): void {
		$this->engaged([]);
		$this->budgets->expects(self::once())->method('isBlocked')->with('org-1', null)->willReturn(true);

		self::assertSame(ResearchGate::BUDGET, $this->gate()->endReason('org-1', null, false));
	}

	/**
	 * The gate over the stubbed budget and a kill switch reading the stubbed controls.
	 *
	 * @return ResearchGate
	 */
	private function gate(): ResearchGate {
		return new ResearchGate($this->budgets, new TenantKillSwitch($this->controls, new NullLogger()));
	}

	/**
	 * Engaged tenant controls for these organisations.
	 *
	 * @param array<int, string> $organisations The engaged organisations.
	 *
	 * @return void
	 */
	private function engaged(array $organisations): void {
		$objects = array_map(
			function (string $organisation): ObjectEntity {
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getObject')->willReturn(['engaged' => true]);
				$entity->method('getOrganisation')->willReturn($organisation);
				return $entity;
			},
			$organisations
		);
		$this->controls->method('findAll')->willReturn($objects);
	}
}
