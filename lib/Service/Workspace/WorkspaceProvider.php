<?php

/**
 * Hermiq WorkspaceProvider.
 *
 * The seam between the governed workspace toolset (the contract every consumer
 * shares: confinement, credential separation, attribution, grant and approval,
 * audit) and the place the bytes live. Hermiq's implementation keeps a
 * run-keyed directory on the governed side; a Hydra builder may implement the
 * same seam over its own checkout with no Nextcloud dependency.
 *
 * A run key is the run id taken from the verified run token, never a value the
 * model supplied. Nothing here returns a filesystem path to a caller that could
 * pass it on to the model: `root()` is for the toolset alone.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * Where a run's workspace lives, and its budget.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-toolset-is-a-contract-with-two-consumers-and-its-runtime-is-not-required-to-be-shared
 */
interface WorkspaceProvider {

	/**
	 * Materialise the run's workspace, or return the existing one when the same
	 * repository and ref are asked for again.
	 *
	 * @param string $runKey     The run id from the verified token.
	 * @param string $cloneUrl   The server-resolved clone URL (never from the model).
	 * @param string $repository The forge-relative slug.
	 * @param string $ref        The branch or tag to start from.
	 * @param int    $depth      The history depth.
	 *
	 * @return array{workspaceId: string, repository: string, ref: string, headSha: string, fileCount: int}
	 *
	 * @throws WorkspaceException
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function open(string $runKey, string $cloneUrl, string $repository, string $ref, int $depth): array;

	/**
	 * The opaque workspace id for a run key.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function workspaceId(string $runKey): string;

	/**
	 * The absolute repository root of the run's workspace. For the toolset only.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return string
	 *
	 * @throws WorkspaceException workspace_absent when none was opened for this run.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function root(string $runKey): string;

	/**
	 * The repository and ref the workspace was opened on.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return array{repository: string, ref: string}
	 *
	 * @throws WorkspaceException workspace_absent.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function describe(string $runKey): array;

	/**
	 * Refuse a write that would take the workspace over its size or file budget.
	 *
	 * @param string $runKey   The run id.
	 * @param int    $addBytes The bytes the write adds (may be negative).
	 * @param int    $addFiles The files the write adds (may be negative).
	 *
	 * @return void
	 *
	 * @throws WorkspaceException workspace_quota.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function reserve(string $runKey, int $addBytes, int $addFiles): void;

	/**
	 * Remove the run's workspace.
	 *
	 * @param string $runKey The run id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function discard(string $runKey): void;
}//end interface
