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
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the advisory Approvals about one subject to whoever may open it.
 *
 * GET /api/ai-oversight/subject?originApp=&subjectId=[&subjectType=&limit=&offset=]
 * GET /api/ai-oversight/subject/export?originApp=&subjectId=[&subjectType=] (CSV, oversight role)
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class AiOversightController extends Controller {

	/**
	 * Rows read per page for a download.
	 */
	private const EXPORT_PAGE = 200;

	/**
	 * The most rows one download holds.
	 */
	private const EXPORT_MAX_ROWS = 5000;


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

	/**
	 * Download the advisory Approvals about one subject as CSV.
	 *
	 * Only a holder of the oversight role (or an admin) who may also open the
	 * subject downloads; both are decided before anything is read. Rows are
	 * newest first, at most EXPORT_MAX_ROWS.
	 *
	 * @param string $originApp   The origin app id.
	 * @param string $subjectId   The subject's id in that app.
	 * @param string $subjectType The subject type, optional.
	 *
	 * @return JSONResponse|DataDownloadResponse The CSV, or an error.
	 *
	 * @spec openspec/changes/ai-oversight-subject-export/specs/ai-oversight/spec.md
	 */
	#[NoAdminRequired]
	public function subjectExport(
		string $originApp='',
		string $subjectId='',
		string $subjectType=''
	): JSONResponse|DataDownloadResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($originApp === '' || $subjectId === '') {
			return new JSONResponse(['error' => 'originApp and subjectId are required'], Http::STATUS_BAD_REQUEST);
		}

		$uid = $user->getUID();
		if ($this->access->hasOversightRole(userId: $uid) === false
			|| $this->access->maySeeSubject(userId: $uid, subjectId: $subjectId) === false
		) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$rows = [];
			$read = 0;
			do {
				$page = $this->oversight->listForSubject(
					originApp: $originApp,
					subjectId: $subjectId,
					subjectType: $subjectType,
					limit: self::EXPORT_PAGE,
					offset: $read
				);
				$rows  = array_merge($rows, $page['results']);
				$read += count($page['results']);
				$more  = $page['results'] !== [] && $read < min((int) $page['total'], self::EXPORT_MAX_ROWS);
			} while ($more === true);
		} catch (Throwable $e) {
			$this->logger->error('AI oversight: subject export failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The AI oversight records could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataDownloadResponse(
			data: $this->toCsv(rows: array_slice($rows, 0, self::EXPORT_MAX_ROWS)),
			filename: 'ai-oversight-'.$originApp.'-'.$subjectId.'.csv',
			contentType: 'text/csv'
		);

	}//end subjectExport()


	/**
	 * The records as CSV: moment, user, question or suggestion, outcome, model.
	 *
	 * A cell that a spreadsheet would read as a formula gets a leading quote.
	 *
	 * @param array<int, array<string, mixed>> $rows The records.
	 *
	 * @return string The CSV text.
	 */
	private function toCsv(array $rows): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, ['decidedAt', 'decidedBy', 'prompt', 'status', 'model'], ',', '"', '\\');
		foreach ($rows as $row) {
			$cells = [
				$row['decidedAt'] ?? '',
				$row['decidedBy'] ?? '',
				$row['prompt'] ?? '',
				$row['status'] ?? '',
				$row['advisoryContext']['model'] ?? '',
			];
			$cells = array_map(
				static function ($cell): string {
					$text = '';
					if (is_scalar($cell) === true) {
						$text = (string) $cell;
					}

					if (preg_match('/^[=+\-@\t\r]/', $text) === 1) {
						return "'".$text;
					}

					return $text;
				},
				$cells
			);
			fputcsv($handle, $cells, ',', '"', '\\');
		}

		rewind($handle);
		$csv = (string) stream_get_contents($handle);
		fclose($handle);

		return $csv;

	}//end toCsv()


}//end class
