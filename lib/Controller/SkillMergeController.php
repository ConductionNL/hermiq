<?php

/**
 * Hermiq SkillMergeController.
 *
 * The merge proposals the skill curator writes (skills-curator-merges-duplicates):
 * list the pending ones, approve one (the duplicate is archived and its agents move to
 * the survivor) or reject one (both skills stay; the pair is not proposed again).
 *
 * Merging is a curator act, not an owner right: every route is gated by
 * `ActionAuthService::requireAction('skill.merge-duplicates')` (ADR-023, admin by
 * default) before anything is read or written, so a caller without the action learns
 * nothing about the proposals. The routes are standard-CSRF; `@NoAdminRequired` opens
 * them to authenticated users and the action check does the authorization.
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
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SkillMergeServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * List, approve and reject the curator's skill merge proposals.
 *
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
 */
class SkillMergeController extends Controller {

	/**
	 * The ADR-023 action that may decide merge proposals.
	 *
	 * @var string
	 */
	public const ACTION = 'skill.merge-duplicates';

	/**
	 * The HTTP status of each refusal the merge service names.
	 *
	 * @var array<string, int>
	 */
	private const ERROR_STATUS = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'no_proposal' => Http::STATUS_CONFLICT,
		'survivor_gone' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request      The request.
	 * @param SkillMergeServiceInterface $mergeService Lists, approves and rejects merge proposals.
	 * @param ActionAuthServiceInterface $actionAuth   ADR-023 action authorization.
	 * @param IUserSession               $userSession  The acting user.
	 * @param LoggerInterface            $logger       Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SkillMergeServiceInterface $mergeService,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The pending merge proposals.
	 *
	 * @return JSONResponse `{proposals: [...]}`, or 401/403/500.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
	 */
	public function index(): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return new JSONResponse(['proposals' => $this->mergeService->pendingMerges()]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill merge proposals could not be read: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not read the merge proposals'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Approve the merge proposed on a duplicate skill.
	 *
	 * @param string $id The duplicate skill's uuid.
	 *
	 * @return JSONResponse `{merged: true, survivor, agentsMoved}`, or 401/403/404/409/500.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-an-approved-merge-archives-the-duplicate-and-moves-its-agents
	 */
	public function approve(string $id): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->answer(result: $this->mergeService->approveMerge(duplicateId: $id), done: 'merged');
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill merge failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not merge the skills'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end approve()

	/**
	 * Reject the merge proposed on a duplicate skill: both skills stay.
	 *
	 * @param string $id The duplicate skill's uuid.
	 *
	 * @return JSONResponse `{rejected: true}`, or 401/403/404/409/500.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-a-rejected-merge-changes-nothing
	 */
	public function reject(string $id): JSONResponse {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->answer(result: $this->mergeService->rejectMerge(duplicateId: $id), done: 'rejected');
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill merge rejection failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not reject the merge'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end reject()

	/**
	 * The 401 or 403 when the caller may not decide merges, or null when they may.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function refusal(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refusal()

	/**
	 * The service's result as a response: 200 when done, else the status of its error.
	 *
	 * @param array<string, mixed> $result The service's result.
	 * @param string               $done   The result key that is true when the decision was made.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(array $result, string $done): JSONResponse {
		if (($result[$done] ?? false) === true) {
			return new JSONResponse($result);
		}

		$error = (string)($result['error'] ?? '');

		return new JSONResponse(['error' => $error], (self::ERROR_STATUS[$error] ?? Http::STATUS_INTERNAL_SERVER_ERROR));
	}//end answer()
}//end class
