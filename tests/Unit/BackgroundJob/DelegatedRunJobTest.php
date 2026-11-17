<?php

/**
 * Hermiq DelegatedRunJobTest
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
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

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\DelegatedRunJob;
use OCA\Hermiq\Kernel\Contracts\DelegationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The job claims and runs the part it names, as a worker.
 */
#[CoversClass(DelegatedRunJob::class)]
final class DelegatedRunJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param DelegationServiceInterface $delegation The delegation service.
	 * @param mixed                      $argument   The queued argument.
	 *
	 * @return void
	 */
	private function runJob(DelegationServiceInterface $delegation, mixed $argument): void {
		$job = new DelegatedRunJob(time: $this->createMock(ITimeFactory::class), delegation: $delegation);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * The named part is claimed and run as a worker.
	 *
	 * @return void
	 */
	public function testTheJobRunsTheNamedPartAsAWorker(): void {
		$delegation = $this->createMock(DelegationServiceInterface::class);
		$delegation->expects($this->once())->method('runStoredPart')->with('part-7', 'worker')->willReturn(true);

		$this->runJob(delegation: $delegation, argument: ['delegatedRunId' => 'part-7']);
	}//end testTheJobRunsTheNamedPartAsAWorker()

	/**
	 * An argument without a part id runs nothing.
	 *
	 * @return void
	 */
	public function testAnArgumentWithoutAPartRunsNothing(): void {
		$delegation = $this->createMock(DelegationServiceInterface::class);
		$delegation->expects($this->never())->method('runStoredPart');

		$this->runJob(delegation: $delegation, argument: null);
		$this->runJob(delegation: $delegation, argument: ['delegatedRunId' => '']);
		$this->runJob(delegation: $delegation, argument: ['delegatedRunId' => 42]);
	}//end testAnArgumentWithoutAPartRunsNothing()
}//end class
