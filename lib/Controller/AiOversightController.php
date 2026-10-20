<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Hermiq\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AiOversightAccess;
use OCA\Hermiq\Service\AiOversightService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the advisory Approvals about one subject to whoever may open it.
 *
 * GET /api/ai-oversight/subject?originApp=&subjectId=[&subjectType=&limit=&offset=]
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class AiOversightController extends Controller {


	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param IUserSession       $userSession The signed-in user.
	 * @param AiOversightAccess  $access      Decides who may read a subject's records.
	 * @param AiOversightService $oversight   Reads the records.
	 * @param LoggerInterface    $logger      The logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AiOversightAccess $access,
		private readonly AiOversightService $oversight,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()


	/**
	 * List the advisory Approvals about one subject.
	 *
	 * The access decision is taken before anything is read: a reader who may
	 * not open the subject, and holds neither the oversight role nor admin,
	 * gets 403 and no rows.
	 *
	 * @param string $originApp   The origin app id.
	 * @param string $subjectId   The subject's id in that app.
	 * @param string $subjectType The subject type, optional.
	 * @param int    $limit       Page size.
	 * @param int    $offset      Paging offset.
	 *
	 * @return JSONResponse `{results, total}`, or an error.
	 *
	 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
	 */
	#[NoAdminRequired]
	public function subject(
		string $originApp='',
		string $subjectId='',
		string $subjectType='',
		int $limit=25,
		int $offset=0
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($originApp === '' || $subjectId === '') {
			return new JSONResponse(['error' => 'originApp and subjectId are required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->access->maySeeSubject(userId: $user->getUID(), subjectId: $subjectId) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$page = $this->oversight->listForSubject(
				originApp: $originApp,
				subjectId: $subjectId,
				subjectType: $subjectType,
				limit: $limit,
				offset: $offset
			);
		} catch (Throwable $e) {
			$this->logger->error('AI oversight: subject listing failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The AI oversight records could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($page);

	}//end subject()


}//end class
