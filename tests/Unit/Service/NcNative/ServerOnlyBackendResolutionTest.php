<?php

/**
 * Resolving the server-only CalDAV backend and Notes service: a container that
 * throws, hands back a non-object, or an implementation missing a method all
 * resolve to null (with the reason logged); a complete one is returned.
 *
 * The classes are looked up by name and exist only inside a Nextcloud server,
 * so each case declares a stand-in in a process of its own.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
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

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\NotesWriteService;
use OCA\Hermiq\Service\NcNative\TaskLists;
use OCP\Calendar\IManager as ICalendarManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\NcNative\TaskLists
 * @covers \OCA\Hermiq\Service\NcNative\NotesWriteService
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ServerOnlyBackendResolutionTest extends TestCase {

	/**
	 * Declare a stand-in for a server-only class with the given methods.
	 *
	 * @param string             $fqcn    The class name.
	 * @param array<int, string> $methods The methods it has.
	 *
	 * @return void
	 */
	private static function declare(string $fqcn, array $methods): void {
		$fqcn      = ltrim($fqcn, '\\');
		$separator = strrpos($fqcn, '\\');
		$namespace = substr($fqcn, 0, $separator);
		$short     = substr($fqcn, ($separator + 1));
		$body      = implode('', array_map(static fn (string $m): string => "public function {$m}() {}", $methods));
		eval("namespace {$namespace}; class {$short} { {$body} }");
	}//end declare()

	/**
	 * A container answering the given value, or throwing it.
	 *
	 * @param mixed $answer The value, or a Throwable to throw.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(mixed $answer): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		if ($answer instanceof \Throwable) {
			$container->method('get')->willThrowException($answer);
		} else {
			$container->method('get')->willReturn($answer);
		}

		return $container;
	}//end container()

	/**
	 * The task lists over a container and logger.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return TaskLists The task lists.
	 */
	private function taskLists(ContainerInterface $container, LoggerInterface $logger): TaskLists {
		return new TaskLists($this->createMock(ICalendarManager::class), $container, $logger);
	}//end taskLists()

	/**
	 * The notes service resolver, exposed.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 *
	 * @return object|null The resolved Notes service.
	 */
	private function notesService(ContainerInterface $container, LoggerInterface $logger): ?object {
		$writes = new class ($this->createMock(AgentArtefactMarker::class), $container, $logger) extends NotesWriteService {
			/**
			 * The protected resolver.
			 *
			 * @return object|null The service.
			 */
			public function resolve(): ?object {
				return $this->notesService();
			}
		};

		return $writes->resolve();
	}//end notesService()

	/**
	 * A complete CalDAV backend is returned as it is.
	 *
	 * @return void
	 */
	public function testACompleteCalDavBackendIsReturned(): void {
		self::declare('OCA\DAV\CalDAV\CalDavBackend', ['getCalendarsForUser', 'getCalendarObject', 'updateCalendarObject']);
		$backend = new \OCA\DAV\CalDAV\CalDavBackend();
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame($backend, $this->taskLists($this->container($backend), $logger)->davBackend());
	}//end testACompleteCalDavBackendIsReturned()

	/**
	 * A CalDAV backend that has drifted is refused, naming the missing method.
	 *
	 * @return void
	 */
	public function testACalDavBackendMissingAMethodIsRefused(): void {
		self::declare('OCA\DAV\CalDAV\CalDavBackend', ['getCalendarsForUser', 'getCalendarObject']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Hermiq: CalDAV backend is missing expected method {method}',
			['method' => 'updateCalendarObject']
		);

		$this->assertNull($this->taskLists($this->container(new \OCA\DAV\CalDAV\CalDavBackend()), $logger)->davBackend());
	}//end testACalDavBackendMissingAMethodIsRefused()

	/**
	 * A container that cannot build the backend, or hands back a non-object, gives no backend.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCalDavBackendIsNull(): void {
		self::declare('OCA\DAV\CalDAV\CalDavBackend', []);
		$failure = new RuntimeException('not built');
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('Hermiq: CalDAV backend could not be resolved', ['exception' => $failure]);

		$this->assertNull($this->taskLists($this->container($failure), $logger)->davBackend());
		$this->assertNull($this->taskLists($this->container('not an object'), $this->createMock(LoggerInterface::class))->davBackend());
	}//end testAnUnresolvableCalDavBackendIsNull()

	/**
	 * A complete Notes service is returned as it is.
	 *
	 * @return void
	 */
	public function testACompleteNotesServiceIsReturned(): void {
		self::declare('OCA\Notes\Service\NotesService', ['getAll', 'get', 'create']);
		$service = new \OCA\Notes\Service\NotesService();
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame($service, $this->notesService($this->container($service), $logger));
	}//end testACompleteNotesServiceIsReturned()

	/**
	 * A Notes service that has drifted is refused, naming the missing method.
	 *
	 * @return void
	 */
	public function testANotesServiceMissingAMethodIsRefused(): void {
		self::declare('OCA\Notes\Service\NotesService', ['getAll', 'get']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Hermiq: Notes service is missing expected method {method}',
			['method' => 'create']
		);

		$this->assertNull($this->notesService($this->container(new \OCA\Notes\Service\NotesService()), $logger));
	}//end testANotesServiceMissingAMethodIsRefused()

	/**
	 * A container that cannot build the Notes service, or hands back a non-object, gives no service.
	 *
	 * @return void
	 */
	public function testAnUnresolvableNotesServiceIsNull(): void {
		self::declare('OCA\Notes\Service\NotesService', []);
		$failure = new RuntimeException('not built');
		$logger  = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('Hermiq: Notes service could not be resolved', ['exception' => $failure]);

		$this->assertNull($this->notesService($this->container($failure), $logger));
		$this->assertNull($this->notesService($this->container(42), $this->createMock(LoggerInterface::class)));
	}//end testAnUnresolvableNotesServiceIsNull()
}//end class
