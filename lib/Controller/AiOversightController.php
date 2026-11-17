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

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\AiOversightAccessInterface;
use OCA\Hermiq\Kernel\Contracts\AiOversightServiceInterface;
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
 * @spec openspec/specs/ai-oversight/spec.md
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
	 * @param AiOversightAccessInterface  $access      Decides who may read a subject's records.
	 * @param AiOversightServiceInterface $oversight   Reads the records.
	 * @param LoggerInterface    $logger      The logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AiOversightAccessInterface $access,
		private readonly AiOversightServiceInterface $oversight,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
	 * @spec openspec/specs/ai-oversight/spec.md
	 */
	#[NoAdminRequired]
	public function subject(
		string $originApp='',
		string $subjectId='',
		string $subjectType='',
		int $limit=25,
		int $offset=0
	): JSONResponse {
		$listing = new SubjectListing(
			originApp: $originApp,
			subjectId: $subjectId,
			subjectType: $subjectType,
			limit: $limit,
			offset: $offset
		);
		$uid = $this->readerOf(listing: $listing);
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		if ($this->access->maySeeSubject(userId: $uid, subjectId: $subjectId) === false) {
			return $this->notAuthorized();
		}

		return $this->pageResponse(listing: $listing);

	}//end subject()


	/**
	 * One page of the subject's records, or the 500 when it cannot be read.
	 *
	 * @param SubjectListing $listing The subject and the page, past the access check.
	 *
	 * @return JSONResponse
	 */
	private function pageResponse(SubjectListing $listing): JSONResponse {
		try {
			$page = $this->readPage(listing: $listing);
		} catch (Throwable $e) {
			$this->logger->error('AI oversight: subject listing failed', ['error' => $e->getMessage()]);
			return $this->unreadable();
		}

		return new JSONResponse($page);

	}//end pageResponse()

	/**
	 * Download the advisory Approvals about one subject as CSV.
	 *
	 * Only a holder of the oversight role (or an admin) downloads: the role is
	 * decided before anything is read, and nobody else gets a row. The subject
	 * check after it is the subject route's own; for a holder it passes, as the
	 * oversight role reads every advisory record. Rows are newest first, at most
	 * EXPORT_MAX_ROWS.
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
		$listing = new SubjectListing(originApp: $originApp, subjectId: $subjectId, subjectType: $subjectType);
		$uid = $this->readerOf(listing: $listing);
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		if ($this->access->hasOversightRole(userId: $uid) === false
			|| $this->access->maySeeSubject(userId: $uid, subjectId: $subjectId) === false
		) {
			return $this->notAuthorized();
		}

		return $this->exportResponse(listing: $listing);

	}//end subjectExport()


	/**
	 * The subject's records as a CSV download, or the 500 when they cannot be read.
	 *
	 * @param SubjectListing $listing The subject, past the access check.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 */
	private function exportResponse(SubjectListing $listing): JSONResponse|DataDownloadResponse {
		try {
			$rows = $this->exportRows(listing: $listing);
		} catch (Throwable $e) {
			$this->logger->error('AI oversight: subject export failed', ['error' => $e->getMessage()]);
			return $this->unreadable();
		}

		return new DataDownloadResponse(
			data: $this->toCsv(rows: array_slice($rows, 0, self::EXPORT_MAX_ROWS)),
			filename: 'ai-oversight-'.$listing->originApp.'-'.$listing->subjectId.'.csv',
			contentType: 'text/csv'
		);

	}//end exportResponse()


	/**
	 * The signed-in reader's uid, or the refusal: 401 with no user, 400 when the
	 * origin app or the subject id is missing.
	 *
	 * @param SubjectListing $listing The listing asked for.
	 *
	 * @return string|JSONResponse
	 */
	private function readerOf(SubjectListing $listing): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($listing->isComplete() === false) {
			return new JSONResponse(['error' => 'originApp and subjectId are required'], Http::STATUS_BAD_REQUEST);
		}

		return $user->getUID();

	}//end readerOf()


	/**
	 * The 403 for a reader who may not read the subject's records.
	 *
	 * @return JSONResponse
	 */
	private function notAuthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);

	}//end notAuthorized()


	/**
	 * The 500 for records that could not be read.
	 *
	 * @return JSONResponse
	 */
	private function unreadable(): JSONResponse {
		return new JSONResponse(['error' => 'The AI oversight records could not be read'], Http::STATUS_INTERNAL_SERVER_ERROR);

	}//end unreadable()


	/**
	 * One page of the subject's records.
	 *
	 * @param SubjectListing $listing The subject and the page.
	 *
	 * @return array{results: array, total: int|string}
	 */
	private function readPage(SubjectListing $listing): array {
		return $this->oversight->listForSubject(
			originApp: $listing->originApp,
			subjectId: $listing->subjectId,
			subjectType: $listing->subjectType,
			limit: $listing->limit,
			offset: $listing->offset
		);

	}//end readPage()


	/**
	 * Every record about the subject, read EXPORT_PAGE at a time, newest first, until
	 * the total or EXPORT_MAX_ROWS is reached or a page comes back empty.
	 *
	 * @param SubjectListing $listing The subject.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function exportRows(SubjectListing $listing): array {
		$rows = [];
		$read = 0;
		do {
			$page  = $this->readPage(listing: $listing->atPage(offset: $read, limit: self::EXPORT_PAGE));
			$rows  = array_merge($rows, $page['results']);
			$read += count($page['results']);
			$more  = $page['results'] !== [] && $read < min((int) $page['total'], self::EXPORT_MAX_ROWS);
		} while ($more === true);

		return $rows;

	}//end exportRows()


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
			fputcsv($handle, $this->csvCells(row: $row), ',', '"', '\\');
		}

		rewind($handle);
		$csv = (string) stream_get_contents($handle);
		fclose($handle);

		return $csv;

	}//end toCsv()


	/**
	 * One record's CSV cells, each made safe for a spreadsheet.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return array<int, string>
	 */
	private function csvCells(array $row): array {
		$cells = [
			$row['decidedAt'] ?? '',
			$row['decidedBy'] ?? '',
			$row['prompt'] ?? '',
			$row['status'] ?? '',
			$row['advisoryContext']['model'] ?? '',
		];

		return array_map(static fn ($cell): string => self::safeCell(cell: $cell), $cells);

	}//end csvCells()


	/**
	 * A cell as text: blank when it is not scalar, with a leading quote when a
	 * spreadsheet would read it as a formula.
	 *
	 * @param mixed $cell The cell.
	 *
	 * @return string
	 */
	private static function safeCell(mixed $cell): string {
		$text = '';
		if (is_scalar($cell) === true) {
			$text = (string) $cell;
		}

		if (preg_match('/^[=+\-@\t\r]/', $text) === 1) {
			return "'".$text;
		}

		return $text;

	}//end safeCell()


}//end class
