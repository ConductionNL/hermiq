<?php

/**
 * Unit tests for ApprovalService's questions and held drafts
 * (oversight-ask-and-hold): a question from an unattended run becomes one
 * pending Approval for the run's reviewer, answering it dispatches the run
 * again with the answer, an unanswered one expires after 7 days, and a held
 * draft is released as written or edited, or refused when its recipient
 * changes.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Hermiq\Mcp\HermiqToolProvider;
use OCA\Hermiq\Service\Approval\ApprovalTaskBridge;
use OCA\Hermiq\Service\Approval\DraftEditRefused;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\DeliveryResult;
use OCA\Hermiq\Service\DeliveryService;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\ScheduleService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ApprovalServiceAskAndHoldTest extends TestCase {

	private ObjectService $objectService;

	private DeliveryService $deliveryService;

	private ContainerInterface $container;

	private IUserSession $userSession;

	/**
	 * Every payload saved, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('appr-new');
				$entity->setOwner('alice');
				$entity->setObject($object);
				return $entity;
			}
		);
		$this->deliveryService = $this->createMock(DeliveryService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ApprovalService
	 */
	private function service(): ApprovalService {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));
		$taskBridge = $this->createMock(ApprovalTaskBridge::class);
		$taskBridge->method('ensureTaskFor')->willReturn(null);

		return new ApprovalService(
			$this->objectService,
			$this->userSession,
			$userManager,
			$this->createMock(IGroupManager::class),
			$this->deliveryService,
			$this->createMock(AuditTrailMapper::class),
			new RedactionService($this->createMock(IConfig::class)),
			$this->container,
			$this->createMock(LoggerInterface::class),
			$taskBridge,
		);
	}//end service()

	/**
	 * An object entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $payload The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner('alice');
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * A delivery outcome.
	 *
	 * @param bool $delivered Whether it went out.
	 *
	 * @return DeliveryResult
	 */
	private function delivered(bool $delivered = true): DeliveryResult {
		return new DeliveryResult(delivered: $delivered, channel: 'email', fellBack: false, warning: null);
	}//end delivered()

	/**
	 * A pending question Approval.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return ObjectEntity
	 */
	private function question(array $extra = []): ObjectEntity {
		return $this->entity('appr-q', $extra + [
			'status' => 'pending',
			'sourceType' => 'question',
			'scheduleId' => 'sched-1',
			'prompt' => 'Tidy the reports.',
			'requestedAt' => '2026-11-01T07:00:00+00:00',
			'draftPayload' => [
				'question' => ['text' => 'Close the three duplicate reports?', 'options' => ['Yes, close them', 'No, keep them'], 'allowOther' => false],
				'runId' => 'run-1',
				'answer' => null,
				'answeredRunId' => null,
			],
		]);
	}//end question()

	public function testAQuestionBecomesOnePendingApprovalForTheSchedulesReviewer(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->deliveryService->expects($this->once())->method('deliverApprovalRequest')
			->with($this->isInstanceOf(ObjectEntity::class), $this->isInstanceOf(ObjectEntity::class), ['lead'])
			->willReturn($this->delivered());
		$schedule = $this->entity('sched-1', ['agentId' => 'agent-1', 'prompt' => 'Tidy the reports.', 'reviewer' => 'lead', 'reviewerType' => 'user']);

		$this->service()->ensurePendingQuestion(
			schedule: $schedule,
			question: ['text' => 'Close them?', 'options' => ['Yes', 'No'], 'allowOther' => false],
			runId: 'run-1'
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('question', $this->saved[0]['sourceType']);
		$this->assertSame('pending', $this->saved[0]['status']);
		$this->assertSame('sched-1', $this->saved[0]['scheduleId']);
		$this->assertSame('lead', $this->saved[0]['reviewer']);
		$this->assertSame('Tidy the reports.', $this->saved[0]['prompt']);
		$this->assertSame(['text' => 'Close them?', 'options' => ['Yes', 'No'], 'allowOther' => false], $this->saved[0]['draftPayload']['question']);
		$this->assertSame('run-1', $this->saved[0]['draftPayload']['runId']);
	}

	public function testASecondQuestionWhileOneWaitsReturnsTheOpenOne(): void {
		$open = $this->question();
		$this->objectService->method('findAll')->willReturn([$open]);
		$this->deliveryService->expects($this->never())->method('deliverApprovalRequest');

		$result = $this->service()->ensurePendingQuestion(
			schedule: $this->entity('sched-1', ['agentId' => 'agent-1']),
			question: ['text' => 'Another?']
		);

		$this->assertSame($open, $result);
		$this->assertSame([], $this->saved);
	}

	public function testAnsweringDispatchesTheRunAgainWithTheAnswer(): void {
		$this->objectService->method('find')->willReturn($this->entity('sched-1', ['agentId' => 'agent-1']));
		$scheduleService = $this->createMock(ScheduleService::class);
		$scheduleService->expects($this->once())->method('runNow')->with(
			$this->isInstanceOf(ObjectEntity::class),
			true,
			"Tidy the reports.\n\nYou asked: Close the three duplicate reports?\nThe person answered: Yes, close them"
		);
		$scheduleService->method('getLastRunId')->willReturn('run-2');
		$this->container->method('get')->willReturn($scheduleService);

		$result = $this->service()->answerQuestion(approval: $this->question(), deciderUid: 'lead', answer: 'Yes, close them');

		$this->assertSame(['status' => 'approved', 'ran' => true], $result);
		$this->assertSame('approved', $this->saved[0]['status']);
		$this->assertSame('lead', $this->saved[0]['decidedBy']);
		$this->assertSame('Yes, close them', $this->saved[0]['draftPayload']['answer']);
		// The history links the question, the answer and the new run.
		$last = $this->saved[count($this->saved) - 1];
		$this->assertSame('run-1', $last['draftPayload']['runId']);
		$this->assertSame('run-2', $last['draftPayload']['answeredRunId']);
	}

	public function testAnAnswerOutsideTheSuggestionsIsRefusedAndNothingRuns(): void {
		$this->container->expects($this->never())->method('get');

		try {
			$this->service()->answerQuestion(approval: $this->question(), deciderUid: 'lead', answer: 'Maybe');
			$this->fail('An answer outside the suggestions must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Choose one of the suggested answers.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}

	public function testOnlyAPendingQuestionCanBeAnswered(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service()->answerQuestion(approval: $this->question(['status' => 'denied']), deciderUid: 'lead', answer: 'Yes, close them');
	}

	public function testApprovingAQuestionWithoutAnAnswerLeavesItPending(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->service()->approve($this->question(), 'lead');

		$this->assertSame(['status' => 'pending', 'ran' => false], $result);
		$this->assertSame([], $this->saved);
	}

	public function testAQuestionUnansweredForSevenDaysExpiresAsDenied(): void {
		$old = $this->question();
		$fresh = $this->question(['requestedAt' => '2026-11-13T07:00:00+00:00']);
		$this->objectService->method('findAll')->willReturn([$old, $fresh]);

		$expired = $this->service()->expireUnansweredQuestions(now: new DateTimeImmutable('2026-11-14T08:00:00+00:00'));

		$this->assertSame(1, $expired);
		$this->assertCount(1, $this->saved);
		$this->assertSame('denied', $this->saved[0]['status']);
		$this->assertSame('No answer within 7 days.', $this->saved[0]['reason']);
		$this->assertSame('system', $this->saved[0]['decidedBy']);
	}

	public function testHeldOutputBecomesAPendingDraftAndIsNotDelivered(): void {
		$this->deliveryService->expects($this->never())->method('deliver');
		$this->deliveryService->expects($this->once())->method('deliverApprovalRequest')->willReturn($this->delivered());

		$this->service()->ensurePendingDraft(
			schedule: $this->entity('sched-1', ['agentId' => 'agent-1']),
			channel: 'email',
			output: 'Open tickets: 41',
			conversationUuid: 'conv-1'
		);

		$this->assertSame('draft', $this->saved[0]['sourceType']);
		$this->assertSame('alice', $this->saved[0]['reviewer']);
		$this->assertSame(
			['kind' => 'delivery', 'channel' => 'email', 'output' => 'Open tickets: 41', 'conversationUuid' => 'conv-1'],
			$this->saved[0]['draftPayload']
		);
	}

	public function testAnEditedReleaseDeliversTheEditAndRecordsBothVersions(): void {
		$schedule = $this->entity('sched-1', ['agentId' => 'agent-1']);
		$this->objectService->method('find')->willReturn($schedule);
		$this->deliveryService->expects($this->once())->method('deliver')
			->with('email', 'Open tickets: 14', $schedule, 'conv-1')
			->willReturn($this->delivered());
		$draft = $this->entity('appr-d', [
			'status' => 'pending',
			'sourceType' => 'draft',
			'scheduleId' => 'sched-1',
			'draftPayload' => ['kind' => 'delivery', 'channel' => 'email', 'output' => 'Open tickets: 41', 'conversationUuid' => 'conv-1'],
		]);

		$result = $this->service()->releaseDraft(approval: $draft, deciderUid: 'lead', edits: ['output' => 'Open tickets: 14']);

		$this->assertSame(['status' => 'approved', 'ran' => true], $result);
		$this->assertSame(['original' => 'Open tickets: 41', 'sent' => 'Open tickets: 14'], $this->saved[0]['editedOutput']);
		$this->assertSame('approved', $this->saved[0]['status']);
	}

	public function testApprovingADraftReleasesItAsWritten(): void {
		$this->objectService->method('find')->willReturn($this->entity('sched-1', []));
		$this->deliveryService->expects($this->once())->method('deliver')
			->with('talk', 'Done.', $this->isInstanceOf(ObjectEntity::class), null)
			->willReturn($this->delivered());
		$draft = $this->entity('appr-d', [
			'status' => 'pending',
			'sourceType' => 'draft',
			'scheduleId' => 'sched-1',
			'draftPayload' => ['kind' => 'delivery', 'channel' => 'talk', 'output' => 'Done.', 'conversationUuid' => ''],
		]);

		$result = $this->service()->approve($draft, 'lead');

		$this->assertSame(['status' => 'approved', 'ran' => true], $result);
		$this->assertNull($this->saved[0]['editedOutput']);
	}

	public function testAReleaseWithADifferentRecipientIsRefusedAndNothingIsSent(): void {
		$this->container->expects($this->never())->method('get');
		$draft = $this->entity('appr-d', [
			'status' => 'pending',
			'sourceType' => 'draft',
			'draftPayload' => [
				'kind' => 'tool',
				'toolId' => 'hermiq.sendMail',
				'arguments' => ['to' => 'council@example.org', 'subject' => 'Weekly', 'body' => 'Hi'],
				'editable' => ['subject', 'body'],
			],
		]);

		$this->expectException(DraftEditRefused::class);
		try {
			$this->service()->releaseDraft(approval: $draft, deciderUid: 'lead', edits: ['to' => 'other@example.org']);
		} finally {
			$this->assertSame([], $this->saved);
		}
	}

	public function testAReleasedMailRunsTheToolAsTheOwnerWithTheEditedBody(): void {
		$provider = $this->createMock(HermiqToolProvider::class);
		$provider->expects($this->once())->method('invokeTool')
			->with('hermiq.sendMail', ['to' => 'council@example.org', 'subject' => 'Weekly', 'body' => 'Hello'])
			->willReturn(['sent' => true]);
		$this->container->method('get')->with(HermiqToolProvider::class)->willReturn($provider);
		// Twice to save the decision as the owner, twice to send as the owner.
		$this->userSession->expects($this->exactly(4))->method('setUser');
		$draft = $this->entity('appr-d', [
			'status' => 'pending',
			'sourceType' => 'draft',
			'draftPayload' => [
				'kind' => 'tool',
				'toolId' => 'hermiq.sendMail',
				'arguments' => ['to' => 'council@example.org', 'subject' => 'Weekly', 'body' => 'Hi'],
				'editable' => ['subject', 'body'],
			],
		]);

		$result = $this->service()->releaseDraft(approval: $draft, deciderUid: 'alice', edits: ['body' => 'Hello']);

		$this->assertSame(['status' => 'approved', 'ran' => true], $result);
		$this->assertSame(['original' => "Subject: Weekly\n\nHi", 'sent' => "Subject: Weekly\n\nHello"], $this->saved[0]['editedOutput']);
	}

	public function testAToolDraftIsRoutedToTheAgentOwner(): void {
		$this->objectService->method('find')->willReturn($this->entity('agent-1', []));
		$this->deliveryService->expects($this->once())->method('deliverApprovalRequestForToolInvocation')
			->willReturn($this->delivered());

		$this->service()->ensurePendingToolDraft(
			agentId: 'agent-1',
			toolId: 'hermiq.sendMail',
			arguments: ['to' => 'a@example.org', 'subject' => 's', 'body' => 'b']
		);

		$this->assertSame('draft', $this->saved[0]['sourceType']);
		$this->assertSame('alice', $this->saved[0]['reviewer']);
		$this->assertSame('tool', $this->saved[0]['draftPayload']['kind']);
		$this->assertSame(['subject', 'body'], $this->saved[0]['draftPayload']['editable']);
	}

	public function testAHeldApprovalDoesNotStandInForTheSchedulesGate(): void {
		$this->objectService->method('findAll')->willReturn([$this->question()]);
		$this->deliveryService->method('deliverApprovalRequest')->willReturn($this->delivered());

		$this->service()->ensurePendingApproval(schedule: $this->entity('sched-1', ['agentId' => 'agent-1']));

		$this->assertCount(1, $this->saved);
		$this->assertSame('schedule', $this->saved[0]['sourceType']);
	}
}//end class
