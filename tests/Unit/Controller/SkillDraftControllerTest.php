<?php

/**
 * Unit tests for SkillDraftController (skill-self-improvement).
 *
 * Guard-order coverage: an invisible draft/skill is 404 BEFORE the action check
 * (never a 403 that confirms existence); a caller without `skill.review-draft`
 * gets 403 with draft, Approval and skill untouched; accept decides by
 * transitioning the linked Approval (the SAME path a generic-inbox approval
 * runs) and surfaces the new version id; a refused transition (edited draft
 * awaiting re-qualification) is a 409, never a silent success; reject marks the
 * curator's bad-learnings refs on the DRAFT before denying; manual propose is
 * owner-guarded and maps a gate block to a structured 429.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillDraftController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillConsolidationService;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SkillDraftController guard + decision tests (skill-self-improvement).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller's own collaborator set.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */
class SkillDraftControllerTest extends TestCase {

	/**
	 * The prepared consolidation mock.
	 *
	 * @var SkillConsolidationService&MockObject
	 */
	private SkillConsolidationService&MockObject $consolidation;

	/**
	 * The prepared approval-service mock.
	 *
	 * @var ApprovalService&MockObject
	 */
	private ApprovalService&MockObject $approvalService;

	/**
	 * The prepared action-auth mock.
	 *
	 * @var ActionAuthService&MockObject
	 */
	private ActionAuthService&MockObject $actionAuth;

	/**
	 * The prepared skill-service mock.
	 *
	 * @var SkillService&MockObject
	 */
	private SkillService&MockObject $skillService;

	/**
	 * The prepared version-service mock.
	 *
	 * @var SkillVersionService&MockObject
	 */
	private SkillVersionService&MockObject $versionService;

