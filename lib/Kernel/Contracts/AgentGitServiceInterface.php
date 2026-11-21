<?php

/**
 * Contract of AgentGitService
 *
 * The public surface of AgentGitService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Contract of AgentGitService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgentGitServiceInterface {

	/**
	 * Publish the agent to a new repository and stamp it on the agent.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $githubOwner The GitHub user or organisation to create the repository under.
	 * @param string $repo The new repository's name.
	 * @param string $visibility `public` or `private`.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return array{repoUrl: string, commitSha: string}
	 *
	 * @throws RuntimeException 404, 403, 409 (already kept in git) or 502 (GitHub refused).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The publish form's fields, each a distinct input.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
	 */
	public function publish(string $agentId, string $uid, string $githubOwner, string $repo, string $visibility, string $credentialId): array;

	/**
	 * Write the pulled fields onto the agent, a new version of the same agent.
	 *
	 * The package is fetched and scanned again: what the owner confirmed is the
	 * repository's current content, and a commit may have landed since the preview.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return ObjectEntity The saved agent.
	 *
	 * @throws RuntimeException 404, 403, 409, 422 (dangerous scan) or 502.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
	 */
	public function pullApply(string $agentId, string $uid, string $credentialId): ObjectEntity;

	/**
	 * What a pull would change: the package's fields that differ from the agent.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return array{changes: array<int, array{field: string, from: mixed, to: mixed}>, scanReport: array<string, mixed>}
	 *
	 * @throws RuntimeException 404, 403, 409, 422 (dangerous scan) or 502.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-edits-made-in-git-come-back-into-the-same-agent-after-a-diff-req-agexp-005
	 */
	public function pullPreview(string $agentId, string $uid, string $credentialId): array;

	/**
	 * Push the agent's current package to its stamped repository.
	 *
	 * @param string $agentId The agent.
	 * @param string $uid The caller.
	 * @param string $credentialId The caller's GitHub credential in the broker.
	 *
	 * @return array{repoUrl: string, commitSha: string}
	 *
	 * @throws RuntimeException 404, 403, 409 (not kept in git) or 502.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-an-agent-owner-can-keep-an-agent-in-a-git-repository-req-agexp-004
	 */
	public function push(string $agentId, string $uid, string $credentialId): array;
}//end interface
