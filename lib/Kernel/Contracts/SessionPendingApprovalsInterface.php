<?php

/**
 * Contract of SessionPendingApprovals
 *
 * The public surface of SessionPendingApprovals that other components call, so they depend
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
 * Contract of SessionPendingApprovals.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SessionPendingApprovalsInterface {

	/**
	 * The approvals the session's agent waits on: its tool-access requests,
	 * then its tool-call approvals that the caller is the reviewer of.
	 *
	 * The caller has already been allowed to read the session.
	 *
	 * @param array<string, mixed> $session The session payload.
	 * @param string $uid The caller.
	 *
	 * @return array<int, array<string, mixed>> The approvals.
	 *
	 * @spec openspec/specs/session-surface/spec.md#requirement-a-sessions-messages-come-with-the-approvals-waiting-on-its-owner
	 */
	public function forSession(array $session, string $uid): array;
}//end interface
