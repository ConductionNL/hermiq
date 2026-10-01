<?php

/**
 * Hermiq AgentGitController.
 *
 * "Keep in git" on the agent page: publish, push and pull an agent's package.
 * Repository coordinates come from the agent, never from these requests, except
 * the publish form that creates the repository.
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
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Agent\AgentGitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Publish, push and pull an agent to and from its GitHub repository.
 *
 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
 */
class AgentGitController extends Controller {

	/**
	 * The service refusals a client sees with their own status and reason.
	 *
	 * @var array<int, int>
	 */
	private const REFUSALS = [
		Http::STATUS_NOT_FOUND,
		Http::STATUS_FORBIDDEN,
		Http::STATUS_CONFLICT,
		Http::STATUS_UNPROCESSABLE_ENTITY,
		Http::STATUS_BAD_GATEWAY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param AgentGitService $git The git round trip.
	 * @param IUserSession $userSession The signed-in user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AgentGitService $git,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Publish the agent to a new repository.
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse 201 with the repository URL, or an error status.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		return $this->run(
			call: fn (string $uid): array => $this->git->publish(
				agentId: $id,
				uid: $uid,
				githubOwner: (string)$this->request->getParam('githubOwner', ''),
				repo: (string)$this->request->getParam('repo', ''),
				visibility: $this->visibility(),
				credentialId: (string)$this->request->getParam('credentialId', '')
			),
			status: Http::STATUS_CREATED
		);
	}//end publish()

	/**
	 * Push the agent to its stamped repository.
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse The repository URL and commit, or an error status.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
	 */
	#[NoAdminRequired]
	public function push(string $id): JSONResponse {
		return $this->run(
			call: fn (string $uid): array => $this->git->push(
				agentId: $id,
				uid: $uid,
				credentialId: (string)$this->request->getParam('credentialId', '')
			)
		);
	}//end push()

	/**
	 * What a pull would change.
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse The changed fields and the scan report, or an error status.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
	 */
	#[NoAdminRequired]
	public function pullPreview(string $id): JSONResponse {
		return $this->run(
			call: fn (string $uid): array => $this->git->pullPreview(
				agentId: $id,
				uid: $uid,
				credentialId: (string)$this->request->getParam('credentialId', '')
			)
		);
	}//end pullPreview()

	/**
	 * Write the pulled fields onto the agent.
	 *
	 * @param string $id The agent.
	 *
	 * @return JSONResponse The saved agent, or an error status.
	 *
	 * @spec openspec/changes/agents-export-import-and-git-sync/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
	 */
	#[NoAdminRequired]
	public function pullApply(string $id): JSONResponse {
		return $this->run(
			call: function (string $uid) use ($id): array {
				$agent = $this->git->pullApply(
					agentId: $id,
					uid: $uid,
					credentialId: (string)$this->request->getParam('credentialId', '')
				);
				return array_merge($agent->getObject(), ['uuid' => (string)$agent->getUuid()]);
			}
		);
	}//end pullApply()

	/**
	 * The requested repository visibility: `public` only when asked for, else `private`.
	 *
	 * @return string
	 */
	private function visibility(): string {
		if ($this->request->getParam('visibility', 'private') === 'public') {
			return 'public';
		}

		return 'private';
	}//end visibility()

	/**
	 * Run a service call for the signed-in user and map its outcome to a response.
	 *
	 * @param callable $call Takes the uid, returns the response data.
	 * @param int $status The success status.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $call, int $status = Http::STATUS_OK): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($call($user->getUID()), $status);
		} catch (RuntimeException $e) {
			if (in_array($e->getCode(), self::REFUSALS, true) === true) {
				return new JSONResponse(['error' => $e->getMessage()], $e->getCode());
			}

			$this->logger->error('[hermiq] Keep in git failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Keep in git failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end run()
}//end class
