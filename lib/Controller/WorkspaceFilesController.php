<?php

/**
 * Hermiq WorkspaceFilesController.
 *
 * The chat's Files panel over a conversation's session workspace
 * (tools-agent-workspace-and-installed-tools D3): list, download, and save a
 * file to the person's own Files. The workspace key is composed here from the
 * session's agent, the session and the SIGNED-IN user, never taken from the
 * request, so a caller only ever reaches their own workspace of a session they
 * take part in; anyone else is answered 404.
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
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\Hermiq\Service\Workspace\SessionWorkspaceKey;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\Hermiq\Service\Workspace\WorkspaceFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * List, download and save the files of the caller's own session workspace.
 *
 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
 */
class WorkspaceFilesController extends Controller {

	/**
	 * OpenRegister register slug that holds Hermiq sessions.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for sessions.
	 *
	 * @var string
	 */
	private const SESSION_SCHEMA = 'agentsession';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param IUserSession $userSession Resolves the signed-in user.
	 * @param ObjectService $objectService Reads the session.
	 * @param WorkspaceFiles $files The workspace files.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ConversationParticipation $participation Who takes part in a session.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly WorkspaceFiles $files,
		private readonly LoggerInterface $logger,
		private readonly ConversationParticipation $participation = new ConversationParticipation(),
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The files of the caller's workspace in this session.
	 *
	 * @param string $sessionId The session UUID.
	 *
	 * @return JSONResponse `{files: [{path, size, modified}], truncated}`, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function index(string $sessionId): JSONResponse {
		$key = $this->workspaceKey(sessionId: $sessionId);
		if ($key === null) {
			return $this->notFound();
		}

		return new JSONResponse($this->files->list(workspaceKey: $key));
	}//end index()

	/**
	 * Download one file of the caller's workspace in this session.
	 *
	 * @param string $sessionId The session UUID.
	 * @param string $path The file's path inside the workspace.
	 *
	 * @return JSONResponse|DataDownloadResponse The file, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function download(string $sessionId, string $path=''): JSONResponse|DataDownloadResponse {
		$key = $this->workspaceKey(sessionId: $sessionId);
		if ($key === null) {
			return $this->notFound();
		}

		try {
			$absolute = $this->files->open(workspaceKey: $key, path: $path);
		} catch (WorkspaceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse((string)file_get_contents($absolute), basename($absolute), 'application/octet-stream');
	}//end download()

	/**
	 * Save one file of the caller's workspace to their Files under "Hermiq",
	 * tagged as agent-authored; a failed tag fails the save.
	 *
	 * @param string $sessionId The session UUID.
	 * @param string $path The file's path inside the workspace.
	 *
	 * @return JSONResponse `{fileId, name, path}` with 201, or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	public function save(string $sessionId, string $path=''): JSONResponse {
		$user = $this->userSession->getUser();
		$key = $this->workspaceKey(sessionId: $sessionId);
		if ($user === null || $key === null) {
			return $this->notFound();
		}

		try {
			$saved = $this->files->saveToFiles(workspaceKey: $key, path: $path, userId: $user->getUID());
		} catch (WorkspaceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ArtefactMarkingFailedException $e) {
			return new JSONResponse(
				['error' => 'The file was not saved: it could not be marked as written by an agent.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($saved, Http::STATUS_CREATED);
	}//end save()

	/**
	 * The caller's workspace key in this session, or null when there is no
	 * signed-in user, the session does not exist, or the caller takes no part in it.
	 *
	 * @param string $sessionId The session UUID.
	 *
	 * @return string|null The key.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	private function workspaceKey(string $sessionId): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$session = $this->findSession(sessionId: $sessionId);
		if ($session === null) {
			return null;
		}

		$data = $session->getObject();
		if ($this->participation->mayTakeTurn(conversationData: $data, userId: $user->getUID()) === false) {
			return null;
		}

		$agentId = ($data['agentId'] ?? '');
		if (is_string($agentId) === false) {
			return null;
		}

		return SessionWorkspaceKey::compose(agentId: $agentId, conversationId: (string)$session->getUuid(), userId: $user->getUID());
	}//end workspaceKey()

	/**
	 * The session object, or null when it cannot be loaded.
	 *
	 * @param string $sessionId The session UUID.
	 *
	 * @return ObjectEntity|null The session.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	private function findSession(string $sessionId): ?ObjectEntity {
		try {
			$session = $this->objectService->find(id: $sessionId, register: self::REGISTER_SLUG, schema: self::SESSION_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq workspace files: session lookup failed for ' . $sessionId . ': ' . $e->getMessage());
			return null;
		}

		if (($session instanceof ObjectEntity) === false) {
			return null;
		}

		return $session;
	}//end findSession()

	/**
	 * The one not-found answer, so a non-participant learns nothing.
	 *
	 * @return JSONResponse 404.
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-workspace/spec.md#requirement-the-user-sees-the-workspace-and-can-keep-a-file-req-wksp-003
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'Session not found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
