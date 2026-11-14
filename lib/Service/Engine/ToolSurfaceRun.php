<?php

/**
 * Hermiq: the inputs of one tool-surface build in ToolLoop.
 *
 * `ToolLoop::buildFunctionInfos()` turns the resolved function descriptors of a
 * turn into LLPhant FunctionInfo objects that all share one FacadeToolInvoker.
 * The values that invoker needs travel together through the build's helpers.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One tool-surface build: the descriptors and the turn they are built for.
 *
 * Built and read only inside ToolLoop.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-3-1
 */
final class ToolSurfaceRun {

	/**
	 * Flattened LLPhant function descriptors.
	 *
	 * @var array<int|string, mixed>
	 */
	public array $functions = [];

	/**
	 * Optional streaming channel for tool_call/tool_result frames.
	 *
	 * @var StreamYieldChannel|null
	 */
	public ?StreamYieldChannel $channel = null;

	/**
	 * Optional run-trace collector.
	 *
	 * @var RunTraceCollector|null
	 */
	public ?RunTraceCollector $trace = null;

	/**
	 * The acting agent, or null for agent-less chat.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $agent = null;

	/**
	 * The turn's organisation, or null.
	 *
	 * @var string|null
	 */
	public ?string $organisation = null;

	/**
	 * Whether this turn is a dry-run preview.
	 *
	 * @var bool
	 */
	public bool $dryRun = false;

	/**
	 * The session's project (memory-project-scopes), or null outside a project.
	 *
	 * @var string|null
	 */
	public ?string $projectKey = null;
}//end class
