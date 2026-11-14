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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One response-generation turn. Built and read only inside ResponseGenerationHandler.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 */
final class ResponseTurnRun {

	/**
	 * The LLPhant messages of the turn: the earlier turns, then the system
	 * prompt in front and the current user turn at the end once built.
	 *
	 * @var array<int, mixed>
	 */
	public array $messageHistory = [];

	/**
	 * The agent, or null for agent-less chat.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $agent = null;

	/**
	 * Tools selected for this request.
	 *
	 * @var array<int, mixed>
	 */
	public array $selectedTools = [];

	/**
	 * The streaming channel, or null for a blocking turn.
	 *
	 * @var StreamYieldChannel|null
	 */
	public ?StreamYieldChannel $channel = null;

	/**
	 * The AI Chat Companion context snapshot.
	 *
	 * @var array<string, mixed>
	 */
	public array $cnAiContext = [];

	/**
	 * The assembled Context preamble.
	 *
	 * @var string
	 */
	public string $contextPreamble = '';

	/**
	 * The run-trace collector, or null.
	 *
	 * @var RunTraceCollector|null
	 */
	public ?RunTraceCollector $trace = null;

	/**
	 * Whether this turn is a dry-run preview.
	 *
	 * @var bool
	 */
	public bool $dryRun = false;

	/**
	 * The conversation this turn belongs to.
	 *
	 * @var string
	 */
	public string $conversationId = '';

	/**
	 * The session's project (memory-project-scopes); '' outside a project.
	 *
	 * @var string
	 */
	public string $projectKey = '';

	/**
	 * Placeholder values for the agent's instructions.
	 *
	 * @var array<string, string>
	 */
	public array $promptVariables = [];

	/**
	 * The turn's resolved attachments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $attachments = [];

	/**
	 * The uid the attachments were resolved for.
	 *
	 * @var string
	 */
	public string $speaker = '';

	/**
	 * What the turn has learned so far.
	 *
	 * @var TurnProgress
	 */
	public TurnProgress $progress;

	/**
	 * Constructor.
	 *
	 * @param string               $userMessage The user's message text.
	 * @param array<string, mixed> $context     RAG context with 'text' and 'sources' keys.
	 */
	public function __construct(
		public readonly string $userMessage,
		public readonly array $context,
	) {
		$this->progress = new TurnProgress();
	}//end __construct()

	/**
	 * The agent's object data, or [] without an agent.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 */
	public function agentData(): array {
		if ($this->agent === null) {
			return [];
		}

		return $this->agent->getObject();
	}//end agentData()
}//end class
