<?php

/**
 * Hermiq ReportSimilarityController.
 *
 * The question an owning app asks per incoming report: which group does this belong
 * to. The answer carries the group, the score, the reasons and nothing else. In
 * particular it carries no instruction about acknowledgement: Awb 4:3a owes every
 * electronic request a confirmation of receipt, and two hundred people who wrote to
 * the gemeente are owed two hundred confirmations whatever a handler's screen shows.
 *
 * Taking a report back out of a group is an administrative act and is gated on the
 * action matrix (ADR-023); asking the question is not, because the owning app asks it
 * on every intake.
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
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#requirement-hermiq-must-answer-which-group-a-report-belongs-to-and-must-not-act-on-the-answer
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilarityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks and reads the grouping judgement, and undoes one membership.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md
 */
class ReportSimilarityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ReportSimilarityService $similarity The grouping judgement.
	 * @param ActionAuthService $actionAuth The ADR-023 action-authorization service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ReportSimilarityService $similarity,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Which group this report belongs to, or that it starts one.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-two-hundred-reports-of-one-power-cut-answer-as-one-group
	 */
	public function evaluate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$reportId = trim((string)$this->request->getParam('reportId', ''));
		$reportType = trim((string)$this->request->getParam('reportType', ''));
		$text = (string)$this->request->getParam('text', '');

		if ($reportId === '' || $reportType === '') {
			return new JSONResponse(
				['error' => 'A grouping question needs the report and its type.'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$answer = $this->similarity->evaluate(
				reportId: $reportId,
				reportType: $reportType,
				text: $text,
				deterministicKey: trim((string)$this->request->getParam('deterministicKey', ''))
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq report-similarity evaluation failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'The grouping question could not be answered'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($answer);
	}//end evaluate()

	/**
	 * One group with its reasons: the terms, the window, and per member the score
	 * and what decided it.
	 *
	 * @param string $groupId The group uuid.
	 *
	 * @return JSONResponse The group.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	public function show(string $groupId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$group = $this->similarity->group(groupId: $groupId);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq report-group read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not read the group'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($group === null) {
			return new JSONResponse(['error' => 'Group not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($group);
	}//end show()

	/**
	 * Take one report back out of a group (action-auth-gated). It stands alone
	 * again, unchanged, and the count moves.
	 *
	 * @param string $groupId The group uuid.
	 * @param string $reportId The report to remove.
	 *
	 * @return JSONResponse The group as it now stands.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-pulling-one-report-out-costs-nothing
	 */
	public function removeMember(string $groupId, string $reportId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'reportgroup.ungroup');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			$group = $this->similarity->removeMember(groupId: $groupId, reportId: $reportId);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq report-group ungroup failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not remove the report from the group'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($group === null) {
			return new JSONResponse(['error' => 'Group not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($group);
	}//end removeMember()
}//end class
