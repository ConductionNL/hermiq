<?php

/**
 * Hermiq WorkspaceWrites.
 *
 * The write-shaped half of the governed workspace surface: write, delete and
 * patch files, create and switch branches, commit. It serves the same run the
 * read half serves (the scope the MCP endpoint set from the verified token) and
 * refuses a workspace id that is not the caller's own. Before any argument is
 * looked at, the run must hold an approved run-scoped pre-authorisation; an
 * unapproved run is refused before anything is written, staged or committed.
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;

/**
 * Dispatches the write-shaped workspace tools behind the approval gate.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-write-shaped-tools-route-through-the-approval-gate-with-a-run-scoped-pre-authorisation-form
 */
class WorkspaceWrites {

	/**
	 * Build the write half.
	 *
	 * @param WorkspaceRunScope        $scope      The verified run.
	 * @param WorkspaceProvider        $provider   Where the workspace lives.
	 * @param WorkspacePathGuard       $guard      Branch name validation.
	 * @param WorkspaceEditor          $editor     The in-workspace writes.
	 * @param WorkspaceWriteAuthoriser $authoriser The run-scoped approval gate.
	 */
	public function __construct(
		private readonly WorkspaceRunScope $scope,
		private readonly WorkspaceProvider $provider,
		private readonly WorkspacePathGuard $guard,
		private readonly WorkspaceEditor $editor,
		private readonly WorkspaceWriteAuthoriser $authoriser,
	) {
	}//end __construct()

	/**
	 * Invoke a write-shaped workspace tool for the current run. Never throws.
	 *
	 * @param string               $toolId    One of WorkspaceToolDescriptors::WRITE_IDS.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or `['error' => ['code', 'message']]`.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-an-unapproved-write-is-refused-before-it-happens
	 */
	public function invoke(string $toolId, array $arguments): array {
		try {
			$run = $this->scope->current();
			if (isset($arguments['workspaceId']) === true
				&& (string)$arguments['workspaceId'] !== $this->provider->workspaceId(runKey: $run['runId'])
			) {
				throw new WorkspaceException(
					errorCode: WorkspaceException::WORKSPACE_MISMATCH,
					message: 'That workspace does not belong to this run.'
				);
			}

			$root = $this->provider->root(runKey: $run['runId']);
			$this->authoriser->assertAuthorised(
				run: $run,
				toolId: $toolId,
				workspace: $this->provider->describe(runKey: $run['runId'])
			);

			return $this->dispatch(toolId: $toolId, run: $run, root: $root, arguments: $arguments);
		} catch (WorkspaceException $e) {
			return ['error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage()]];
		}
	}//end invoke()

	/**
	 * Dispatch a write-shaped tool that has passed the approval gate.
	 *
	 * @param string                                               $toolId    The tool id.
	 * @param array{runId: string, agentId: string, userId: string} $run       The verified run.
	 * @param string                                               $root      The workspace root.
	 * @param array<string, mixed>                                 $arguments The tool arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-commits-are-authored-and-pushes-authorised-as-the-resolved-run-owner
	 */
	private function dispatch(string $toolId, array $run, string $root, array $arguments): array {
		return match ($toolId) {
			WorkspaceToolDescriptors::WRITE_FILE => $this->editor->writeFile(runKey: $run['runId'], root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::DELETE_FILE => $this->editor->deleteFile(root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::APPLY_PATCH => $this->editor->applyPatch(runKey: $run['runId'], root: $root, arguments: $arguments),
			WorkspaceToolDescriptors::CREATE_BRANCH => $this->editor->createBranch(root: $root, branch: $this->branch(arguments: $arguments)),
			WorkspaceToolDescriptors::CHECKOUT_BRANCH => $this->editor->checkoutBranch(root: $root, branch: $this->branch(arguments: $arguments)),
			default => $this->editor->commit(root: $root, ownerUid: $run['userId'], arguments: $arguments),
		};
	}//end dispatch()

	/**
	 * The validated `branch` argument.
	 *
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException invalid_argument.
	 */
	private function branch(array $arguments): string {
		return $this->guard->refName(value: (string)($arguments['branch'] ?? ''));
	}//end branch()
}//end class
