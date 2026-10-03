<?php

/**
 * Hermiq ChatAttachmentController.
 *
 * `POST /api/chat/attachments`: the route the AI companion's attach control
 * already calls. One multipart file in the field `file`, kept in the caller's
 * own Files; the answer is `{ path, name, fileId, mimeType, size }`, a refusal
 * is a 400 with `{ error }` in the caller's language
 * (chat-attachments-and-images, D1).
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\AttachmentStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chat attachment uploads.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */
class ChatAttachmentController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param IUserSession    $userSession The caller.
	 * @param AttachmentStore $store       Keeps the file in the caller's Files.
	 * @param IL10N           $l10n        Errors in the caller's language.
	 * @param LoggerInterface $logger      A write failure, never the file.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AttachmentStore $store,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Keep one uploaded file in the caller's Files.
	 *
	 * @return JSONResponse 200 with the file, 400 with an error, 401 signed out, 500 when it could not be saved.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function upload(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => $this->l10n->t('Not signed in.')], statusCode: 401);
		}

		$upload = $this->request->getUploadedFile('file');
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new JSONResponse(data: ['error' => $this->l10n->t('No file was uploaded.')], statusCode: 400);
		}

		try {
			return new JSONResponse(data: $this->store->store(uid: $user->getUID(), upload: $upload));
		} catch (AttachmentRefusedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ChatAttachmentController] An attachment could not be saved',
				context: ['exception' => $e]
			);
			return new JSONResponse(data: ['error' => $this->l10n->t('The file could not be saved.')], statusCode: 500);
		}
	}//end upload()
}//end class
