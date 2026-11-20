<?php

/**
 * Contract of ServerSideWorkspaceProvider
 *
 * The public surface of ServerSideWorkspaceProvider that other components call, so they depend
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

/**
 * Contract of ServerSideWorkspaceProvider.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ServerSideWorkspaceProviderInterface {

	/**
	 * Remove every workspace idle for longer than the retention window.
	 *
	 * @return int The number removed.
	 *
	 * @spec openspec/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function reap(): int;

	/**
	 * Remove every conversation-long workspace whose conversation is gone.
	 *
	 * @param callable(string): bool $conversationGone Whether a conversation id no longer exists.
	 *
	 * @return int How many workspaces were removed.
	 */
	public function reapEndedSessions(callable $conversationGone): int;
}//end interface
