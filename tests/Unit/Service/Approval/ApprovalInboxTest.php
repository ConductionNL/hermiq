<?php

/**
 * Unit tests for ApprovalInbox (human-approval-gate-enforcement).
 *
 * Exercises the reviewer side of the gate without a live Nextcloud/OpenRegister:
 *   - isReviewer admits the reviewer user / group member / instance admin, and refuses
 *     the owner unless owner == reviewer (separation of duties);
 *   - listPendingForReviewer lists only pending approvals routed to the user, with
 *     their stored arguments and preview.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalInbox;
use OCA\Hermiq\Service\ApprovalPreviewBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApprovalInbox.
 */
class ApprovalInboxTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Set up fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}//end setUp()

	/**
	 * Build an ApprovalInbox with the current mocks.
	 *
	 * @return ApprovalInbox
	 */
	private function inbox(): ApprovalInbox {
		return new ApprovalInbox(
			$this->objectService,
			$this->groupManager,
			$this->container,
			$this->createMock(LoggerInterface::class),
		);
	}//end inbox()

	/**
	 * Build an approval ObjectEntity.
	 *
	 * @param array<string,mixed> $payload The approval body.
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function approval(array $payload, string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('appr-1');
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end approval()

	/**
	 * isReviewer: the reviewer user is admitted; a different non-admin user (incl. the
	 * owner when owner != reviewer) is refused; an instance admin is always admitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
	 */
	public function testIsReviewerUserAndAdmin(): void {
		$this->groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => ($uid === 'root')
		);

		$approval = $this->approval(['status' => 'pending', 'reviewer' => 'bob', 'reviewerType' => 'user'], 'alice');
		$service = $this->inbox();

		$this->assertTrue($service->isReviewer($approval, 'bob'), 'The reviewer user may decide.');
		$this->assertFalse($service->isReviewer($approval, 'alice'), 'The owner (≠ reviewer) may NOT decide.');
		$this->assertFalse($service->isReviewer($approval, 'mallory'), 'A stranger may not decide.');
		$this->assertTrue($service->isReviewer($approval, 'root'), 'An instance admin may always decide.');

	}//end testIsReviewerUserAndAdmin()

	/**
	 * isReviewer with a group reviewer admits any member of the reviewer group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
	 */
	public function testIsReviewerGroupMember(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($uid === 'bob' && $gid === 'reviewers')
		);

		$approval = $this->approval(['status' => 'pending', 'reviewer' => 'reviewers', 'reviewerType' => 'group']);
		$service = $this->inbox();

		$this->assertTrue($service->isReviewer($approval, 'bob'), 'A reviewer-group member may decide.');
		$this->assertFalse($service->isReviewer($approval, 'eve'), 'A non-member may not decide.');

	}//end testIsReviewerGroupMember()

	/**
	 * listPendingForReviewer returns only pending approvals routed to the user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	public function testListPendingForReviewer(): void {
		$mine = $this->approval(['status' => 'pending', 'scheduleId' => 's1', 'reviewer' => 'bob', 'reviewerType' => 'user']);
		$mine->setUuid('mine');
		$other = $this->approval(['status' => 'pending', 'scheduleId' => 's2', 'reviewer' => 'carol', 'reviewerType' => 'user']);
		$other->setUuid('other');

		$this->objectService->method('findAll')->willReturn([$mine, $other]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$records = $this->inbox()->listPendingForReviewer('bob');

		$this->assertCount(1, $records, 'Only approvals routed to the caller are listed.');
		$this->assertSame('mine', $records[0]['id']);

	}//end testListPendingForReviewer()

	/**
	 * Each inbox record carries its source type, the stored arguments and the
	 * preview; still only the reviewer's own approvals are listed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
	 */
	public function testInboxRecordsCarryThePreview(): void {
		$mine = $this->approval(['status' => 'pending', 'sourceType' => 'toolcall', 'toolId' => 'files.deleteFile', 'toolArguments' => ['path' => '/a.pdf'], 'agentId' => 'agent-1', 'reviewer' => 'bob', 'reviewerType' => 'user']);
		$mine->setUuid('mine');
		$other = $this->approval(['status' => 'pending', 'sourceType' => 'toolcall', 'toolId' => 'files.deleteFile', 'reviewer' => 'carol', 'reviewerType' => 'user']);
		$other->setUuid('other');

		$this->objectService->method('findAll')->willReturn([$mine, $other]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$preview = ['kind' => 'toolcall', 'heldBecause' => 'x', 'tools' => [['id' => 'files.deleteFile', 'effect' => 'deletes']]];
		$builder = $this->createMock(ApprovalPreviewBuilder::class);
		$builder->expects($this->once())->method('build')->willReturn($preview);
		$this->container->method('get')->willReturn($builder);

		$records = $this->inbox()->listPendingForReviewer('bob');

		$this->assertCount(1, $records);
		$this->assertSame('toolcall', $records[0]['sourceType']);
		$this->assertSame(['path' => '/a.pdf'], $records[0]['toolArguments']);
		$this->assertSame($preview, $records[0]['preview']);
	}//end testInboxRecordsCarryThePreview()

	/**
	 * The inbox lists every field of a pending approval routed to the reviewer,
	 * in a fixed order; rows that are not entities or not pending are skipped,
	 * non-array stored arguments read as null, and an unavailable preview
	 * builder leaves the preview null instead of failing the inbox.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	public function testInboxRecordShapeAndSkippedRows(): void {
		$decided = $this->approval(['status' => 'approved', 'reviewer' => 'bob', 'reviewerType' => 'user']);
		$decided->setUuid('decided');
		$full = $this->approval(
			[
				'status' => 'pending',
				'scheduleId' => 's1',
				'agentId' => 'agent-1',
				'prompt' => 'Do it',
				'requestedAt' => '2026-01-01T00:00:00+00:00',
				'reviewer' => 'bob',
				'reviewerType' => 'user',
				'sourceType' => 'toolcall',
				'toolId' => 'files.deleteFile',
				'toolArguments' => ['path' => '/a.pdf'],
			]
		);
		$full->setUuid('full');
		$bare = $this->approval(['status' => 'pending', 'reviewer' => 'bob', 'toolArguments' => 'not-an-array']);
		$bare->setUuid('bare');

		$this->objectService->method('findAll')->willReturn(['not-an-entity', $decided, $full, $bare]);
		$this->container->method('get')->willThrowException(new \RuntimeException('no builder'));

		$records = $this->inbox()->listPendingForReviewer('bob');

		$this->assertSame(
			[
				[
					'id' => 'full',
					'scheduleId' => 's1',
					'agentId' => 'agent-1',
					'prompt' => 'Do it',
					'requestedAt' => '2026-01-01T00:00:00+00:00',
					'reviewer' => 'bob',
					'reviewerType' => 'user',
					'status' => 'pending',
					'sourceType' => 'toolcall',
					'toolId' => 'files.deleteFile',
					'toolArguments' => ['path' => '/a.pdf'],
					'preview' => null,
				],
				[
					'id' => 'bare',
					'scheduleId' => '',
					'agentId' => '',
					'prompt' => '',
					'requestedAt' => null,
					'reviewer' => 'bob',
					'reviewerType' => 'user',
					'status' => 'pending',
					'sourceType' => 'schedule',
					'toolId' => '',
					'toolArguments' => null,
					'preview' => null,
				],
			],
			$records
		);
	}//end testInboxRecordShapeAndSkippedRows()

	/**
	 * An empty user id has no inbox and may decide nothing, without a query.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-1-2
	 */
	public function testEmptyUserHasNoInboxAndIsNoReviewer(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => ($uid === '')
		);
		$approval = $this->approval(['status' => 'pending', 'reviewer' => '', 'reviewerType' => 'user']);
		$service = $this->inbox();

		$this->assertSame([], $service->listPendingForReviewer(''));
		$this->assertFalse($service->isReviewer($approval, ''), 'An empty user id is refused even when isAdmin would say yes.');
		$this->assertFalse($service->isReviewer($this->approval(['status' => 'pending']), 'bob'), 'An approval without a reviewer admits no non-admin.');
	}//end testEmptyUserHasNoInboxAndIsNoReviewer()

	/**
	 * A question record carries its suggested answers and a draft record its
	 * text and recipients; other records gain neither (oversight-ask-and-hold).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function testQuestionAndDraftRecordsCarryWhatIsHeld(): void {
		$question = ['text' => 'Close them?', 'options' => ['Yes', 'No'], 'allowOther' => false];
		$draft = ['kind' => 'tool', 'toolId' => 'hermiq.sendMail', 'arguments' => ['to' => 'a@example.org', 'subject' => 's', 'body' => 'b'], 'editable' => ['subject', 'body']];
		$asked = $this->approval(['status' => 'pending', 'sourceType' => 'question', 'draftPayload' => ['question' => $question, 'runId' => 'r1'], 'reviewer' => 'bob', 'reviewerType' => 'user']);
		$asked->setUuid('asked');
		$held = $this->approval(['status' => 'pending', 'sourceType' => 'draft', 'draftPayload' => $draft, 'reviewer' => 'bob', 'reviewerType' => 'user']);
		$held->setUuid('held');
		$gate = $this->approval(['status' => 'pending', 'sourceType' => 'schedule', 'draftPayload' => ['x' => 1], 'reviewer' => 'bob', 'reviewerType' => 'user']);
		$gate->setUuid('gate');
		$this->objectService->method('findAll')->willReturn([$asked, $held, $gate]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$records = $this->inbox()->listPendingForReviewer('bob');

		$this->assertSame($question, $records[0]['question']);
		$this->assertArrayNotHasKey('draft', $records[0]);
		$this->assertSame($draft, $records[1]['draft']);
		$this->assertArrayNotHasKey('question', $records[2]);
		$this->assertArrayNotHasKey('draft', $records[2]);
	}//end testQuestionAndDraftRecordsCarryWhatIsHeld()
}//end class
