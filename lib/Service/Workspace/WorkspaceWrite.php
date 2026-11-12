<?php

/**
 * Hermiq WorkspaceWrite.
 *
 * One governed workspace write: the tool, the verified run, the workspace root
 * and the arguments, then the approval that permitted it and the tool's result.
 * Built by WorkspaceWrites, recorded by WorkspaceAuditor.
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * One governed workspace write.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-every-governed-workspace-write-is-audited-with-owner-credential-and-approval
 */
final class WorkspaceWrite {

	/**
	 * The workspace root.
	 *
	 * @var string
	 */
	public string $root = '';

	/**
	 * The tool arguments.
	 *
	 * @var array<string, mixed>
	 */
	public array $arguments = [];

	/**
	 * The decision that permitted the write, or null (not yet, or refused).
	 *
	 * @var array{approvalId: string, decidedBy: string}|null
	 */
	public ?array $approval = null;

	/**
	 * The tool's result, or null when the write did not happen.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string                                               $toolId The tool id.
	 * @param array{runId: string, agentId: string, userId: string} $run    The verified run.
	 */
	public function __construct(
		public readonly string $toolId,
		public readonly array $run,
	) {
	}//end __construct()
}//end class
