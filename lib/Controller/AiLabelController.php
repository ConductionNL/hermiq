<?php

/**
 * Hermiq AiLabelController.
 *
 * The AI label of what agents write (REQ-AILBL-002): mark an agent-authored file
 * as reviewed, and the counts for the AI oversight page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\AiLabelStatsService;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Marks a labelled file as reviewed, and reports the label counts.
 *
 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
 */
class AiLabelController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest            $request     The request.
	 * @param AgentArtefactMarker $marker      Reads and sets the two tags.
	 * @param AiLabelStatsService $stats       The label counts.
	 * @param IRootFolder         $rootFolder  Opens the caller's own Files.
	 * @param IUserSession        $userSession Resolves the caller.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function __construct(
		IRequest $request,
		private readonly AgentArtefactMarker $marker,
		private readonly AiLabelStatsService $stats,
		private readonly IRootFolder $rootFolder,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Mark an agent-authored file the caller can read as reviewed.
	 *
	 * A file the caller cannot open answers 404 before any tag is read, so the
	 * route cannot tell whether it exists; a readable file without the
	 * agent-authored tag answers 409.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return JSONResponse The file id and its reviewed state, or an error.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	#[NoAdminRequired]
	public function markReviewed(int $fileId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->readableFile(uid: $user->getUID(), fileId: $fileId) === false) {
			return new JSONResponse(data: ['error' => 'File not found.'], statusCode: 404);
		}

		if ($this->marker->isFileMarked(fileId: $fileId) === false) {
			return new JSONResponse(data: ['error' => 'This file is not marked as made with AI.'], statusCode: 409);
		}

		try {
			$this->marker->markReviewed(fileId: $fileId);
		} catch (ArtefactMarkingFailedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}

		return new JSONResponse(data: ['fileId' => $fileId, 'reviewed' => true], statusCode: 200);
	}//end markReviewed()

	/**
	 * The label counts for the caller, limited to the files they can read.
	 *
	 * @return JSONResponse files, reviewed, reviewedShare, tagId and truncated.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	#[NoAdminRequired]
	public function stats(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		return new JSONResponse(data: $this->stats->stats(uid: $user->getUID()), statusCode: 200);
	}//end stats()

	/**
	 * Whether the file is a file the caller can open and read in their own Files.
	 *
	 * @param string $uid    The caller.
	 * @param int    $fileId The file id.
	 *
	 * @return bool True when readable.
	 */
	private function readableFile(string $uid, int $fileId): bool {
		if ($fileId < 1) {
			return false;
		}

		try {
			$node = ($this->rootFolder->getUserFolder($uid)->getById($fileId)[0] ?? null);
		} catch (Throwable) {
			return false;
		}

		return $node instanceof File && $node->isReadable() === true;
	}//end readableFile()
}//end class
