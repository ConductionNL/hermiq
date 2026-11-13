<?php

/**
 * Characterisation of WorkspaceAuditor's failure paths and argument
 * recording: an audit trail that cannot be written is logged and never
 * thrown, an unreadable agent still gets its record, and recorded arguments
 * are bounded, redacted and reduced to text.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Workspace
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

namespace OCA\Hermiq\Tests\Unit\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\Workspace\WorkspaceAuditor;
use OCA\Hermiq\Service\Workspace\WorkspaceWrite;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure handling and argument recording of the workspace auditor.
 *
 * @covers \OCA\Hermiq\Service\Workspace\WorkspaceAuditor
 * @uses   \OCA\Hermiq\Service\Workspace\WorkspaceWrite
 * @uses   \OCA\Hermiq\Service\RedactionService
 */
final class WorkspaceAuditorTest extends TestCase {

	private const RUN = ['runId' => 'run-1', 'agentId' => 'agent-1', 'userId' => 'alice'];

	/**
	 * Recorded audit events.
	 *
	 * @var list<ObjectAuditEvent>
	 */
	private array $events = [];

	/**
	 * An auditor over the given object lookup, trail and logger.
	 *
	 * @param ObjectService        $objects The object service.
	 * @param AuditTrailMapper|null $trail   The trail, or a recording one.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return WorkspaceAuditor
	 */
	private function auditor(ObjectService $objects, ?AuditTrailMapper $trail = null, ?LoggerInterface $logger = null): WorkspaceAuditor {
		if ($trail === null) {
			$trail = $this->createMock(AuditTrailMapper::class);
			$trail->method('recordObjectEvent')->willReturnCallback(function (ObjectAuditEvent $event): AuditTrail {
				$this->events[] = $event;
				return new AuditTrail();
			});
		}

		return new WorkspaceAuditor(
			objects: $objects,
			auditTrail: $trail,
			redaction: new RedactionService($this->createMock(IConfig::class)),
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end auditor()

	/**
	 * An object service whose agent lookup fails.
	 *
	 * @return ObjectService
	 */
	private function unreadableAgents(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willThrowException(new RuntimeException('register offline'));
		return $objects;
	}//end unreadableAgents()

	/**
	 * A write of a tool with these arguments.
	 *
	 * @param string               $toolId    The tool.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return WorkspaceWrite
	 */
	private static function write(string $toolId, array $arguments): WorkspaceWrite {
		$write = new WorkspaceWrite(toolId: $toolId, run: self::RUN);
		$write->arguments = $arguments;
		return $write;
	}//end write()

	/**
	 * An unreadable agent still gets a record, hung on a subject carrying its id.
	 *
	 * @return void
	 */
	public function testAnUnreadableAgentStillGetsItsRecord(): void {
		$this->auditor(objects: $this->unreadableAgents())
			->record(write: self::write(toolId: WorkspaceToolDescriptors::DELETE_FILE, arguments: ['path' => 'a.txt']), outcome: 'ok');

		self::assertCount(1, $this->events);
		self::assertSame('agent-1', $this->events[0]->object->getUuid());
		self::assertSame(WorkspaceAuditor::ACTION, $this->events[0]->action);
		self::assertSame(
			[
				'runId' => 'run-1',
				'agentId' => 'agent-1',
				'tool' => WorkspaceToolDescriptors::DELETE_FILE,
				'classification' => 'destructive',
				'owner' => 'alice',
				'credentialId' => '',
				'approvalId' => '',
				'decidedBy' => '',
				'arguments' => ['path' => 'a.txt'],
				'outcome' => 'ok',
			],
			$this->events[0]->context
		);
	}//end testAnUnreadableAgentStillGetsItsRecord()

	/**
	 * A trail that cannot be written is logged with the run id and never thrown.
	 *
	 * @return void
	 */
	public function testAFailingTrailIsLoggedNotThrown(): void {
		$trail = $this->createMock(AuditTrailMapper::class);
		$trail->method('recordObjectEvent')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Hermiq could not write the workspace audit record for run run-1: disk full', $this->arrayHasKey('exception'));

		$this->auditor(objects: $this->unreadableAgents(), trail: $trail, logger: $logger)
			->record(write: self::write(toolId: WorkspaceToolDescriptors::WRITE_FILE, arguments: []), outcome: 'approval_required');
	}//end testAFailingTrailIsLoggedNotThrown()

	/**
	 * Only the tool's recorded arguments are kept; each is cut to 500
	 * characters and non-text values become empty strings.
	 *
	 * @return void
	 */
	public function testRecordedArgumentsAreBoundedAndTextOnly(): void {
		$this->auditor(objects: $this->unreadableAgents())->record(
			write: self::write(
				toolId: WorkspaceToolDescriptors::COMMIT,
				arguments: [
					'message' => str_repeat('m', 600),
					'paths' => ['a.txt', ['nested'], 7, null],
					'content' => 'never recorded',
				]
			),
			outcome: 'ok'
		);

		$arguments = $this->events[0]->context['arguments'];
		self::assertSame(['message', 'paths'], array_keys($arguments));
		self::assertSame(str_repeat('m', 500), $arguments['message']);
		self::assertSame(['a.txt', '', '7', ''], $arguments['paths']);
	}//end testRecordedArgumentsAreBoundedAndTextOnly()
}//end class
