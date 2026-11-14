<?php

/**
 * Contract of DelegationService
 *
 * The public surface of DelegationService that other components call, so they depend
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
 * Contract of DelegationService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DelegationServiceInterface {

	/**
	 * Delegate a bounded sub-task from `$callerAgentId` to `$targetAgentId`.
	 *
	 * Never throws: every refusal is a structured `{error:{code,message}}`
	 * envelope; on success `{targetAgentId, result}` is returned.
	 *
	 * @param string $callerAgentId The delegating agent's own UUID (server-injected —
	 *                              see `Engine\FacadeToolInvoker::withAgentId()` — NEVER
	 *                              read from the tool call's own arguments).
	 * @param string $targetAgentId The agent UUID to delegate to (LLM-supplied).
	 * @param string $task The bounded task/prompt to hand to the target (LLM-supplied).
	 *
	 * @return array<string, mixed> `{targetAgentId, result}` on success, or
	 *                              `{error: {code, message}}` on refusal/failure.
	 *
	 * @spec openspec/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) A fixed, ordered gate sequence
	 *   (self/cycle → allowlist → depth/fan-out → same-organisation → model-policy →
	 *   kill-switch → budget → approval) — each gate's OWN logic already lives in its
	 *   own small, independently-tested private method; this method is the orchestrator
	 *   deciding whether to proceed to the next one, mirroring
	 *   `ScheduleService::dispatch()`'s identical shape for the same reason.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same reasoning as above.
	 */
	public function delegate(string $callerAgentId, string $targetAgentId, string $task): array;
}//end interface
