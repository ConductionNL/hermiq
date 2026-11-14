<?php

/**
 * A run by hand without a schedule (scheduling-overview-and-start-choice, REQ-SCHED-002).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/scheduling-overview-and-start-choice/specs/agent-schedule/spec.md#requirement-an-agents-page-offers-one-choice-of-how-it-starts-req-sched-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Schedule;

use OCA\Hermiq\Kernel\Contracts\ScheduleServiceInterface;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\Schedule\ManualRunService;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ManualRunService::class)]
final class ManualRunServiceTest extends TestCase {

	private ScheduleServiceInterface&MockObject $schedules;

	private AuditTrailMapper&MockObject $auditTrail;

	/** @var array<int, ObjectAuditEvent> */
	private array $events = [];

	/**
	 * The service with the schedule service and the audit trail doubled.
	 *
	 * @return ManualRunService
	 */
	private function service(): ManualRunService {
		$this->schedules  = $this->createMock(ScheduleServiceInterface::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
		$this->auditTrail->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event) {
				$this->events[] = $event;
				return new AuditTrail();
			}
		);

		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnCallback(static fn (string $text): string => str_replace('secret-123', '[REDACTED]', $text));

		return new ManualRunService(
			schedules: $this->schedules,
			auditTrail: $this->auditTrail,
			redaction: $redaction,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * An agent of an organisation.
	 *
	 * @return ObjectEntity
	 */
	private function agent(): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOrganisation('org-1');
		return $agent;
	}//end agent()

	public function testARunByHandRunsAsTheCallerAndIsRecordedAsManual(): void {
		$service = $this->service();
		$this->schedules->method('gateFor')->with('org-1', 'agent-1')->willReturn(null);
		$this->schedules->expects($this->once())->method('runAgentAsOwner')
			->with('clerk', 'agent-1', 'Summarise the open objections', 'org-1')
			->willReturn('Three objections are open.');
		$this->schedules->method('getLastRunUsage')->willReturn(['promptTokens' => 10, 'completionTokens' => 5]);

		$result = $service->run(agent: $this->agent(), uid: 'clerk', prompt: 'Summarise the open objections');

		self::assertSame(['status' => 'ok', 'trigger' => 'manual', 'output' => 'Three objections are open.', 'error' => null], $result);
		self::assertCount(1, $this->events);
		self::assertSame('manual-run', $this->events[0]->action);
		self::assertSame('agent-1', (string)$this->events[0]->object->getUuid());
		$context = $this->events[0]->context;
		self::assertSame(
			['ok', 'manual', 'agent-1', 'clerk', 'Three objections are open.', 'Summarise the open objections', false, ['promptTokens' => 10, 'completionTokens' => 5]],
			[$context['status'], $context['trigger'], $context['agentId'], $context['runAsUser'], $context['summary'], $context['prompt'], $context['dryRun'], $context['usage']]
		);
	}//end testARunByHandRunsAsTheCallerAndIsRecordedAsManual()

	public function testAGateHoldsTheRunAndTheHoldIsRecorded(): void {
		$service = $this->service();
		$this->schedules->method('gateFor')->willReturn('skipped_killswitch');
		$this->schedules->expects($this->never())->method('runAgentAsOwner');

		$result = $service->run(agent: $this->agent(), uid: 'clerk', prompt: 'Go');

		self::assertSame('skipped_killswitch', $result['status']);
		self::assertSame('skipped_killswitch', $this->events[0]->context['status']);
		self::assertSame([], $this->events[0]->context['usage']);
	}//end testAGateHoldsTheRunAndTheHoldIsRecorded()

	public function testAFailedRunIsAnErrorAndItsMessageIsRedactedOnTheRecord(): void {
		$service = $this->service();
		$this->schedules->method('gateFor')->willReturn(null);
		$this->schedules->method('runAgentAsOwner')->willThrowException(new RuntimeException('provider refused key secret-123'));
		$this->schedules->method('getLastRunUsage')->willReturn([]);

		$result = $service->run(agent: $this->agent(), uid: 'clerk', prompt: 'Use secret-123');

		self::assertSame(['error', 'provider refused key secret-123'], [$result['status'], $result['error']]);
		self::assertSame('provider refused key [REDACTED]', $this->events[0]->context['summary']);
		self::assertSame('Use [REDACTED]', $this->events[0]->context['prompt']);
	}//end testAFailedRunIsAnErrorAndItsMessageIsRedactedOnTheRecord()

	public function testALongAnswerIsCutOnTheRecordButReturnedWhole(): void {
		$service = $this->service();
		$long    = str_repeat('a', 900);
		$this->schedules->method('gateFor')->willReturn(null);
		$this->schedules->method('runAgentAsOwner')->willReturn($long);
		$this->schedules->method('getLastRunUsage')->willReturn([]);

		$result = $service->run(agent: $this->agent(), uid: 'clerk', prompt: 'Go');

		self::assertSame($long, $result['output']);
		self::assertSame(500, mb_strlen($this->events[0]->context['summary']));
	}//end testALongAnswerIsCutOnTheRecordButReturnedWhole()

	public function testAFailedAuditWriteDoesNotFailTheRun(): void {
		$this->schedules  = $this->createMock(ScheduleServiceInterface::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
		$this->auditTrail->method('recordObjectEvent')->willThrowException(new RuntimeException('database gone'));
		$this->schedules->method('gateFor')->willReturn(null);
		$this->schedules->method('runAgentAsOwner')->willReturn('done');
		$this->schedules->method('getLastRunUsage')->willReturn([]);
		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnArgument(0);
		$service = new ManualRunService(schedules: $this->schedules, auditTrail: $this->auditTrail, redaction: $redaction, logger: new NullLogger());

		$result = $service->run(agent: $this->agent(), uid: 'clerk', prompt: 'Go');

		self::assertSame('ok', $result['status']);
	}//end testAFailedAuditWriteDoesNotFailTheRun()
}//end class
