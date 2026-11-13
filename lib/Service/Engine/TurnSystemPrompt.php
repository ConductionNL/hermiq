<?php

/**
 * Hermiq: the system prompt of one ResponseGenerationHandler turn.
 *
 * Agent.prompt (or the default) with the owner's placeholders filled in, then
 * the assembled Context preamble, the CnAiContext snapshot and the RAG context,
 * in that order.
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

/**
 * Builds the system prompt of a turn from its run.
 *
 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
final class TurnSystemPrompt {

	/**
	 * The prompt of a turn whose agent defines none.
	 */
	private const DEFAULT_PROMPT = 'You are a helpful AI assistant that helps users find and understand their data.';

	/**
	 * Compose the system prompt of the turn.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PromptVariableResolver::fill is a pure template fill.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 */
	public function compose(ResponseTurnRun $run): string {
		// Agents-instruction-variables: the owner's placeholders are filled in
		// here, on Agent.prompt alone and before anything is appended, so no
		// retrieved document or user text is ever read as a template.
		$systemPrompt = PromptVariableResolver::fill(
			prompt: (string)($run->agentData()['prompt'] ?? self::DEFAULT_PROMPT),
			variables: $run->promptVariables
		);

		// The assembled Context preamble (agent-context-system) follows Agent.prompt
		// right away — same category ("who you are / what you know statically") as
		// the prompt itself, ahead of the per-turn CnAiContext/RAG blocks.
		if ($run->contextPreamble !== '') {
			$systemPrompt .= "\n\n" . $run->contextPreamble;
		}

		return $systemPrompt . $this->appContextBlock(cnAiContext: $run->cnAiContext) . $this->ragContextBlock(context: $run->context);
	}//end compose()

	/**
	 * The CnAiContext snapshot the widget sends with each message. Without it
	 * the LLM has no idea which app the user is in — so it would call the wrong
	 * app's tools (or default to platform-wide phrasing). The snapshot is small
	 * and free-form (typically {app, slug, view, objectId}); rendered as a bullet
	 * list so the model can quote individual fields.
	 *
	 * @param array<string, mixed> $cnAiContext The context snapshot.
	 *
	 * @return string The block, or '' without a snapshot.
	 */
	private function appContextBlock(array $cnAiContext): string {
		if (empty($cnAiContext) === true) {
			return '';
		}

		$block = "\n\nCURRENT APP CONTEXT (this is where the user is RIGHT NOW — prefer tools that match this app):\n";
		foreach ($cnAiContext as $key => $value) {
			if (is_scalar($value) === true) {
				$block .= "- {$key}: " . (string)$value . "\n";
				continue;
			}

			$block .= "- {$key}: " . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
		}

		return $block;
	}//end appContextBlock()

	/**
	 * The retrieved context the model answers from.
	 *
	 * @param array<string, mixed> $context RAG context with 'text' and 'sources' keys.
	 *
	 * @return string The block, or '' without context text.
	 */
	private function ragContextBlock(array $context): string {
		if (empty($context['text']) === true) {
			return '';
		}

		$block = "\n\nUse the following context to answer the user's question:\n\n";
		$block .= "CONTEXT:\n" . $context['text'] . "\n\n";
		$block .= "If the context doesn't contain relevant information, say so honestly. ";
		return $block . 'Always cite which sources you used when answering.';
	}//end ragContextBlock()
}//end class
