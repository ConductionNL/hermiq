<?php

/**
 * Hermiq WorkspacePusher.
 *
 * The one repo-effecting workspace tool: push a local branch of the run's
 * workspace to the same branch on the forge. It runs on the governed side, as
 * the run owner, with the owner's personal forge credential handed to git only
 * through the environment of this one process (an `http.extraHeader` set by
 * `GIT_CONFIG_*`), so the credential is on no command line, in no repository
 * configuration and in no result. A refusal from the forge becomes the stable
 * `push_rejected` code with a fixed sentence; git's own output is never
 * forwarded, because it can carry the remote URL.
 *
 * The approval gate and the argument-scoped grant have both been passed before
 * this class runs; it re-checks only what it alone can see: that the pinned
 * repository is the workspace's own.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-the-forge-credential-and-the-model-credential-are-separate-and-neither-reaches-the-model
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Pushes a workspace branch as the run owner.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-failed-push-does-not-leak-the-credential
 */
class WorkspacePusher {

	/**
	 * Budget for a push.
	 *
	 * @var int
	 */
	private const TIMEOUT_SECONDS = 60;

	/**
	 * Build the pusher.
	 *
	 * @param ForgeLocator            $forge       Slug to URL, after the egress policy.
	 * @param ForgeCredentialResolver $credentials The owner's forge credential.
	 * @param GitRunner               $git         The hardened git runner.
	 * @param ObjectService           $objects     Reads the run's agent.
	 */
	public function __construct(
		private readonly ForgeLocator $forge,
		private readonly ForgeCredentialResolver $credentials,
		private readonly GitRunner $git,
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * `push`: send a local branch to the same branch on the forge.
	 *
	 * @param array{runId: string, agentId: string, userId: string} $run        The verified run.
	 * @param string                                               $root       The workspace root.
	 * @param string                                               $repository The workspace's repository slug.
	 * @param string                                               $branch     A validated branch name.
	 * @param array<string, mixed>                                 $arguments  `repository`, `branch`.
	 *
	 * @return array{repository: string, branch: string, sha: string, credentialId: string}
	 *
	 * @throws WorkspaceException invalid_argument, owner_unresolvable, credential_scope_refused, egress_denied or push_rejected.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-the-forge-credential-is-absent-from-the-models-container
	 */
	public function push(array $run, string $root, string $repository, string $branch, array $arguments): array {
		if ((string)($arguments['repository'] ?? '') !== $repository) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::INVALID_ARGUMENT,
				message: 'Push the repository this run opened (' . $repository . '), and name it.'
			);
		}

		$sha = $this->git->run(arguments: ['rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch], workingDir: $root, timeoutSeconds: 20);
		if ($sha['exit'] !== 0) {
			throw new WorkspaceException(errorCode: WorkspaceException::INVALID_ARGUMENT, message: 'That branch does not exist in the workspace.');
		}

		$credential = $this->credentials->resolve(ownerUid: $run['userId'], agent: $this->agent(agentId: $run['agentId']));
		$url = $this->forge->cloneUrl(repository: $repository);

		$result = $this->git->run(
			arguments: ['push', '--porcelain', '--no-verify', $url, 'refs/heads/' . $branch . ':refs/heads/' . $branch],
			workingDir: $root,
			timeoutSeconds: self::TIMEOUT_SECONDS,
			extraEnv: [
				'GIT_CONFIG_COUNT' => '1',
				'GIT_CONFIG_KEY_0' => 'http.extraHeader',
				'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode('x-access-token:' . $credential['secret']),
			]
		);
		if ($result['exit'] !== 0) {
			throw new WorkspaceException(
				errorCode: WorkspaceException::PUSH_REJECTED,
				message: 'The forge refused the push. The branch may be protected, behind the remote, or outside what the credential may write.'
			);
		}

		return [
			'repository' => $repository,
			'branch' => $branch,
			'sha' => trim($sha['stdout']),
			'credentialId' => $credential['credentialId'],
		];
	}//end push()

	/**
	 * The run's agent, or null.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return ObjectEntity|null
	 */
	private function agent(string $agentId): ?ObjectEntity {
		try {
			$agent = $this->objects->find(id: $agentId, register: 'hermiq', schema: 'agent', _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return null;
		}

		if ($agent instanceof ObjectEntity) {
			return $agent;
		}

		return null;
	}//end agent()
}//end class
