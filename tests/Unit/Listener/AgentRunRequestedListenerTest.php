<?php

/**
 * Unit tests for AgentRunRequestedListener (flow-agent-listener).
 *
 * Exercises the listener's own logic (mode validation + enqueue) without a live
 * Nextcloud/OpenRegister — the governed dispatch itself is FlowAgentRunService's
 * responsibility and is tested separately.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-agent-listener/tasks.md#1-listener-and-queued-job
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\BackgroundJob\AgentRunRequestedJob;
use OCA\Hermiq\Listener\AgentRunRequestedListener;
use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AgentRunRequestedListener.
 *
 * @spec openspec/changes/flow-agent-listener/tasks.md#1-listener-and-queued-job
 */
class AgentRunRequestedListenerTest extends TestCase {

	/**
	 * Build an AgentRunRequestedEvent with the given overrides.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return AgentRunRequestedEvent
	 */
	private function event(array $overrides = []): AgentRunRequestedEvent {
		$defaults = [
			'subjectUuid' => 'obj-1',
			'subjectRegister' => '1',
			'subjectSchema' => '10',
			'agent' => 'agent-uuid-1',
			'skill' => null,
			'prompt' => 'Classify this',
			'resultField' => 'categorySlug',
			'requiresApproval' => false,
			'mode' => 'async',
			'flowName' => 'classify-tender',
		];
		$data = array_merge($defaults, $overrides);

		return new AgentRunRequestedEvent(
			subjectUuid: $data['subjectUuid'],
			subjectRegister: $data['subjectRegister'],
			subjectSchema: $data['subjectSchema'],
			agent: $data['agent'],
			skill: $data['skill'],
			prompt: $data['prompt'],
			resultField: $data['resultField'],
			requiresApproval: $data['requiresApproval'],
			mode: $data['mode'],
			flowName: $data['flowName']
		);

	}//end event()

	/**
	 * An unrelated event is ignored entirely.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedEvent(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$listener = new AgentRunRequestedListener($jobList, $this->createMock(LoggerInterface::class));
		$listener->handle($this->createMock(Event::class));

		$this->addToAssertionCount(1);

	}//end testIgnoresUnrelatedEvent()

	/**
	 * mode=async enqueues AgentRunRequestedJob with the event's flattened payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#task-1-2
	 */
	public function testAsyncModeEnqueuesJobWithPayload(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())
			->method('add')
			->with(
				AgentRunRequestedJob::class,
				$this->callback(function (array $payload): bool {
					return $payload['agent'] === 'agent-uuid-1'
						&& $payload['resultField'] === 'categorySlug'
						&& $payload['mode'] === 'async';
				})
			);

		$listener = new AgentRunRequestedListener($jobList, $this->createMock(LoggerInterface::class));
		$listener->handle($this->event());

	}//end testAsyncModeEnqueuesJobWithPayload()

	/**
	 * An unsupported mode (e.g. "sync") is logged and never enqueued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#task-1-2
	 */
	public function testUnsupportedModeIsSkippedAndLogged(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$listener = new AgentRunRequestedListener($jobList, $logger);
		$listener->handle($this->event(['mode' => 'sync']));

	}//end testUnsupportedModeIsSkippedAndLogged()

	/**
	 * A job-list failure is caught and logged — the listener never throws into
	 * OpenRegister's dispatchTyped() call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#task-1-2
	 */
	public function testJobListFailureIsCaughtAndLogged(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willThrowException(new RuntimeException('queue down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$listener = new AgentRunRequestedListener($jobList, $logger);
		$listener->handle($this->event());

		$this->addToAssertionCount(1);

	}//end testJobListFailureIsCaughtAndLogged()
}//end class
