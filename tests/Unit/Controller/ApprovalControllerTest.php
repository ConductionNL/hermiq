<?php

/**
 * Unit tests for ApprovalController (human-approval-gate-enforcement).
 *
 * Focuses on the Art. 14 separation-of-duties IDOR guard: only the resolved reviewer
 * (or a reviewer-group member, or an instance admin) may approve/deny; a non-reviewer
 * gets 404 (no leak) and never reaches the service; an already-decided approval yields
 * 409; approve delegates to ApprovalService (which runs the gated schedule) and deny
 * records without running.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ApprovalController;
use OCA\Hermiq\Service\Approval\ApprovalInbox;
use OCA\Hermiq\Service\Approval\DraftEditRefused;
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use ReflectionMethod;

/**
 * Tests for the human-approval-gate-enforcement ApprovalController.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */
class ApprovalControllerTest extends TestCase {

	/**
	 * The reviewer inbox + reviewer guard the controller consults.
	 *
	 * @var ApprovalInbox&MockObject
	 */
	private ApprovalInbox&MockObject $inbox;

	/**
	 * Fresh inbox mock per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->inbox = $this->createMock(ApprovalInbox::class);
	}//end setUp()

	/**
	 * Build an approval ObjectEntity with the given status.
	 *
	 * @param string $status The approval status.
	 *
	 * @return ObjectEntity
	 */
	private function approval(string $status = 'pending'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('appr-1');
		$entity->setObject(['status' => $status, 'scheduleId' => 'sched-1', 'reviewer' => 'bob', 'reviewerType' => 'user']);
		return $entity;
	}//end approval()

	/**
	 * A session with the given (or no) user.
	 *
	 * @param string|null $uid The UID, or null for unauthenticated.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * Build the controller with the given collaborators.
	 *
	 * @param ApprovalService $approvalService The approval service.
	 * @param IUserSession $session The user session.
	 *
	 * @return ApprovalController
	 */
	private function controller(ApprovalService $approvalService, IUserSession $session): ApprovalController {
		return new ApprovalController(
			$this->createMock(IRequest::class),
			$approvalService,
			$session,
			$this->createMock(LoggerInterface::class),
			$this->inbox
		);

	}//end controller()

	/**
	 * The inbox returns the reviewer's pending approvals.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	public function testIndexReturnsReviewerPending(): void {
		$service = $this->createMock(ApprovalService::class);
		$this->inbox->method('listPendingForReviewer')->willReturn([['id' => 'appr-1']]);

		$response = $this->controller($service, $this->session('bob'))->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);

	}//end testIndexReturnsReviewerPending()

	/**
	 * An unauthenticated caller gets 401.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	public function testUnauthenticatedIsRejected(): void {
		$service = $this->createMock(ApprovalService::class);
		$response = $this->controller($service, $this->session(null))->approve('appr-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedIsRejected()

	/**
	 * The reviewer approves: the service runs the schedule and 200 is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
	 */
	public function testReviewerApproves(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('pending'));
		$this->inbox->method('isReviewer')->willReturn(true);
		$service->expects($this->once())->method('approve')->willReturn(['status' => 'approved', 'ran' => true]);

		$response = $this->controller($service, $this->session('bob'))->approve('appr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('approved', $response->getData()['status']);
		$this->assertTrue($response->getData()['ran']);

	}//end testReviewerApproves()

	/**
	 * A non-reviewer is refused with 404 and never reaches approve().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
	 */
	public function testNonReviewerRefused(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('pending'));
		$this->inbox->method('isReviewer')->willReturn(false);
		$service->expects($this->never())->method('approve');

