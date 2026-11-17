<?php

/**
 * Hermiq KnowledgeBaseController.
 *
 * The knowledge base routes that are not plain OpenRegister object edits:
 * uploading documents into a knowledge base's folder in Files, and the
 * detail page's source state (indexing state and the only-you notice).
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\KnowledgeBaseServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SourceSharingInspectorInterface;
use OCA\Hermiq\Service\KnowledgeBase\KnowledgeBaseException;
use OCA\Hermiq\Service\KnowledgeBase\VectorFacadeProbe;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Upload into a knowledge base and read its sources' state, as its owner.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
class KnowledgeBaseController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                        $request     The request.
	 * @param KnowledgeBaseServiceInterface   $service     The owner's writes.
	 * @param SourceSharingInspectorInterface $inspector   Which sources only the owner can read.
	 * @param VectorFacadeProbe               $probe       Whether search is available and each file's indexing state.
	 * @param IUserSession                    $userSession The caller.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	public function __construct(
		IRequest $request,
		private readonly KnowledgeBaseServiceInterface $service,
		private readonly SourceSharingInspectorInterface $inspector,
		private readonly VectorFacadeProbe $probe,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Upload documents (field `files` or `file`) into the knowledge base's folder.
	 *
	 * @param string $id The knowledge base uuid.
	 *
	 * @return JSONResponse `{results: sources}`; 404 for anyone but the owner.
	 *
	 * @no-admin-idor-exempt the owner check runs in KnowledgeBaseServiceInterface::owned(), which answers 404 to anyone but the knowledge base's owner.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function upload(string $id): JSONResponse {
		return $this->respond(
			action: fn (string $caller): array => $this->service->upload(id: $id, callerUid: $caller, uploads: $this->uploads())
		);
	}//end upload()

	/**
	 * The detail page's view of the sources: indexing state and the only-you notice.
	 *
	 * @param string $id The knowledge base uuid.
	 *
	 * @return JSONResponse `{results: sources, searchAvailable: bool}`; 404 for anyone but the owner.
	 *
	 * @no-admin-idor-exempt the owner check runs in KnowledgeBaseServiceInterface::owned(), which answers 404 to anyone but the knowledge base's owner.
	 *
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-a-person-only-gets-answers-from-documents-they-may-read-req-kb-003
	 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-the-vector-facade-offers-a-file-scope-and-an-indexing-state-req-kb-005
	 */
	#[NoAdminRequired]
	public function sources(string $id): JSONResponse {
		$response = $this->respond(
			action: function (string $caller) use ($id): array {
				$entity  = $this->service->owned(id: $id, callerUid: $caller);
				$sources = $this->inspector->inspect(knowledgeBase: $entity->getObject(), ownerUid: $caller);
				$fileIds = array_values(array_filter(array_map(static fn (array $source): int => (int)($source['fileId'] ?? 0), $sources)));
				$states  = $this->probe->indexStatus(fileIds: $fileIds);
				foreach ($sources as $index => $source) {
					$sources[$index]['indexState'] = ($states[(int)($source['fileId'] ?? 0)] ?? null);
				}

				return $sources;
			}
		);
		if ($response->getStatus() === 200) {
			$response->setData(['results' => $response->getData()['results'], 'searchAvailable' => $this->probe->canSearchFiles()]);
		}

		return $response;
	}//end sources()

	/**
	 * The upload slots of the request, for `files[]` and for a single `file`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function uploads(): array {
		$slot = ($this->request->getUploadedFile('files') ?? $this->request->getUploadedFile('file'));
		if (is_array($slot) === false) {
			return [];
		}

		if (is_array($slot['name'] ?? null) === false) {
			return [$slot];
		}

		$uploads = [];
		foreach (array_keys($slot['name']) as $index) {
			$uploads[] = [
				'name' => ($slot['name'][$index] ?? ''),
				'tmp_name' => ($slot['tmp_name'][$index] ?? ''),
				'size' => ($slot['size'][$index] ?? 0),
				'error' => ($slot['error'][$index] ?? UPLOAD_ERR_NO_FILE),
			];
		}

		return $uploads;
	}//end uploads()

	/**
	 * Run an action as the signed-in caller and map refusals to their status.
	 *
	 * @param callable(string): array<int, array<string, mixed>> $action The action.
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $action): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		try {
			return new JSONResponse(data: ['results' => $action($user->getUID())], statusCode: 200);
		} catch (KnowledgeBaseException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getCode());
		}
	}//end respond()
}//end class
