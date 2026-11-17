<?php

/**
 * Hermiq ApprovalController.
 *
 * The reviewer-facing surface for the human-approval gate (EU AI Act Art. 14):
 * list-pending-for-me, approve, deny. Approving executes the gated run (via
 * ApprovalService, which reuses ScheduleService::runNow with the gate bypassed);
 * denying records the decision and never runs.
 *
 * Security (ADR-005 Rule 3 / OWASP A01 IDOR): `@NoAdminRequired` means any
 * authenticated user can call it, so the decision endpoints MUST NOT trust the
 * {approvalId} path blindly. Because the Approval is owned by the schedule owner but
 * decided by a DIFFERENT party (the reviewer), the object is loaded RBAC-off and the
 * caller is authorised via ApprovalInbox::isReviewer() — the resolved reviewer user,
 * a member of the reviewer group, or an instance admin. The schedule owner is refused
 * (404) unless owner == reviewer (separation of duties).
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
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ApprovalServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ComputerUseServiceInterface;
use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Service\Approval\ApprovalInbox;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reviewer/admin-guarded approve/deny + pending-inbox endpoints.
 *
 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#4-approve-deny-endpoints-reviewer-admin-guarded
 */
class ApprovalController extends Controller {

	/**
	 * The approve scope that widens a computer action to its machine for its run.
	 *
	 * @var string
	 */
	public const SCOPE_MACHINE_RUN = 'machine-run';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ApprovalServiceInterface $approvalService The approval gate write-path.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ApprovalInbox $approvalInbox The reviewer inbox + reviewer guard.
	 * @param ComputerUseServiceInterface|null $computerUse Takes the live screenshot a reviewer sees on a computer action.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	public function __construct(
		IRequest $request,
		private readonly ApprovalServiceInterface $approvalService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ApprovalInbox $approvalInbox,
		private readonly ?ComputerUseServiceInterface $computerUse = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the pending approvals routed to the current user as reviewer.
	 *
	 * @return JSONResponse The pending-approval records, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$records = $this->approvalInbox->listPendingForReviewer(uid: $user->getUID());
			return new JSONResponse(['results' => $records, 'total' => count($records)]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq approval inbox read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load approvals'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()

	/**
	 * Approve a pending approval and execute the gated run.
	 *
	 * A computer action may be approved with `scope: machine-run`: the reviewer
	 * then also approves the actions on that machine for that run for fifteen
	 * minutes (tools-browser-and-computer-use REQ-COMPUTER-003), answered as
	 * `preauthorisedUntil`. Any other scope approves this one action.
	 *
	 * @param string $approvalId The Approval object UUID.
	 * @param string $scope      `machine-run` to widen a computer action; empty for one action.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-4-2
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function approve(string $approvalId, string $scope = ''): JSONResponse {
		$guard = $this->ensureDecidableApproval(approvalId: $approvalId);
		if (($guard instanceof ObjectEntity) === false) {
			return $guard;
		}

		$response = $this->approved(approval: $guard, approvalId: $approvalId);
		if ($scope !== self::SCOPE_MACHINE_RUN || $response->getStatus() !== Http::STATUS_OK) {
			return $response;
		}

		return $this->withMachineRun(response: $response, approval: $guard);
	}//end approve()

	/**
	 * The machine's screen now, for a reviewer deciding a pending computer
	 * action, with the action's point; shown and never stored.
	 *
	 * Only the approval's reviewer gets it, only while it is pending, and only
	 * when computer use is enabled: the screenshot goes through the same gate
	 * an agent's screenshot does (tools-browser-and-computer-use REQ-COMPUTER-003).
	 *
	 * @param string $approvalId The Approval object UUID.
	 *
	 * @return JSONResponse `{image, mimeType, width, height, x, y}`, 404/409 as the guard answers, 409 when the machine cannot be read.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public function screenshot(string $approvalId): JSONResponse {
		$guard = $this->ensureDecidableApproval(approvalId: $approvalId);
		if (($guard instanceof ObjectEntity) === false) {
			return $guard;
		}

		$data = $guard->getObject();
		$args = (array)($data['toolArguments'] ?? []);
		if ($this->computerUse === null || in_array((string)($data['toolId'] ?? ''), ComputerToolDescriptors::ACTION_IDS, true) === false) {
			return new JSONResponse(['error' => 'No screen for this approval'], Http::STATUS_NOT_FOUND);
		}

		$shot = $this->computerUse->invoke(
			uid: (string)$this->userSession->getUser()?->getUID(),
			toolId: ComputerToolDescriptors::SCREENSHOT,
			arguments: ['target' => (string)($args['target'] ?? '')]
		);
		if (isset($shot['error']) === true) {
			return new JSONResponse(['error' => (string)($shot['error']['message'] ?? 'The machine could not be read')], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(
			[
				'image' => (string)($shot['image'] ?? ''),
				'mimeType' => (string)($shot['mimeType'] ?? 'image/png'),
				'width' => (int)($shot['width'] ?? 0),
				'height' => (int)($shot['height'] ?? 0),
				'x' => self::coordinate(value: ($args['x'] ?? null)),
				'y' => self::coordinate(value: ($args['y'] ?? null)),
			]
		);
	}//end screenshot()

	/**
	 * A stored coordinate as a number, or null when the action has none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function coordinate(mixed $value): ?int {
		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (int)$value;
		}

		return null;
	}//end coordinate()

	/**
	 * Add the machine-and-run pre-authorisation to an approved computer action.
	 *
	 * @param JSONResponse $response The approval's answer.
	 * @param ObjectEntity $approval The approval, as loaded before the decision.
	 *
	 * @return JSONResponse The answer with `preauthorisedUntil` ('' when this approval cannot carry one).
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	private function withMachineRun(JSONResponse $response, ObjectEntity $approval): JSONResponse {
		$data = $response->getData();
		$data['preauthorisedUntil'] = '';
		if (($data['status'] ?? '') !== 'approved') {
			return new JSONResponse($data);
		}

		$decided = $this->approvalService->loadApproval(uuid: (string)$approval->getUuid()) ?? $approval;
		try {
			$widened = $this->approvalService->preauthoriseComputerRun(
				approval: $decided,
				deciderUid: (string)$this->userSession->getUser()?->getUID(),
				now: new DateTimeImmutable()
			);
			$data['preauthorisedUntil'] = (string)($widened['expiresAt'] ?? '');
		} catch (Throwable $e) {
			$this->logger->error('Hermiq machine-run pre-authorisation failed: ' . $e->getMessage(), ['exception' => $e]);
		}

		return new JSONResponse($data);
	}//end withMachineRun()

	/**
	 * Decide a pending approval from one route: `decision` is `granted`
	 * (approve) or `refused` (deny), so a chat posts to the approval's
	 * `resolveUrl` as it does for a tool-access request.
	 *
	 * The reviewer guard runs first, so a non-reviewer gets the same 404 for
	 * any decision and cannot learn whether the approval exists.
	 *
	 * @param string $approvalId The Approval object UUID.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function resolve(string $approvalId): JSONResponse {
		$guard = $this->ensureDecidableApproval(approvalId: $approvalId);
		if (($guard instanceof ObjectEntity) === false) {
			return $guard;
		}

		$decision = $this->request->getParam('decision');
		if ($decision === 'granted') {
			return $this->approved(approval: $guard, approvalId: $approvalId);
		}

		if ($decision === 'refused') {
			return $this->denied(approval: $guard, approvalId: $approvalId);
		}

		return new JSONResponse(['error' => "decision must be 'granted' or 'refused'"], Http::STATUS_BAD_REQUEST);
	}//end resolve()

	/**
	 * Approve a guarded approval and answer with the outcome.
	 *
	 * @param ObjectEntity $approval   The approval the caller may decide.
	 * @param string       $approvalId The Approval object UUID as requested.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 */
	private function approved(ObjectEntity $approval, string $approvalId): JSONResponse {
		try {
			$result = $this->approvalService->approve(
				approval: $approval,
				deciderUid: (string)$this->userSession->getUser()?->getUID()
			);
			return new JSONResponse(
				[
					'approvalId' => $approvalId,
					'status' => $result['status'],
					'ran' => $result['ran'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq approval approve failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Approve failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end approved()

	/**
	 * Deny a pending approval — the gated run never executes.
	 *
	 * @param string $approvalId The Approval object UUID.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-4-3
	 */
	public function deny(string $approvalId): JSONResponse {
		$guard = $this->ensureDecidableApproval(approvalId: $approvalId);
		if (($guard instanceof ObjectEntity) === false) {
			return $guard;
		}

		return $this->denied(approval: $guard, approvalId: $approvalId);
	}//end deny()

	/**
	 * Deny a guarded approval, with the request's optional `reason`.
	 *
	 * @param ObjectEntity $approval   The approval the caller may decide.
	 * @param string       $approvalId The Approval object UUID as requested.
	 *
	 * @return JSONResponse The decision outcome, or an error status.
	 */
	private function denied(ObjectEntity $approval, string $approvalId): JSONResponse {
		$reason = $this->request->getParam('reason');
		$reasonStr = null;
		if ($reason !== null) {
			$reasonStr = (string)$reason;
		}

		try {
			$this->approvalService->deny(
				approval: $approval,
				deciderUid: (string)$this->userSession->getUser()?->getUID(),
				reason: $reasonStr
			);
			return new JSONResponse(['approvalId' => $approvalId, 'status' => 'denied']);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq approval deny failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Deny failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end denied()

	/**
	 * Load a pending approval the caller is authorised to decide (shared IDOR guard).
	 *
	 * Returns the ObjectEntity when the caller is the resolved reviewer (or reviewer
	 * group member or instance admin) and the approval is still pending; otherwise
	 * returns the JSONResponse to send: 401 (unauthenticated), 404 (absent, or
	 * non-reviewer — no cross-tenant leak), or 409 (already decided).
	 *
	 * @param string $approvalId The Approval object UUID.
	 *
	 * @return ObjectEntity|JSONResponse The guarded approval, or an error response.
	 *
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-enforcement/tasks.md#task-4-1
	 */
	private function ensureDecidableApproval(string $approvalId): ObjectEntity|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$approval = $this->approvalService->loadApproval(uuid: $approvalId);
		if ($approval === null) {
			return new JSONResponse(['error' => 'Approval not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->approvalInbox->isReviewer(approval: $approval, uid: $user->getUID()) === false) {
			// 404 (not 403) so a non-reviewer cannot even confirm the approval exists.
			return new JSONResponse(['error' => 'Approval not found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($approval->getObject()['status'] ?? '') !== 'pending') {
			return new JSONResponse(['error' => 'Approval already decided'], Http::STATUS_CONFLICT);
		}

		return $approval;
	}//end ensureDecidableApproval()
}//end class
