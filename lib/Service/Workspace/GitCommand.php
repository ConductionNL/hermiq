<?php

/**
 * Hermiq GitCommand.
 *
 * One git invocation as the runner starts it: the arguments after the hardening
 * options, the budget, the directory and the input.
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * One git invocation.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-the-repository-metadata-directory-is-never-writable-through-a-governed-tool
 */
final class GitCommand {

	/**
	 * The directory to run in, or null.
	 *
	 * @var string|null
	 */
	public ?string $workingDir = null;

	/**
	 * Input for the process, or null.
	 *
	 * @var string|null
	 */
	public ?string $stdin = null;

	/**
	 * Build the command.
	 *
	 * @param array<int, string> $arguments      The git arguments (after the hardening options).
	 * @param int                $timeoutSeconds The wall-clock budget.
	 */
	public function __construct(
		public readonly array $arguments,
		public readonly int $timeoutSeconds,
	) {
	}//end __construct()
}//end class
