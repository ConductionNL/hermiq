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
use OCA\Hermiq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the human-approval-gate-enforcement ApprovalController.
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */
class ApprovalControllerTest extends TestCase
{

    /**
     * Build an approval ObjectEntity with the given status.
     *
     * @param string $status The approval status.
     *
     * @return ObjectEntity
     */
    private function approval(string $status='pending'): ObjectEntity
    {
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
    private function session(?string $uid): IUserSession
    {
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
     * @param IUserSession    $session         The user session.
     *
     * @return ApprovalController
     */
    private function controller(ApprovalService $approvalService, IUserSession $session): ApprovalController
    {
        return new ApprovalController(
            $this->createMock(IRequest::class),
            $approvalService,
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * The inbox returns the reviewer's pending approvals.
     *
     * @return void
     *
     * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#task-4-1
     */
    public function testIndexReturnsReviewerPending(): void
    {
        $service = $this->createMock(ApprovalService::class);
        $service->method('listPendingForReviewer')->willReturn([['id' => 'appr-1']]);

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
    public function testUnauthenticatedIsRejected(): void
    {
        $service  = $this->createMock(ApprovalService::class);
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
    public function testReviewerApproves(): void
    {
        $service = $this->createMock(ApprovalService::class);
        $service->method('loadApproval')->willReturn($this->approval('pending'));
        $service->method('isReviewer')->willReturn(true);
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
    public function testNonReviewerRefused(): void
    {
        $service = $this->createMock(ApprovalService::class);
        $service->method('loadApproval')->willReturn($this->approval('pending'));
        $service->method('isReviewer')->willReturn(false);
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
    public function testMissingApprovalIsNotFound(): void
    {
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
    public function testAlreadyDecidedIsConflict(): void
    {
        $service = $this->createMock(ApprovalService::class);
        $service->method('loadApproval')->willReturn($this->approval('approved'));
        $service->method('isReviewer')->willReturn(true);
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
    public function testReviewerDenies(): void
    {
        $service = $this->createMock(ApprovalService::class);
        $service->method('loadApproval')->willReturn($this->approval('pending'));
        $service->method('isReviewer')->willReturn(true);
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
    public function testNonReviewerCannotDeny(): void
    {
        $service = $this->createMock(ApprovalService::class);
        $service->method('loadApproval')->willReturn($this->approval('pending'));
        $service->method('isReviewer')->willReturn(false);
        $service->expects($this->never())->method('deny');

        $response = $this->controller($service, $this->session('mallory'))->deny('appr-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testNonReviewerCannotDeny()
}//end class
