<?php

/**
 * Seed repair steps skip, with a warning, when OpenRegister is not available.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\SeedAgentBuilder;
use OCA\Hermiq\Repair\SeedComplianceControls;
use OCA\Hermiq\Repair\SeedIncidents;
use OCA\Hermiq\Repair\SeedRecordSummary;
use OCA\Hermiq\Repair\SeedSkillCreator;
use OCA\Hermiq\Service\SeedFreshnessService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An upgrade on an instance without OpenRegister must not fail: each seed
 * step names what it skipped, to the admin and to the log, and stops.
 *
 * @covers \OCA\Hermiq\Repair\SeedAgentBuilder
 * @covers \OCA\Hermiq\Repair\SeedComplianceControls
 * @covers \OCA\Hermiq\Repair\SeedIncidents
 * @covers \OCA\Hermiq\Repair\SeedRecordSummary
 * @covers \OCA\Hermiq\Repair\SeedSkillCreator
 */
class SeedStepsWithoutOpenRegisterTest extends TestCase {

	/**
	 * The steps, with the warning shown to the admin and the one logged.
	 *
	 * @return array<string, array{0: class-string<IRepairStep>, 1: bool, 2: string, 3: string}>
	 */
	public static function steps(): array {
		return [
			'incidents' => [SeedIncidents::class, false, 'OpenRegister not available — skipping incident seed.', '[hermiq] Incident seed skipped: '],
			'compliance controls' => [SeedComplianceControls::class, false, 'OpenRegister not available — skipping compliance-control-packs seed.', '[hermiq] Compliance control seed skipped: '],
			'agent builder' => [SeedAgentBuilder::class, true, 'OpenRegister not available, skipping the Agent builder seed.', '[hermiq] Agent builder seed skipped: '],
			'record summary' => [SeedRecordSummary::class, false, 'OpenRegister not available, skipping the record-summary seed.', '[hermiq] record-summary seed skipped: '],
			'skill creator' => [SeedSkillCreator::class, true, 'OpenRegister not available — skipping skill-creator seed.', '[hermiq] skill-creator seed skipped: '],
		];
	}//end steps()

	/**
	 * The step warns once to the admin and once to the log, and asks the container for nothing else.
	 *
	 * @param class-string<IRepairStep> $class      The repair step.
	 * @param bool                      $freshness  Whether the step takes a SeedFreshnessService.
	 * @param string                    $shown      The admin-facing warning.
	 * @param string                    $logPrefix  The logged warning, before the cause.
	 *
	 * @return void
	 */
	#[DataProvider('steps')]
	public function testTheStepSkipsWithAWarning(string $class, bool $freshness, string $shown, string $logPrefix): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())->method('get')->willThrowException(new RuntimeException('no OpenRegister'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($logPrefix . 'no OpenRegister');
		$logger->expects($this->never())->method('error');

		$step = $freshness === true
			? new $class($container, $logger, $this->createMock(SeedFreshnessService::class))
			: new $class($container, $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($shown);
		$output->expects($this->never())->method('info');

		$step->run($output);
	}//end testTheStepSkipsWithAWarning()
}//end class
