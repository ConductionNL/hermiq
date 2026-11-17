<?php

/**
 * Characterisation of hermiq's queued jobs: what each hands to its service,
 * how a non-array argument is treated (passed on empty, or the job does
 * nothing), and which failures are logged instead of thrown.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\BackgroundJob
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

use OCA\Hermiq\BackgroundJob\AgentRunRequestedJob;
use OCA\Hermiq\BackgroundJob\ConversationTitleJob;
use OCA\Hermiq\BackgroundJob\SkillLearningsCaptureJob;
use OCA\Hermiq\BackgroundJob\WebhookAgentRunJob;
use OCA\Hermiq\Service\Engine\ConversationTitleWriter;
use OCA\Hermiq\Service\FlowAgentRunService;
use OCA\Hermiq\Service\SkillLearningsCaptureService;
use OCA\Hermiq\Service\WebhookAgentRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Argument hand-off of the queued jobs.
 *
 * @covers \OCA\Hermiq\BackgroundJob\AgentRunRequestedJob
 * @covers \OCA\Hermiq\BackgroundJob\WebhookAgentRunJob
 * @covers \OCA\Hermiq\BackgroundJob\ConversationTitleJob
 * @covers \OCA\Hermiq\BackgroundJob\SkillLearningsCaptureJob
 */
final class QueuedJobsTest extends TestCase {

	/**
	 * Run a queued job's protected run().
	 *
	 * @param QueuedJob $job      The job.
	 * @param mixed     $argument The argument.
	 *
	 * @return void
	 */
	private static function runJob(QueuedJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end runJob()

	/**
	 * A flow run request passes its payload on, and a non-array as empty.
	 *
	 * @return void
	 */
	public function testAFlowRunRequestPassesItsPayloadOn(): void {
		$seen = [];
		$service = $this->createMock(FlowAgentRunService::class);
		$service->method('run')->willReturnCallback(static function (array $payload, bool $bypassApprovalGate = false) use (&$seen): bool {
			$seen[] = [$payload, $bypassApprovalGate];
			return true;
		});
		$job = new AgentRunRequestedJob(time: $this->createMock(ITimeFactory::class), flowAgentRunService: $service);

		self::runJob($job, ['agentId' => 'a-1', 'input' => 'go']);
		self::runJob($job, 'garbage');

		self::assertSame([[['agentId' => 'a-1', 'input' => 'go'], false], [[], false]], $seen);
	}//end testAFlowRunRequestPassesItsPayloadOn()

	/**
	 * A webhook run passes its context on, and a non-array as empty, never
	 * bypassing the approval gate.
	 *
	 * @return void
	 */
	public function testAWebhookRunPassesItsContextOn(): void {
		$seen = [];
		$service = $this->createMock(WebhookAgentRunService::class);
		$service->method('run')->willReturnCallback(static function (array $context, bool $bypassApprovalGate = false) use (&$seen): bool {
			$seen[] = [$context, $bypassApprovalGate];
			return true;
		});
		$job = new WebhookAgentRunJob(time: $this->createMock(ITimeFactory::class), webhookAgentRun: $service);

		self::runJob($job, ['hook' => 'h-1']);
		self::runJob($job, null);

		self::assertSame([[['hook' => 'h-1'], false], [[], false]], $seen);
	}//end testAWebhookRunPassesItsContextOn()

	/**
	 * A title is written only when conversation, message and user are all present.
	 *
	 * @return void
	 */
	public function testATitleIsWrittenOnlyForACompleteRequest(): void {
		$writer = $this->createMock(ConversationTitleWriter::class);
		$writer->expects($this->once())
			->method('write')
			->with('c-1', 'How do I reset?', 'alice');
		$job = new ConversationTitleJob(time: $this->createMock(ITimeFactory::class), writer: $writer);

		self::runJob($job, 'not an array');
		self::runJob($job, ['conversationId' => 'c-1', 'userMessage' => '', 'userId' => 'alice']);
		self::runJob($job, ['conversationId' => '', 'userMessage' => 'x', 'userId' => 'alice']);
		self::runJob($job, ['conversationId' => 'c-1', 'userMessage' => 'x']);
		self::runJob($job, ['conversationId' => 'c-1', 'userMessage' => 'How do I reset?', 'userId' => 'alice']);
	}//end testATitleIsWrittenOnlyForACompleteRequest()

	/**
	 * A learnings capture passes its arguments on; a failure is logged as a warning.
	 *
	 * @return void
	 */
	public function testALearningsCaptureFailureIsLogged(): void {
		$seen = [];
		$capture = $this->createMock(SkillLearningsCaptureService::class);
		$capture->method('captureForRun')->willReturnCallback(static function (array $args) use (&$seen): void {
			$seen[] = $args;
			if ($args === []) {
				throw new RuntimeException('no run');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('Hermiq learnings capture job failed: no run', $this->arrayHasKey('exception'));
		$job = new SkillLearningsCaptureJob(time: $this->createMock(ITimeFactory::class), captureService: $capture, logger: $logger);

		self::runJob($job, ['runId' => 'r-1']);
		self::runJob($job, 42);

		self::assertSame([['runId' => 'r-1'], []], $seen);
	}//end testALearningsCaptureFailureIsLogged()
}//end class