	/**
	 * The prepared request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * A user session resolving to $uid (null = unauthenticated).
	 *
	 * @param string|null $uid The UID, or null.
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
	 * Build the controller under test.
	 *
	 * @param IUserSession $session The user session.
	 *
	 * @return SkillDraftController
	 */
	private function controller(IUserSession $session, bool $callerIsAdmin = false): SkillDraftController {
		$this->skillService = $this->createMock(SkillService::class);
		$this->consolidation = $this->createMock(SkillConsolidationService::class);
		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->versionService = $this->createMock(SkillVersionService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->request = $this->createMock(IRequest::class);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($callerIsAdmin);

		return new SkillDraftController(
			$this->request,
			$this->skillService,
			$this->consolidation,
			$this->approvalService,
			$this->versionService,
			$this->actionAuth,
			new SeedCustodyService(groupManager: $groupManager),
			$session,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A draft entity linked to skill-1.
	 *
	 * @param array<string, mixed> $payload Payload overrides.
	 *
	 * @return ObjectEntity
	 */
	private function draft(array $payload = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('draft-1');
		$entity->setObject(
			array_merge(
				[
					'skillId' => 'skill-1',
					'status' => 'awaiting-approval',
					'approvalId' => 'appr-1',
				],
				$payload
			)
		);
		return $entity;
	}//end draft()

	/**
	 * A skill entity owned by the given uid.
	 *
	 * @param string $owner The owner uid.
	 *
	 * @return ObjectEntity
	 */
	private function skill(string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('skill-1');
		$entity->setOwner($owner);
		$entity->setObject(['name' => 'tender-summary']);
		return $entity;
	}//end skill()

	/**
	 * An invisible draft (or skill outside tenant visibility) is 404 BEFORE the
	 * action check — the action matrix is never consulted, so a 403 can never
	 * confirm existence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function testInvisibleDraftIs404BeforeTheActionCheck(): void {
		$controller = $this->controller(session: $this->session('mallory'));
		$this->consolidation->method('getDraft')->willReturn($this->draft());
		// The SKILL is outside the caller's visibility → the draft reads as absent.
		$this->skillService->method('getSkill')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('requireAction');

		$response = $controller->accept('draft-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testInvisibleDraftIs404BeforeTheActionCheck()

	/**
	 * A caller without `skill.review-draft` gets 403 with the draft, its Approval
	 * and the skill unchanged (no decision call is ever made).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function testUnauthorizedReviewerIs403WithNothingChanged(): void {
		$controller = $this->controller(session: $this->session('mallory'));
		$this->consolidation->method('getDraft')->willReturn($this->draft());
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$this->approvalService->expects($this->never())->method('approve');
		$this->approvalService->expects($this->never())->method('deny');
		$this->consolidation->expects($this->never())->method('markRejectedLearningRefs');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->accept('draft-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->reject('draft-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->content('draft-1')->getStatus());

	}//end testUnauthorizedReviewerIs403WithNothingChanged()

	/**
	 * Accept decides by transitioning the linked Approval — and returns the new
	 * version id the apply created.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function testAcceptTransitionsTheLinkedApprovalAndReturnsTheVersionId(): void {
		$controller = $this->controller(session: $this->session('reviewer'));
		$this->consolidation->method('getDraft')->willReturn($this->draft());
		$this->skillService->method('getSkill')->willReturn($this->skill());

		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['status' => 'pending', 'sourceType' => 'skill-draft']);
		$this->approvalService->method('loadApproval')->with('appr-1')->willReturn($approval);
		$this->approvalService->expects($this->once())
			->method('approve')
			->willReturn(['status' => 'approved', 'ran' => true]);
		$this->versionService->method('currentVersionId')->willReturn('v-new');

		$response = $controller->accept('draft-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('v-new', $response->getData()['versionId']);

	}//end testAcceptTransitionsTheLinkedApprovalAndReturnsTheVersionId()

	/**
	 * A refused transition (edited draft awaiting re-qualification) is a 409 —
	 * never a silent success.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function testAcceptWhileAwaitingRequalificationIs409(): void {
		$controller = $this->controller(session: $this->session('reviewer'));
		$this->consolidation->method('getDraft')->willReturn($this->draft());
		$this->skillService->method('getSkill')->willReturn($this->skill());

		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['status' => 'pending']);
		$this->approvalService->method('loadApproval')->willReturn($approval);
		$this->approvalService->method('approve')->willReturn(['status' => 'pending', 'ran' => false]);

		$response = $controller->accept('draft-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('not_approvable', $response->getData()['error']);

	}//end testAcceptWhileAwaitingRequalificationIs409()

	/**
	 * Reject marks the curator's bad-learnings refs on the DRAFT first, then
	 * denies the linked Approval (the same reconcile path an inbox denial runs).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function testRejectMarksRefsThenDeniesTheApproval(): void {
		$controller = $this->controller(session: $this->session('reviewer'));
		$draft = $this->draft();
		$this->consolidation->method('getDraft')->willReturn($draft);
		$this->skillService->method('getSkill')->willReturn($this->skill());
		$this->request->method('getParam')->willReturnMap(
			[
				['note', '', 'off track'],
				['rejectedLearningRefs', [], ['2026-07-20-aaaaaaaa']],
			]
		);

		$this->consolidation->expects($this->once())
			->method('markRejectedLearningRefs')
			->willReturn($draft);

		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['status' => 'pending']);
		$this->approvalService->method('loadApproval')->willReturn($approval);
		$this->approvalService->expects($this->once())->method('deny');

		$response = $controller->reject('draft-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('rejected', $response->getData()['status']);

	}//end testRejectMarksRefsThenDeniesTheApproval()

	/**
	 * Manual propose is OWNER-guarded: a visible-but-not-owned skill is 404, and a
	 * gate block maps to a structured 429.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-and-its-evals-respect-the-kill-switch-and-budget-hard-caps
	 */
	public function testProposeIsOwnerGuardedAndMapsGateBlocksTo429(): void {
		$controller = $this->controller(session: $this->session('mallory'));
		$this->skillService->method('getSkill')->willReturn($this->skill(owner: 'alice'));
		$this->consolidation->expects($this->never())->method('proposeForSkill');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->propose('skill-1')->getStatus());

		$controller = $this->controller(session: $this->session('alice'));
		$this->skillService->method('getSkill')->willReturn($this->skill(owner: 'alice'));
		$this->consolidation->method('proposeForSkill')->willReturn(
			[
				'created' => false,
				'status' => 'blocked_killswitch',
				'draft' => null,
			]
		);

		$response = $controller->propose('skill-1');
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame('blocked_killswitch', $response->getData()['error']);

	}//end testProposeIsOwnerGuardedAndMapsGateBlocksTo429()

	/**
	 * Manual propose returns the EXISTING open draft as a pointer (200), never an
	 * error and never a duplicate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 */
	public function testProposeReturnsTheOpenDraftAsAPointer(): void {
		$controller = $this->controller(session: $this->session('alice'));
		$this->skillService->method('getSkill')->willReturn($this->skill(owner: 'alice'));
		$this->consolidation->method('proposeForSkill')->willReturn(
			[
				'created' => false,
				'status' => 'open_draft_exists',
				'draft' => $this->draft(),
			]
		);

		$response = $controller->propose('skill-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('open_draft_exists', $response->getData()['status']);
		$this->assertSame('draft-1', $response->getData()['draft']['uuid']);

	}//end testProposeReturnsTheOpenDraftAsAPointer()
}//end class
