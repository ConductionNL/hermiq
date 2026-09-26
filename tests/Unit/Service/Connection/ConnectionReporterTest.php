<?php

/**
 * ConnectionReporter unit tests.
 *
 * The reporter tells integriq's connection registry what hermiq observed. Every
 * test here guards one way it could quietly stop doing that: sending the wrong
 * app or key, sending a status integriq would drop, turning a working search
 * into a 500 because a listener threw, logging a fault when integriq is simply
 * not installed, or writing integriq's row on every search.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Connection;

use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReporter.
 *
 * The integriq event class comes from tests/Stubs/Integriq/Event, which mirrors
 * design D6 of the hydra change connection-registry.
 *
 * @covers \OCA\Hermiq\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher $dispatcher;

	/**
	 * Mocked app config holding the throttle memory in $memory.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The current time the time factory answers.
	 *
	 * @var int
	 */
	private int $now = 1_000_000;

	/**
	 * The app-config values the reporter wrote, by key.
	 *
	 * @var array<string, string>
	 */
	private array $memory = [];

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->sent = [];
		$this->memory = [];

		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => ($this->memory[$app . '/' . $key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false): bool {
				$this->assertTrue(condition: $lazy, message: 'the throttle memory must be a lazy key, so it never loads on a page request');
				$this->memory[$app . '/' . $key] = $value;
				return true;
			}
		);
	}//end setUp()

	/**
	 * The reporter as production builds it.
	 *
	 * @param IEventDispatcher|null $dispatcher Another dispatcher, or null for the recording one.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(?IEventDispatcher $dispatcher = null): ConnectionReporter {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ConnectionReporter(
			eventDispatcher: ($dispatcher ?? $this->dispatcher),
			appConfig: $this->appConfig,
			timeFactory: $time,
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * The reporter as it behaves on an instance without integriq.
	 *
	 * The stub makes the event class resolvable in this process, so absence is
	 * simulated at the one seam that asks. The real lookup is tested on its own
	 * in testTheLookupAnswersNullForAnAbsentClass.
	 *
	 * @return ConnectionReporter
	 */
	private function reporterWithoutIntegriq(): ConnectionReporter {
		$time = $this->createMock(originalClassName: ITimeFactory::class);

		return new class($this->dispatcher, $this->appConfig, $time, $this->logger) extends ConnectionReporter {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end reporterWithoutIntegriq()

	/**
	 * A report reaches integriq as one event with hermiq's id and the words observed.
	 *
	 * @return void
	 */
	public function testAReportIsSentWithTheAppKeyStatusAndMessage(): void {
		$this->assertTrue(condition: $this->reporter()->report(key: 'web-search', status: 'configured', message: 'Searches go to SearXNG.'));

		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);
		$this->assertSame(expected: 'hermiq', actual: $event->app);
		$this->assertSame(expected: 'web-search', actual: $event->key);
		$this->assertSame(expected: 'configured', actual: $event->status);
		$this->assertSame(expected: 'Searches go to SearXNG.', actual: $event->message);
	}//end testAReportIsSentWithTheAppKeyStatusAndMessage()

	/**
	 * The event name is the one the contract fixes, and it resolves here.
	 *
	 * A class-name string is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stub's real name.
	 *
	 * @return void
	 */
	public function testTheEventNameIsTheContractName(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReporter::STATUS_EVENT);
	}//end testTheEventNameIsTheContractName()

	/**
	 * The keys the reporter accepts are exactly the declared keys.
	 *
	 * @return void
	 */
	public function testTheKeysAreTheDeclaredKeys(): void {
		$declaration = json_decode(
			(string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/connections.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertSame(expected: array_column($declaration['connections'], 'key'), actual: ConnectionReporter::KEYS);
		$this->assertSame(expected: $declaration['app'], actual: ConnectionReporter::APP_ID);
	}//end testTheKeysAreTheDeclaredKeys()

	/**
	 * The class lookup answers null for a class nobody ships.
	 *
	 * This is the real guard, not the test double: an instance without integriq
	 * has no class, and the lookup must say so instead of throwing.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReporter::class, 'resolveEventClass');

		$this->assertNull(actual: $method->invoke($this->reporter(), 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReporter::STATUS_EVENT,
			actual: $method->invoke($this->reporter(), ConnectionReporter::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * Without integriq nothing is sent, stored or logged, and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentStoredOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->never())->method('warning');

		$reporter = $this->reporterWithoutIntegriq();

		$this->assertFalse(condition: $reporter->report(key: 'llm', status: 'configured', message: 'ok'));
		$this->assertFalse(condition: $reporter->reportThrottled(key: 'github-templates', status: 'limited', message: 'rate limited'));
	}//end testWithoutIntegriqNothingIsSentStoredOrLogged()

	/**
	 * An unknown key is refused with a warning and never sent.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefused(): void {
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'unknown connection key'), ['key' => 'talk']);

		$this->assertFalse(condition: $this->reporter()->report(key: 'talk', status: 'configured'));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAnUnknownKeyIsRefused()

	/**
	 * A status outside the six is refused, and each of the six is sent.
	 *
	 * @return void
	 */
	public function testStatusMustBeOneOfTheSix(): void {
		$reporter = $this->reporter();

		$this->assertFalse(condition: $reporter->report(key: 'llm', status: 'degraded'));
		$this->assertSame(expected: [], actual: $this->sent);

		foreach (ConnectionReporter::STATUSES as $status) {
			$this->assertTrue(condition: $reporter->report(key: 'llm', status: $status));
		}

		$this->assertCount(expectedCount: 6, haystack: $this->sent);
		$this->assertContains(needle: 'limited', haystack: ConnectionReporter::STATUSES);
	}//end testStatusMustBeOneOfTheSix()

	/**
	 * A listener that throws never escapes into the request that reported.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(count: 2))->method('warning')
			->with($this->stringContains(string: 'Could not send'), $this->anything());

		$reporter = $this->reporter(dispatcher: $dispatcher);

		$this->assertFalse(condition: $reporter->report(key: 'llm', status: 'configured', message: 'Chat uses OpenAI.'));
		$this->assertFalse(condition: $reporter->reportThrottled(key: 'github-templates', status: 'configured'));
		$this->assertSame(expected: [], actual: $this->memory, message: 'a report that was not sent must not start the throttle');
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * The same status is sent once, then again only after an hour.
	 *
	 * @return void
	 */
	public function testTheSameStatusRepeatsOnlyAfterAnHour(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportThrottled(key: 'github-templates', status: 'limited', message: 'rate limited'));
		$this->assertSame(
			expected: 'limited|1000000',
			actual: $this->memory['hermiq/' . ConnectionReporter::MEMORY_KEY_PREFIX . 'github-templates']
		);

		$this->now += (ConnectionReporter::REPEAT_SECONDS - 1);
		$this->assertFalse(condition: $reporter->reportThrottled(key: 'github-templates', status: 'limited', message: 'rate limited'));
		$this->assertCount(expectedCount: 1, haystack: $this->sent);

		$this->now += 1;
		$this->assertTrue(condition: $reporter->reportThrottled(key: 'github-templates', status: 'limited', message: 'rate limited'));
		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testTheSameStatusRepeatsOnlyAfterAnHour()

	/**
	 * A different status waits five minutes, so two disagreeing targets cannot flap.
	 *
	 * @return void
	 */
	public function testADifferentStatusWaitsFiveMinutes(): void {
		$reporter = $this->reporter();
		$reporter->reportThrottled(key: 'webhook-delivery', status: 'configured', message: 'reached a.example');

		$this->now += (ConnectionReporter::CHANGE_SECONDS - 1);
		$this->assertFalse(condition: $reporter->reportThrottled(key: 'webhook-delivery', status: 'error', message: 'failed b.example'));

		$this->now += 1;
		$this->assertTrue(condition: $reporter->reportThrottled(key: 'webhook-delivery', status: 'error', message: 'failed b.example'));
		$this->assertSame(expected: ['configured', 'error'], actual: array_map(static fn (Event $e): string => $e->status, $this->sent));
	}//end testADifferentStatusWaitsFiveMinutes()

	/**
	 * Each connection keeps its own memory, and an immediate report is never throttled.
	 *
	 * @return void
	 */
	public function testTheThrottleIsPerConnectionAndOnlyForThrottledReports(): void {
		$reporter = $this->reporter();
		$reporter->reportThrottled(key: 'webhook-delivery', status: 'error');

		$this->assertTrue(condition: $reporter->reportThrottled(key: 'github-templates', status: 'error'));
		$this->assertTrue(condition: $reporter->report(key: 'llm', status: 'configured'));
		$this->assertTrue(condition: $reporter->report(key: 'llm', status: 'configured'));
		$this->assertCount(expectedCount: 4, haystack: $this->sent);
	}//end testTheThrottleIsPerConnectionAndOnlyForThrottledReports()

	/**
	 * A garbled memory is treated as no memory, so the report still goes.
	 *
	 * @return void
	 */
	public function testAGarbledMemoryDoesNotBlockAReport(): void {
		$this->memory['hermiq/' . ConnectionReporter::MEMORY_KEY_PREFIX . 'github-templates'] = 'limited|yesterday';

		$this->assertTrue(condition: $this->reporter()->reportThrottled(key: 'github-templates', status: 'limited'));
	}//end testAGarbledMemoryDoesNotBlockAReport()
}//end class
