<?php

/**
 * Hermiq SkillDraftController.
 *
 * The skill-self-improvement draft endpoints:
 *
 * - `propose()` — the manual "Propose improvement" trigger (owner-guarded; the
 *   kill-switch/budget/one-open-draft gates apply exactly as in the background job;
 *   an existing open draft is returned as a pointer, never an error).
 * - `index()` — the SkillDetail surface's draft list (owner-guarded).
 * - `content()` — edit-then-accept (SkillDetail-only surface): replaces the draft's
 *   proposed content, INVALIDATES scan+eval evidence and re-runs pre-qualification;
 *   the linked Approval is not approvable from any surface until it passes.
 * - `accept()` / `reject()` — decide by transitioning the draft's linked `Approval`
 *   object (the apply logic lives on the Approval transition, so an approval from
 *   the generic inbox runs the IDENTICAL path).
 *
 * IDOR guard (agent-evals pattern, ADR-005 Rule 3): visibility resolves FIRST and a
 * missing/invisible skill or draft is 404 — never a 403 that confirms existence; the
 * `skill.review-draft` action check (ADR-023) runs after, and a 403 leaves draft,
 * Approval and skill unchanged. Standard NC session + CSRF on every POST.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillConsolidationServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillVersionServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Propose / list / edit-content / accept / reject endpoints for skill drafts.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One collaborator per pipeline
 *   seam (skill visibility, draft pipeline, approval machine, action matrix,
 *   version lookup) — the controller only guards and delegates.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
 */
class SkillDraftController extends Controller {

