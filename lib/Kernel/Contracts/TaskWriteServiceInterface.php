<?php

/**
 * Contract of TaskWriteService
 *
 * The public surface of TaskWriteService that other components call, so they depend
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
 * Contract of TaskWriteService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaskWriteServiceInterface {

	/**
	 * Run one task tool for the acting user. Never throws.
	 *
	 * @param string $uid The acting (session) user id.
	 * @param string $toolId The namespaced tool id.
	 * @param array<string, mixed> $arguments The tool arguments; `agentId` is run-injected.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-task-tools-are-default-denied-never-delete-and-record-identity-without-content-req-nctask-004
	 */
	public function invoke(string $uid, string $toolId, array $arguments): array;

	/**
	 * Whether a completed task can be written back on this instance.
	 *
	 * @return bool True when the DAV backend's update path is available.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-an-agent-can-complete-a-task-without-losing-what-the-user-wrote-req-nctask-003
	 */
	public function canComplete(): bool;
}//end interface
