<?php

/**
 * Edges of the throttled connection report: refusals before the memory, and a memory that fails.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Connection;

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

// The integriq event contract is not installed here; load its stub explicitly.
require_once __DIR__ . '/../../../Stubs/Integriq/Event/ConnectionStatusReportedEvent.php';

/**
 * @covers \OCA\Hermiq\Service\Connection\ConnectionReporter
 * @uses \OCA\Hermiq\Service\Connection\ConnectionStatus
 */
class ConnectionReporterThrottleEdgeTest extends TestCase {

	/**
	 * The dispatcher double.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->appConfig  = $this->createMock(IAppConfig::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reporter at a fixed moment.
	 *
	 * @param int $now The time the clock answers.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(int $now = 2_000_000): ConnectionReporter {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		return new ConnectionReporter(
			eventDispatcher: $this->dispatcher,
			appConfig: $this->appConfig,
			timeFactory: $time,
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * An invalid throttled report is refused before the memory is read or anything is sent.
	 *
	 * @return void
	 */
	public function testAnInvalidThrottledReportNeverReachesTheMemory(): void {
		$this->appConfig->expects($this->never())->method('getValueString');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->exactly(2))->method('warning');

		$reporter = $this->reporter();
		$this->assertFalse($reporter->reportThrottled(key: 'mail', status: 'configured'));
		$this->assertFalse($reporter->reportThrottled(key: 'llm', status: 'broken'));
	}//end testAnInvalidThrottledReportNeverReachesTheMemory()

	/**
	 * A memory that cannot be read answers false, is logged, and sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableMemoryIsLoggedAndSendsNothing(): void {
		$this->appConfig->method('getValueString')->willThrowException(new RuntimeException('appconfig locked'));
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[ConnectionReporter] Could not keep the connection report memory',
				['key' => 'speech', 'exception' => 'appconfig locked']
			);

		$this->assertFalse($this->reporter()->reportThrottled(key: 'speech', status: 'error'));
	}//end testAnUnreadableMemoryIsLoggedAndSendsNothing()

	/**
	 * A memory that cannot be written after a send answers false, though the report went out.
	 *
	 * @return void
	 */
	public function testAnUnwritableMemoryAnswersFalseAfterTheSend(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('hermiq', 'connection_report_web-search', 'unavailable|2000000', true)
			->willThrowException(new RuntimeException('read-only'));
		$this->dispatcher->expects($this->once())->method('dispatchTyped');
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ConnectionReporter] Could not keep the connection report memory', ['key' => 'web-search', 'exception' => 'read-only']);

		$this->assertFalse($this->reporter()->reportThrottled(key: 'web-search', status: 'unavailable'));
	}//end testAnUnwritableMemoryAnswersFalseAfterTheSend()

	/**
	 * The memory is read lazily under the app and the key's own name.
	 *
	 * @return void
	 */
	public function testTheMemoryIsReadPerKeyAndLazily(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('hermiq', 'connection_report_llm-runner', '', true)
			->willReturn('error|' . (2_000_000 - 10));
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->assertFalse($this->reporter()->reportThrottled(key: 'llm-runner', status: 'error'));
	}//end testTheMemoryIsReadPerKeyAndLazily()

	/**
	 * A memory without a separator, or whose time is not all digits, is read as no memory.
	 *
	 * @return void
	 */
	public function testAMalformedMemoryIsReadAsNone(): void {
		$memories = ['error', 'error|1999990x', 'error| 1999990', 'error|1999990.5', 'error|'];
		$this->appConfig->method('getValueString')->willReturnOnConsecutiveCalls(...$memories);
		$sent = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use (&$sent): void {
				$sent[] = $event->status;
			}
		);

		$reporter = $this->reporter();
		foreach ($memories as $memory) {
			$this->assertTrue($reporter->reportThrottled(key: 'speech', status: 'error'), $memory);
		}

		$this->assertSame(['error', 'error', 'error', 'error', 'error'], $sent);
	}//end testAMalformedMemoryIsReadAsNone()
}//end class
