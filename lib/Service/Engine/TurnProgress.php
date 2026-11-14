<?php

/**
 * Hermiq: the inputs and progress of one ResponseGenerationHandler turn.
 *
 * `ResponseGenerationHandler::generateResponse()` takes the turn's inputs one by
 * one; they travel together, with what the turn learns on the way (the agent's
 * functions, the resolved driver, the timings), through the turn's steps.
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
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\Llm\ChatDriver;

/**
 * The progress of one response-generation turn. Built and read only inside
 * ResponseGenerationHandler and the collaborators it hands its run to.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */
final class TurnProgress {

	/**
	 * When the turn started (microtime).
	 *
	 * @var float
	 */
	public float $startTime = 0.0;

	/**
	 * Seconds spent loading the agent's functions.
	 *
	 * @var float
	 */
	public float $toolsTime = 0.0;

	/**
	 * The agent's function descriptors for this turn.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $functions = [];

	/**
	 * The agent's organisation, or null without an agent.
	 *
	 * @var string|null
	 */
	public ?string $organisation = null;

	/**
	 * The AI feature the agent's runs belong to, or null.
	 *
	 * @var string|null
	 */
	public ?string $aiFeature = null;

	/**
	 * The resolved chat driver; set before any step reads it.
	 *
	 * @var ChatDriver
	 */
	public ChatDriver $driver;

	/**
	 * When the model call started (microtime).
	 *
	 * @var float
	 */
	public float $llmStartTime = 0.0;

	/**
	 * Seconds the model call took.
	 *
	 * @var float
	 */
	public float $llmTime = 0.0;

	/**
	 * The agent id bound to the Anthropic CLI run token, or null.
	 *
	 * @var string|null
	 */
	public ?string $cliAgentId = null;
}//end class