	/**
	 * The ADR-023 action gating the review verb set (accept / edit-content /
	 * reject), surfaced in the action matrix beside `skill.approve-quarantined`.
	 *
	 * @var string
	 */
	private const REVIEW_ACTION = 'skill.review-draft';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param SkillServiceInterface $skillService Tenant-scoped skill visibility reads.
	 * @param SkillConsolidationServiceInterface $consolidation The draft pipeline owner.
	 * @param ApprovalServiceInterface $approvalService The ONE human-decision surface.
	 * @param SkillVersionServiceInterface $versionService Post-accept version id resolution.
	 * @param ActionAuthServiceInterface $actionAuth ADR-023 action authorization.
	 * @param SeedCustodyServiceInterface $seedCustody Owner-or-seed-custodian check.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		IRequest $request,
		private readonly SkillServiceInterface $skillService,
		private readonly SkillConsolidationServiceInterface $consolidation,
		private readonly ApprovalServiceInterface $approvalService,
		private readonly SkillVersionServiceInterface $versionService,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly SeedCustodyServiceInterface $seedCustody,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Manual trigger (c): propose a consolidation draft for an owned skill. The
	 * kill-switch, budget and one-open-draft gates apply exactly as in the job; an
	 * existing open draft returns 200 with a pointer to it.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The created (or already-open) draft, or a structured error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-consolidation-proposes-a-draft-version-and-never-edits-the-active-skill
	 */
	public function propose(string $id): JSONResponse {
		// 404 (never 403) so a non-owner cannot even confirm the skill exists.
		$skill = $this->skills()->ownedBy(user: $this->userSession->getUser(), skillId: $id);
		if ($skill instanceof JSONResponse) {
			return $skill;
		}

		try {
			$result = $this->consolidation->proposeForSkill(skill: $skill, trigger: 'manual');
		} catch (Throwable $e) {
			$this->logger->error('Hermiq manual propose failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Propose failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->proposalResponse(result: $result);

	}//end propose()

	/**
	 * The response to a proposal: 429 for a kill-switch or budget block (the gate is
	 * evidence, never bypassed), 422 when no draft came of it, the draft otherwise.
	 *
	 * @param array $result The proposal result.
	 *
	 * @return JSONResponse
	 */
	private function proposalResponse(array $result): JSONResponse {
		$status = (string)$result['status'];
		if ($status === 'blocked_killswitch' || $status === 'blocked_budget') {
			// Structured 429-style block: the gate is evidence, never bypassed.
			return new JSONResponse(['error' => $status], Http::STATUS_TOO_MANY_REQUESTS);
		}

		if ($result['draft'] === null) {
			return new JSONResponse(['error' => $status], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(
			[
				'status' => $status,
				'draft' => $this->shape(object: $result['draft']),
			]
		);

	}//end proposalResponse()

	/**
	 * The skill's drafts, newest first (the SkillDetail review surface's list).
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The drafts, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
	 */
	public function index(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$skill = $this->skills()->visible(skillId: $id);
		if ($skill === null) {
			return new JSONResponse(['error' => 'Skill not found'], Http::STATUS_NOT_FOUND);
		}

		$drafts = array_map(
			fn (ObjectEntity $draft): array => $this->shape(object: $draft),
			$this->consolidation->draftsForSkill(skillId: $id)
		);

		return new JSONResponse(['results' => $drafts]);
	}//end index()

	/**
	 * Edit-then-accept content update — available ONLY through this SkillDetail
	 * surface (editing needs the surface): replaces the draft's proposed content,
	 * records `editedBeforeAccept` + the editor, INVALIDATES the stored scan and
	 * eval evidence and re-runs pre-qualification. Until re-qualification passes,
	 * the linked Approval is not approvable from ANY surface.
	 *
	 * @param string $id The SkillDraft UUID.
	 *
	 * @return JSONResponse The re-qualified draft, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function content(string $id): JSONResponse {
		$guarded = $this->loadGuardedDraft(draftId: $id);
		if (($guarded instanceof JSONResponse) === true) {
			return $guarded;
		}

		[$draft, $user] = $guarded;

		// The service applies only a string frontmatter/body and a files list; any
		// other value (or none) keeps the draft's current content.
		$replacement = [
			'frontmatter' => $this->request->getParam('frontmatter'),
			'body' => $this->request->getParam('body'),
			'files' => $this->request->getParam('files'),
		];

		try {
			$updated = $this->consolidation->editDraftContent(
				draft: $draft,
				replacement: $replacement,
				editorUid: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq draft content edit failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Edit failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->shape(object: $updated));
	}//end content()

	/**
	 * Accept a draft — decides by transitioning the draft's linked `Approval` to
	 * `approved`: the apply step lives on THAT transition (identical to a generic-
	 * inbox approval), which writes the new skill version and stamps
	 * `lastAcceptedVersionAt`. The response carries the new version id.
	 *
	 * @param string $id The SkillDraft UUID.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function accept(string $id): JSONResponse {
		$guarded = $this->loadGuardedDraft(draftId: $id);
		if (($guarded instanceof JSONResponse) === true) {
			return $guarded;
		}

		[$draft, $user] = $guarded;

		$data = $draft->getObject();
		$approval = $this->approvalService->loadApproval(uuid: (string)($data['approvalId'] ?? ''));
		if ($approval === null) {
			return new JSONResponse(['error' => 'The draft has no pending approval'], Http::STATUS_CONFLICT);
		}

		$outcome = $this->approveAs(approval: $approval, deciderUid: $user->getUID());
		if ($outcome instanceof JSONResponse) {
			return $outcome;
		}

		if ((string)$outcome['status'] !== 'approved') {
			return $this->notApprovable(outcome: $outcome);
		}

		return $this->acceptedResponse(draftId: $id, skillId: (string)($data['skillId'] ?? ''));

	}//end accept()

	/**
	 * The accepted draft: the skill's new current version and the settled draft.
	 *
	 * @param string $draftId The SkillDraft UUID.
	 * @param string $skillId The Skill UUID.
	 *
	 * @return JSONResponse
	 */
	private function acceptedResponse(string $draftId, string $skillId): JSONResponse {
		return new JSONResponse(
			[
				'status' => 'accepted',
				'versionId' => (string)($this->versionService->currentVersionId(skillUuid: $skillId) ?? ''),
				'draft' => $this->shapeCurrent(draftId: $draftId),
			]
		);
	}//end acceptedResponse()

	/**
	 * Approve the draft's Approval as the curator, or the refusal: 404 when it vanished
	 * or fell out of the caller's scope, 500 on any other failure.
	 *
	 * @param ObjectEntity $approval The draft's pending Approval.
	 * @param string $deciderUid The deciding curator.
	 *
	 * @return array|JSONResponse The transition outcome, or the refusal.
	 */
	private function approveAs(ObjectEntity $approval, string $deciderUid): array|JSONResponse {
		try {
			return $this->approvalService->approve(approval: $approval, deciderUid: $deciderUid);
		} catch (DoesNotExistException $e) {
			// The approval/draft/skill vanished or fell out of the caller's scope
			// between the guard and the transition — 404, not a raw 500 on the
			// defended path (gate-49 / opencatalogi#86 lesson).
			return new JSONResponse(['error' => 'Draft approval not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq draft accept failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Accept failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end approveAs()

	/**
	 * The 409 for a transition that was refused (e.g. an edited draft awaiting
	 * re-qualification) or an Approval already decided — nothing was applied.
	 *
	 * @param array $outcome The transition outcome.
	 *
	 * @return JSONResponse
	 */
	private function notApprovable(array $outcome): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'not_approvable',
				'status' => (string)$outcome['status'],
			],
			Http::STATUS_CONFLICT
		);
	}//end notApprovable()

	/**
	 * Reject a draft — records any curator-marked bad learnings on the DRAFT
	 * (`rejectedLearningRefs`, never an edit to `learnings.md`), then decides by
	 * transitioning the linked `Approval` to `denied` (the same reconcile path an
	 * inbox denial runs). Marked entries never drive the skill's next proposal.
	 *
	 * @param string $id The SkillDraft UUID.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	public function reject(string $id): JSONResponse {
		$guarded = $this->loadGuardedDraft(draftId: $id);
		if (($guarded instanceof JSONResponse) === true) {
			return $guarded;
		}

		[$draft, $user] = $guarded;

		$rejection = new DraftRejection(
			draftId: $id,
			deciderUid: $user->getUID(),
			note: trim((string)$this->request->getParam('note', '')),
			refs: $this->rejectedLearningRefs()
		);

		$refusal = $this->settleRejection(draft: $draft, rejection: $rejection);
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(
			[
				'status' => 'rejected',
				'draft' => $this->shapeCurrent(draftId: $id),
			]
		);

	}//end reject()

	/**
	 * The learnings the curator marked as bad, or none when the param is not a list.
	 *
	 * @return array
	 */
	private function rejectedLearningRefs(): array {
		$refs = $this->request->getParam('rejectedLearningRefs', []);
		if (is_array($refs) === false) {
			return [];
		}

		return $refs;
	}//end rejectedLearningRefs()

	/**
	 * Settle the rejection, or the refusal: 404 when the approval or draft vanished or
	 * fell out of the caller's scope, 500 on any other failure.
	 *
	 * @param ObjectEntity $draft The guarded draft.
	 * @param DraftRejection $rejection The rejection.
	 *
	 * @return JSONResponse|null Null when settled.
	 */
	private function settleRejection(ObjectEntity $draft, DraftRejection $rejection): ?JSONResponse {
		try {
			$this->rejectDraft(draft: $draft, rejection: $rejection);
		} catch (DoesNotExistException $e) {
			// The approval/draft vanished or fell out of the caller's scope between
			// the guard and the transition — 404, not a raw 500 (gate-49).
			return new JSONResponse(['error' => 'Draft approval not found'], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq draft reject failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Reject failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return null;
	}//end settleRejection()

	/**
	 * Record the marked learnings on the draft, then deny its Approval, or settle the
	 * draft directly when it has none yet.
	 *
	 * @param ObjectEntity $draft The guarded draft.
	 * @param DraftRejection $rejection The rejection.
	 *
	 * @return void
	 */
	private function rejectDraft(ObjectEntity $draft, DraftRejection $rejection): void {
		if ($rejection->refs !== []) {
			$draft = $this->consolidation->markRejectedLearningRefs(draft: $draft, refs: $rejection->refs);
		}

		$approval = $this->approvalService->loadApproval(uuid: (string)($draft->getObject()['approvalId'] ?? ''));
		if ($approval !== null) {
			$this->approvalService->deny(approval: $approval, deciderUid: $rejection->deciderUid, reason: $rejection->note);
			return;
		}

		// No Approval yet (e.g. draft still proposed) — settle the draft
		// directly through the same reconcile path a denial runs.
		$this->consolidation->rejectDraftByDecision(
			draftId: $rejection->draftId,
			deciderUid: $rejection->deciderUid,
			note: $rejection->note,
			refs: $rejection->refs
		);
	}//end rejectDraft()

	/**
	 * Shared guard for the decision endpoints: resolve the draft, resolve its skill
	 * WITHIN the caller's visibility (404 — never 403 — on any miss, BEFORE the
	 * action check so existence never leaks), then require `skill.review-draft`
	 * (403 leaves everything unchanged).
	 *
	 * @param string $draftId The SkillDraft UUID.
	 *
	 * @return JSONResponse|array{0: ObjectEntity, 1: \OCP\IUser} The error response,
	 *                                                            or the [draft, user] pair.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	private function loadGuardedDraft(string $draftId): JSONResponse|array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$draft = $this->consolidation->getDraft(draftId: $draftId);
		if ($draft === null) {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		// Visibility through the SKILL (tenant-scoped read): an invisible skill makes
		// the draft invisible too — 404 BEFORE the action check.
		$skill = $this->skills()->visible(skillId: (string)($draft->getObject()['skillId'] ?? ''));
		if ($skill === null) {
			return new JSONResponse(['error' => 'Draft not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::REVIEW_ACTION);
		} catch (OCSForbiddenException $e) {
			// Draft, Approval and skill are untouched — nothing was written.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return [$draft, $user];
	}//end loadGuardedDraft()

	/**
	 * Shape a draft ObjectEntity into a UUID + payload response map.
	 *
	 * @param ObjectEntity $object The draft object.
	 *
	 * @return array<string, mixed> The response payload.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-the-skilldetail-review-surface-presents-diff-provenance-and-verdicts-with-three-actions
	 */
	private function shape(ObjectEntity $object): array {
		$data = $object->getObject();
		$data['uuid'] = (string)$object->getUuid();
		return $data;
	}//end shape()

	/**
	 * Re-read and shape a draft's CURRENT state (post-decision responses reflect the
	 * settled draft, not the pre-decision snapshot).
	 *
	 * @param string $draftId The SkillDraft UUID.
	 *
	 * @return array<string, mixed>|null The shaped draft, or null when gone.
	 *
	 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-draft-acceptance-runs-through-the-approval-state-machine-behind-action-authorization
	 */
	private function shapeCurrent(string $draftId): ?array {
		$draft = $this->consolidation->getDraft(draftId: $draftId);
		if ($draft === null) {
			return null;
		}

		return $this->shape(object: $draft);
	}//end shapeCurrent()

	/**
	 * The visibility and ownership guard over this controller's skill reads.
	 *
	 * @return SkillOwnership
	 */
	private function skills(): SkillOwnership {
		return new SkillOwnership(skillService: $this->skillService, seedCustody: $this->seedCustody);
	}//end skills()
}//end class
