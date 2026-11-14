<?php

/**
 * Contract of FlowAgentRunService
 *
 * The public surface of FlowAgentRunService that other components call, so they depend
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
 * Contract of FlowAgentRunService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FlowAgentRunServiceInterface {

	/**
	 * Run one flow-triggered agent-run request, applying the synchronous oversight
	 * gates first — mirrors `ScheduleService::dispatch()`'s gate ordering exactly.
	 *
	 * @param array<string,mixed> $payload The AgentRunRequestedEvent payload
	 *                                     (subjectUuid/subjectRegister/subjectSchema/
	 *                                     agent/skill/prompt/resultField/requiresApproval/
	 *                                     mode/flowName/correlationId).
	 * @param bool $bypassApprovalGate When true, skip the requiresApproval gate for
	 *                                 this authorised occurrence (an approval-run).
	 *
	 * @return bool Whether the agent run actually executed (false when gated/skipped/failed).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The bypass is a genuine two-mode
	 *   authorisation input (normal dispatch vs. an already-approved occurrence),
	 *   mirroring ScheduleService::runNow()'s identical parameter.
	 *
	 * @spec openspec/changes/archive/2026-09-06-flow-agent-listener/tasks.md#2-flowagentrunservice-governed-dispatch
	 */
	public function run(array $payload, bool $bypassApprovalGate = false): bool;
}//end interface
