<?php

/**
 * Contract of ToolLoop
 *
 * The public surface of ToolLoop that other components call, so they depend
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

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of ToolLoop.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ToolLoopInterface {

	/**
	 * Convert facade function descriptors into LLPhant FunctionInfo objects.
	 *
	 * Every FunctionInfo's `$instance` is a `FacadeToolInvoker`, so LLPhant's
	 * `$instance->{$name}(...$args)` dispatch lands on
	 * `ToolRegistryFacade::invokeTool()`. When `$channel` is non-null the invoker
	 * additionally fans `tool_call`/`tool_result` frames out to it (absorbing
	 * OR's `StreamingToolInstanceWrapper` — see FacadeToolInvoker).
	 *
	 * @param array $functions Flattened LLPhant function descriptors
	 *                         (from `listAgentFunctions()`).
	 * @param StreamYieldChannel|null $channel Optional streaming channel.
	 * @param RunTraceCollector|null $trace Optional run-trace collector; threaded
	 *                                      onto the shared `FacadeToolInvoker` so
	 *                                      each tool call the LLM makes is timed
	 *                                      as a `tool` step
	 *                                      (run-trace-observability).
	 * @param ObjectEntity|null $agent Agent object (agent-tool-governance-and-disclosure);
	 *                                 threaded onto `FacadeToolInvoker` so the
	 *                                 un-granted-destructive → approval-gate check and
	 *                                 the `hermiq.searchTools` short-circuit know which
	 *                                 agent/run they act for. Null (agent-less chat)
	 *                                 simply disables both — a plain facade dispatch,
	 *                                 unchanged.
	 * @param string|null $organisation The turn's organisation (agent-guardrails);
	 *                                  resolved ONCE into the effective GuardrailPolicy's
	 *                                  `toolPolicy` map and threaded onto the shared
	 *                                  `FacadeToolInvoker`. Null/absent GuardrailPolicyService
	 *                                  resolves to every tool `auto` (zero behavior change).
	 * @param bool $dryRun Whether this turn is a dry-run preview
	 *                     (run-replay-and-dry-run); threaded onto the
	 *                     shared `FacadeToolInvoker` along with each
	 *                     function's full descriptor (so its
	 *                     `scope`/`destructiveHint`/`readOnlyHint`, when
	 *                     set, informs the side-effect classification).
	 *                     False (every pre-existing caller) is
	 *                     byte-for-byte unchanged behavior.
	 *
	 * @return array<int, FunctionInfo> FunctionInfo objects ready for `setTools()`.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Dry-run preview (run-replay-and-dry-run)
	 * is a cross-cutting mode threaded through the engine as a flag by design.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
	 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-3
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-4
	 * @spec openspec/changes/archive/2026-07-13-agent-guardrails/tasks.md#task-5-tool-classification-autodeny-enforced-in-facadetoolinvoker
	 * @spec openspec/changes/archive/2026-07-13-run-replay-and-dry-run/tasks.md#task-3-thread-dryrun-through-toolloop-engine-and-responsegenerationhandler
	 */
	public function buildFunctionInfos(
		array $functions,
		?StreamYieldChannel $channel = null,
		?RunTraceCollector $trace = null,
		?ObjectEntity $agent = null,
		?string $organisation = null,
		bool $dryRun = false,
	): array;
}//end interface