		$response = $this->controller($service, $this->session('mallory'))->approve('appr-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testNonReviewerRefused()

	/**
	 * A missing approval is 404 and never reaches approve().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
	 */
	public function testMissingApprovalIsNotFound(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn(null);
		$service->expects($this->never())->method('approve');

		$response = $this->controller($service, $this->session('bob'))->approve('appr-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testMissingApprovalIsNotFound()

	/**
	 * An already-decided approval yields 409 and never re-runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-2
	 */
	public function testAlreadyDecidedIsConflict(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('approved'));
		$this->inbox->method('isReviewer')->willReturn(true);
		$service->expects($this->never())->method('approve');

		$response = $this->controller($service, $this->session('bob'))->approve('appr-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testAlreadyDecidedIsConflict()

	/**
	 * The reviewer denies: the service records the denial and 200 is returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-3
	 */
	public function testReviewerDenies(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('pending'));
		$this->inbox->method('isReviewer')->willReturn(true);
		$service->expects($this->once())->method('deny');

		$response = $this->controller($service, $this->session('bob'))->deny('appr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('denied', $response->getData()['status']);

	}//end testReviewerDenies()

	/**
	 * A non-reviewer cannot deny (404, never reaches deny()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-3
	 */
	public function testNonReviewerCannotDeny(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('pending'));
		$this->inbox->method('isReviewer')->willReturn(false);
		$service->expects($this->never())->method('deny');

		$response = $this->controller($service, $this->session('mallory'))->deny('appr-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testNonReviewerCannotDeny()

	/**
	 * The controller with a request whose parameters are $params.
	 *
	 * @param ApprovalService      $approvalService The approval service.
	 * @param IUserSession         $session         The user session.
	 * @param array<string, mixed> $params          The request parameters.
	 *
	 * @return ApprovalController
	 */
	private function resolvingController(ApprovalService $approvalService, IUserSession $session, array $params): ApprovalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new ApprovalController($request, $approvalService, $session, $this->createMock(LoggerInterface::class), $this->inbox);

	}//end resolvingController()

	/**
	 * A service whose pending approval only `bob` reviews.
	 *
	 * @return ApprovalService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function bobReviews(): ApprovalService {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('pending'));
		$this->inbox->method('isReviewer')->willReturnCallback(
			static fn (ObjectEntity $approval, string $uid): bool => $uid === 'bob'
		);
		return $service;

	}//end bobReviews()

	/**
	 * `granted` on the resolve route approves as the reviewer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function testResolveGrantedApproves(): void {
		$service = $this->bobReviews();
		$service->expects($this->once())->method('approve')
			->with($this->isInstanceOf(ObjectEntity::class), 'bob')
			->willReturn(['status' => 'approved', 'ran' => true]);
		$service->expects($this->never())->method('deny');

		$response = $this->resolvingController($service, $this->session('bob'), ['decision' => 'granted'])->resolve('appr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['approvalId' => 'appr-1', 'status' => 'approved', 'ran' => true], $response->getData());

	}//end testResolveGrantedApproves()

	/**
	 * `refused` on the resolve route denies as the reviewer, with the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function testResolveRefusedDenies(): void {
		$service = $this->bobReviews();
		$service->expects($this->once())->method('deny')->with($this->isInstanceOf(ObjectEntity::class), 'bob', 'not now');
		$service->expects($this->never())->method('approve');

		$response = $this->resolvingController($service, $this->session('bob'), ['decision' => 'refused', 'reason' => 'not now'])->resolve('appr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['approvalId' => 'appr-1', 'status' => 'denied'], $response->getData());

	}//end testResolveRefusedDenies()

	/**
	 * A caller who is not the reviewer is refused with the same 404 for either
	 * decision, and a forged reviewer parameter changes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function testResolveRefusesANonReviewer(): void {
		$service = $this->bobReviews();
		$service->expects($this->never())->method('approve');
		$service->expects($this->never())->method('deny');

		foreach (['granted', 'refused', 'maybe'] as $decision) {
			$params = ['decision' => $decision, 'uid' => 'bob', 'reviewer' => 'bob', 'deciderUid' => 'bob'];
			$response = $this->resolvingController($service, $this->session('mallory'), $params)->resolve('appr-1');
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus(), $decision);
		}

	}//end testResolveRefusesANonReviewer()

	/**
	 * An unauthenticated caller gets 401 and a decided approval 409, before any decision is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function testResolveKeepsTheApprovalGuard(): void {
		$service = $this->createMock(ApprovalService::class);
		$service->method('loadApproval')->willReturn($this->approval('approved'));
		$this->inbox->method('isReviewer')->willReturn(true);
		$service->expects($this->never())->method('approve');

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$this->resolvingController($service, $this->session(null), ['decision' => 'granted'])->resolve('appr-1')->getStatus()
		);
		$this->assertSame(
			Http::STATUS_CONFLICT,
			$this->resolvingController($service, $this->session('bob'), ['decision' => 'granted'])->resolve('appr-1')->getStatus()
		);

	}//end testResolveKeepsTheApprovalGuard()

	/**
	 * The reviewer with an unknown decision gets 400 and nothing is decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-about-a-subject/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function testResolveRejectsAnUnknownDecision(): void {
		$service = $this->bobReviews();
		$service->expects($this->never())->method('approve');
		$service->expects($this->never())->method('deny');

		foreach ([[], ['decision' => 'approve'], ['decision' => ['granted']]] as $params) {
			$response = $this->resolvingController($service, $this->session('bob'), $params)->resolve('appr-1');
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		}

	}//end testResolveRejectsAnUnknownDecision()
	/**
	 * A forged userId, uid or deciderUid in the request never becomes the decider:
	 * approve, deny and resolve all decide as the session user.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
	 */
	public function testTheDeciderIsTheSessionUserNotARequestParameter(): void {
		$forged = ['userId' => 'mallory', 'uid' => 'mallory', 'deciderUid' => 'mallory', 'reviewer' => 'mallory'];
		$routes = [
			'approve' => ['approve', []],
			'deny' => ['deny', []],
			'resolve-granted' => ['approve', ['decision' => 'granted']],
			'resolve-refused' => ['deny', ['decision' => 'refused']],
		];
		foreach ($routes as $route => [$decides, $extra]) {
			$service = $this->createMock(ApprovalService::class);
			$service->method('loadApproval')->willReturn($this->approval('pending'));
			$this->inbox->method('isReviewer')->willReturn(true);
			$deciders = [];
			$service->method('approve')->willReturnCallback(
				function (ObjectEntity $approval, string $deciderUid) use (&$deciders): array {
					$deciders[] = ['approve', $deciderUid];
					return ['status' => 'approved', 'ran' => true];
				}
			);
			$service->method('deny')->willReturnCallback(
				function (ObjectEntity $approval, string $deciderUid) use (&$deciders): void {
					$deciders[] = ['deny', $deciderUid];
				}
			);

			$controller = $this->resolvingController($service, $this->session('anna'), $forged + $extra);
			$method = explode('-', $route)[0];
			$response = $controller->$method('appr-1');

			$this->assertSame(Http::STATUS_OK, $response->getStatus(), $route);
			$this->assertSame([[$decides, 'anna']], $deciders, $route);
		}

	}//end testTheDeciderIsTheSessionUserNotARequestParameter()
	/**
	 * Approve, deny and resolve change state, so they keep Nextcloud's CSRF check.
	 *
	 * @return void
	 */
	public function testDecisionsRequireTheCsrfToken(): void {
		foreach (['approve', 'deny', 'resolve'] as $name) {
			$method = new ReflectionMethod(ApprovalController::class, $name);
			$this->assertCount(0, $method->getAttributes(NoCSRFRequired::class), $name);
			$this->assertStringNotContainsString('@NoCSRFRequired', (string)$method->getDocComment(), $name);
		}

	}//end testDecisionsRequireTheCsrfToken()

	/**
	 * A reviewer answers a question; the session user is the decider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	public function testAReviewerAnswersAQuestion(): void {
		$service = $this->bobReviews();
		$service->expects($this->once())->method('answerQuestion')
			->with($this->isInstanceOf(ObjectEntity::class), 'bob', 'Yes, close them')
			->willReturn(['status' => 'approved', 'ran' => true]);

		$response = $this->resolvingController($service, $this->session('bob'), ['answer' => 'Yes, close them'])->answer('appr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['approvalId' => 'appr-1', 'status' => 'approved', 'ran' => true], $response->getData());

	}//end testAReviewerAnswersAQuestion()

	/**
	 * A refused answer is 422; a missing one is 400; a non-reviewer gets 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	public function testAnswerRefusals(): void {
		$service = $this->bobReviews();
		$service->method('answerQuestion')->willThrowException(new \InvalidArgumentException('Choose one of the suggested answers.'));

		$refused = $this->resolvingController($service, $this->session('bob'), ['answer' => 'Maybe'])->answer('appr-1');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame('Choose one of the suggested answers.', $refused->getData()['error']);

		$missing = $this->resolvingController($service, $this->session('bob'), [])->answer('appr-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $missing->getStatus());

		$stranger = $this->resolvingController($service, $this->session('eve'), ['answer' => 'Yes'])->answer('appr-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $stranger->getStatus());

	}//end testAnswerRefusals()

	/**
	 * A reviewer releases a draft with edits.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function testAReviewerReleasesADraftWithEdits(): void {
		$service = $this->bobReviews();
		$service->expects($this->once())->method('releaseDraft')
			->with($this->isInstanceOf(ObjectEntity::class), 'bob', ['output' => 'Open tickets: 14'])
			->willReturn(['status' => 'approved', 'ran' => true]);

		$response = $this->resolvingController($service, $this->session('bob'), ['edits' => ['output' => 'Open tickets: 14']])->release('appr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['ran']);

	}//end testAReviewerReleasesADraftWithEdits()

	/**
	 * Changed recipients are refused with 422 and nothing is sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
	 */
	public function testAReleaseWithChangedRecipientsIs422(): void {
		$service = $this->bobReviews();
		$service->method('releaseDraft')->willThrowException(new DraftEditRefused('The field "to" of this draft cannot be changed.'));

		$response = $this->resolvingController($service, $this->session('bob'), ['edits' => ['to' => 'other@example.org']])->release('appr-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('The field "to" of this draft cannot be changed.', $response->getData()['error']);

		$notAnObject = $this->resolvingController($service, $this->session('bob'), ['edits' => 'x'])->release('appr-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $notAnObject->getStatus());

	}//end testAReleaseWithChangedRecipientsIs422()

	/**
	 * Answer and release change state, so they keep Nextcloud's CSRF check.
	 *
	 * @return void
	 */
	public function testAnswerAndReleaseRequireTheCsrfToken(): void {
		foreach (['answer', 'release'] as $name) {
			$method = new ReflectionMethod(ApprovalController::class, $name);
			$this->assertCount(0, $method->getAttributes(NoCSRFRequired::class), $name);
			$this->assertStringNotContainsString('@NoCSRFRequired', (string)$method->getDocComment(), $name);
		}

	}//end testAnswerAndReleaseRequireTheCsrfToken()
}//end class
