<?php

/**
 * SeedHydraTriageAgent::run() seeds the agent once, under a system identity,
 * and never lets a failure abort the upgrade.
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

use OCA\Hermiq\Repair\SeedHydraTriageAgent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the run: skipped without OpenRegister, skipped when the agent exists,
 * seeded with its read grants otherwise, and a failed save reported.
 *
 * @covers \OCA\Hermiq\Repair\SeedHydraTriageAgent
 */
class SeedHydraTriageAgentRunTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Whether the work ran inside runAsSystem.
	 *
	 * @var bool
	 */
	private bool $ranAsSystem = false;

	/**
	 * An object service whose agent lookup returns $existing and that runs work as system.
	 *
	 * @param array<int, mixed> $existing What the agent lookup returns.
	 *
	 * @return ObjectService&MockObject
	 */
	private function objectService(array $existing): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('runAsSystem')->willReturnCallback(
			function (callable $operation) {
				$this->ranAsSystem = true;
				return $operation();
			}
		);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($existing);

		return $objectService;
	}//end objectService()

	/**
	 * The step over a container answering ObjectService with $objectService (or throwing).
	 *
	 * @param ObjectService|null $objectService The object service, or null when OpenRegister is absent.
	 *
	 * @return SeedHydraTriageAgent
	 */
	private function step(?ObjectService $objectService): SeedHydraTriageAgent {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === ObjectService::class && $objectService !== null) {
					return $objectService;
				}

				throw new RuntimeException('Service ' . $id . ' not available');
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$this->logger = $this->createMock(LoggerInterface::class);

		return new SeedHydraTriageAgent(container: $container, appConfig: $appConfig, logger: $this->logger);
	}//end step()

	/**
	 * An agent object named like the seeded one.
	 *
	 * @param string $name The agent name.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $name): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['name' => $name]);

		return $entity;
	}//end agent()

	/**
	 * Without OpenRegister the step warns and stops.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSeedIsSkipped(): void {
		$step = $this->step(objectService: null);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('OpenRegister not available — skipping Hydra Triage agent seed.');
		$this->logger->expects($this->once())->method('warning')
			->with('[hermiq] Hydra Triage agent seed skipped: Service ' . ObjectService::class . ' not available');

		$step->run(output: $output);
	}//end testWithoutOpenRegisterTheSeedIsSkipped()

	/**
	 * An agent of that name already present is not seeded again.
	 *
	 * @return void
	 */
	public function testAnExistingAgentIsNotSeededAgain(): void {
		$objectService = $this->objectService(existing: ['junk', $this->agent('Other'), $this->agent(SeedHydraTriageAgent::AGENT_NAME)]);
		$objectService->expects($this->never())->method('saveObject');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Hydra Triage agent already present — skipped.');

		$this->step(objectService: $objectService)->run(output: $output);

		$this->assertTrue($this->ranAsSystem);
	}//end testAnExistingAgentIsNotSeededAgain()

	/**
	 * A missing agent is seeded with its read grants, and the missing command grant is explained.
	 *
	 * @return void
	 */
	public function testAMissingAgentIsSeededWithItsReadGrants(): void {
		$objectService = $this->objectService(existing: [$this->agent('Other')]);
		$step = $this->step(objectService: $objectService);
		$grants = $step->grants();

		$objectService->expects($this->once())->method('saveObject')
			->with($step->agentObject(grants: $grants), [], 'hermiq', 'agent')
			->willReturn(new ObjectEntity());

		$infos = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$infos): void {
				$infos[] = $message;
			}
		);
		$output->expects($this->never())->method('warning');

		$step->run(output: $output);

		$this->assertTrue($this->ranAsSystem);
		$this->assertSame('Hydra Triage agent seeded with ' . count($grants) . ' grant(s).', $infos[0]);
		$this->assertStringStartsWith('No command grant: set the `hydra.commandFlowId` app config', $infos[1]);
		$this->assertCount(2, $infos);
	}//end testAMissingAgentIsSeededWithItsReadGrants()

	/**
	 * A save that fails is reported and logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsReportedNotThrown(): void {
		$objectService = $this->objectService(existing: []);
		$objectService->method('saveObject')->willThrowException(new RuntimeException('schema missing'));
		$step = $this->step(objectService: $objectService);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Could not seed the Hydra Triage agent: schema missing');
		$this->logger->expects($this->once())->method('error')->with('[hermiq] Hydra Triage agent seed failed: schema missing');

		$step->run(output: $output);
	}//end testAFailedSaveIsReportedNotThrown()
}//end class
