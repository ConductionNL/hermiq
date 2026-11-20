<?php

/**
 * Unit tests for AnswerCachePurgeJob.
 *
 * @category Test
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
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\BackgroundJob;

use OCA\Hermiq\BackgroundJob\AnswerCachePurgeJob;
use OCA\Hermiq\Service\AnswerCache\AnswerCacheService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\BackgroundJob\AnswerCachePurgeJob
 */
class AnswerCachePurgeJobTest extends TestCase {

	private function runJob(AnswerCacheService $cache, LoggerInterface $logger): void {
		$job = new AnswerCachePurgeJob($this->createMock(ITimeFactory::class), $cache, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testTheDailyRunPurgesAndLogsTheCount(): void {
		$cache = $this->createMock(AnswerCacheService::class);
		$cache->expects($this->once())->method('purgeExpired')->willReturn(3);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('Hermiq answer cache removed 3 expired answers', ['app' => 'hermiq']);
		$logger->expects($this->never())->method('error');

		$this->runJob($cache, $logger);
	}

	public function testAFailureIsLoggedNotThrown(): void {
		$cache = $this->createMock(AnswerCacheService::class);
		$cache->method('purgeExpired')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq answer cache purge failed: down', $this->anything());

		$this->runJob($cache, $logger);
	}
}
