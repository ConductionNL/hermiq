<?php

/**
 * Golden tests for the schedule flow-mirror rollback command.
 *
 * Pins the exact display, the order of the marker and flow writes per schedule, the
 * warning logged for a failure and the exit code, for a real run with a success and
 * a failure among skipped rows, and for a dry run over the same rows.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Command
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

namespace OCA\Hermiq\Tests\Unit\Command;

use OCA\Hermiq\Command\RollbackScheduleFlowMirror;
use OCA\Hermiq\Service\Schedule\ScheduleFlowBridge;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden tests for the rollback command.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-in-flight-schedules-migrate-and-roll-back
 */
class RollbackScheduleFlowMirrorGoldenTest extends TestCase {

	/**
	 * Every write and log line, in order.
	 *
	 * @var array<int, string>
	 */
	private array $log = [];

	/**
	 * Run the command over a fixed set of rows.
	 *
	 * @param array $input The command input.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function runCommand(array $input): array {
		$this->log = [];
		$rows = [];
		foreach (['s-1' => ['engineFlowId' => ' f-1 '], 's-2' => ['engineFlowId' => '  '], 's-3' => ['engineFlowId' => 'f-3'], 's-4' => ['engineFlowId' => 'f-4']] as $uuid => $data) {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$entity->setObject($data);
			$rows[] = $entity;
		}

		array_splice($rows, 1, 0, ['not an entity']);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn($rows);

		$schedules = $this->createMock(ScheduleService::class);
		$schedules->method('clearEngineDelegation')->willReturnCallback(
			function (ObjectEntity $schedule): void {
				$this->log[] = 'clear ' . $schedule->getUuid();
				if ($schedule->getUuid() === 's-3') {
					throw new RuntimeException('conflict');
				}
			}
		);

		$bridge = $this->createMock(ScheduleFlowBridge::class);
		$bridge->method('deleteFlow')->willReturnCallback(
			function (string $flowId): void {
				$this->log[] = 'delete ' . $flowId;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->log[] = 'log.warning: ' . $message;
			}
		);

		$tester = new CommandTester(new RollbackScheduleFlowMirror($objects, $schedules, $bridge, $logger));
		$code = $tester->execute($input);
		return [$code, $tester->getDisplay()];
	}//end runCommand()

	/**
	 * A real run: a success, a failure and a success among skipped rows.
	 *
	 * @return void
	 */
	public function testARealRunReportsEachScheduleInOrder(): void {
		[$code, $display] = $this->runCommand([]);

		$this->assertSame(1, $code);
		$this->assertSame(
			"Rolled back schedule s-1 (flow f-1).\n"
			. "Could not roll back schedule s-3: conflict\n"
			. "Rolled back schedule s-4 (flow f-4).\n"
			. "Rollback: 2 schedules rolled back, 1 failed.\n",
			$display
		);
		$this->assertSame(
			['clear s-1', 'delete f-1', 'clear s-3', 'log.warning: RollbackScheduleFlowMirror could not roll back schedule s-3: conflict', 'clear s-4', 'delete f-4'],
			$this->log
		);
	}//end testARealRunReportsEachScheduleInOrder()

	/**
	 * A dry run over the same rows writes nothing and exits zero.
	 *
	 * @return void
	 */
	public function testADryRunReportsWithoutWriting(): void {
		[$code, $display] = $this->runCommand(['--dry-run' => true]);

		$this->assertSame(0, $code);
		$this->assertSame(
			"Would roll back schedule s-1 (flow f-1).\n"
			. "Would roll back schedule s-3 (flow f-3).\n"
			. "Would roll back schedule s-4 (flow f-4).\n"
			. "Rollback: 3 schedules rolled back, 0 failed.\n",
			$display
		);
		$this->assertSame([], $this->log);
	}//end testADryRunReportsWithoutWriting()
}//end class
