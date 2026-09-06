<?php

/**
 * Tests the in-flight schedule delegation repair step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\MirrorSchedulesToEngineFlows;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The step is one sync pass, and it never aborts an upgrade.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */
class MirrorSchedulesToEngineFlowsTest extends TestCase {

	/**
	 * The step delegates to syncAll() and reports its counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testRunsOneSyncPassAndReportsCounts(): void {
		$bridge = $this->createMock(ScheduleFlowBridge::class);
		$bridge->expects($this->once())->method('syncAll')->willReturn(
			['mirrored' => 2, 'refreshed' => 1, 'retired' => 0, 'skipped' => 3, 'failed' => 0]
		);

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step = new MirrorSchedulesToEngineFlows(
			flowBridge: $bridge,
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run(output: $output);

		$this->assertCount(1, $messages);
		$this->assertStringContainsString('2 mirrored', $messages[0]);
		$this->assertStringContainsString('3 stayed local', $messages[0]);

	}//end testRunsOneSyncPassAndReportsCounts()

	/**
	 * A sync failure is reported, never thrown: a repair step that aborts the
	 * upgrade over one unmappable schedule would cost the whole instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
	 */
	public function testSyncFailureNeverAbortsTheUpgrade(): void {
		$bridge = $this->createMock(ScheduleFlowBridge::class);
		$bridge->method('syncAll')->willThrowException(new RuntimeException('no register yet'));

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step = new MirrorSchedulesToEngineFlows(
			flowBridge: $bridge,
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run(output: $output);

		$this->assertCount(1, $messages);
		$this->assertStringContainsString('skipped', $messages[0]);

	}//end testSyncFailureNeverAbortsTheUpgrade()
}//end class
