<?php

/**
 * Hermiq: the system prompt of one ResponseGenerationHandler turn.
 *
 * Agent.prompt (or the default) with the owner's placeholders filled in, then
 * the assembled Context preamble, the CnAiContext snapshot and the RAG context,
 * in that order. The first two are the stable block, the last two the per-turn
 * block, so a provider's prompt cache on the stable block survives a turn.
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
	 * Compose the system prompt of the turn: the stable block, then the per-turn block.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-1-1
	 */
	public function compose(ResponseTurnRun $run): string {
		$perTurn = $this->perTurn(run: $run);
		if ($perTurn === '') {
			return $this->stable(run: $run);
		}

		return $this->stable(run: $run) . "\n\n" . $perTurn;
	}//end compose()

	/**
	 * The stable block: the agent's prompt and its Context preamble.
	 *
	 * It is the same on every turn of one agent version, so a provider's prompt
	 * cache on it survives from one turn to the next.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PromptVariableResolver::fill is a pure template fill.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-2
	 */
	public function stable(ResponseTurnRun $run): string {
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

		return $systemPrompt;
	}//end stable()

	/**
	 * The per-turn block: the current app context, the retrieved context and an ensemble
	 * turn's reference answers, or ''.
	 *
	 * @param ResponseTurnRun $run The turn.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-2
	 */
	public function perTurn(ResponseTurnRun $run): string {
		return ltrim(
			$this->appContextBlock(cnAiContext: $run->cnAiContext) . $this->ragContextBlock(context: $run->context)
			. $this->referenceAnswersBlock(answers: $run->referenceAnswers),
			"\n"
		);
	}//end perTurn()

	/**
	 * The reference models' answers of an ensemble turn, labelled, for the
	 * agent's own model to weigh and merge (models-several-models-per-turn D7).
	 *
	 * @param array<int, array{provider: string, model: string, text: string}> $answers The answers.
	 *
	 * @return string The block, or '' outside an ensemble turn.
	 *
	 * @spec openspec/specs/model-ensemble/spec.md#requirement-reference-models-answer-without-tools-and-the-agents-own-model-merges-req-mens-002
	 */
	private function referenceAnswersBlock(array $answers): string {
		if ($answers === []) {
			return '';
		}

		$block = "\n\nDRAFT ANSWERS FROM OTHER MODELS, for you to weigh and merge. They were written without tools "
			. 'and may be wrong or incomplete. Write one answer of your own: keep what is right, correct what is not, '
			. "use your tools where they are needed, and do not mention these drafts.\n";
		foreach ($answers as $index => $answer) {
			$block .= sprintf("\n--- Draft %d (%s %s) ---\n%s\n", ($index + 1), $answer['provider'], $answer['model'], $answer['text']);
		}

		return $block;
	}//end referenceAnswersBlock()

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
		if (($context['scoped'] ?? false) === true) {
			return $this->scopedContextBlock(context: $context);
		}

		if (empty($context['text']) === true) {
			return '';
		}

		$block = "\n\nUse the following context to answer the user's question:\n\n";
		$block .= "CONTEXT:\n" . $context['text'] . "\n\n";
		$block .= "If the context doesn't contain relevant information, say so honestly. ";
		return $block . 'Always cite which sources you used when answering.';
	}//end ragContextBlock()

	/**
	 * The numbered passages of a turn scoped to one document or folder, with the
	 * instruction to cite them by number and to answer from nothing else.
	 *
	 * @param array<string, mixed> $context The scoped context.
	 *
	 * @return string The block.
	 *
	 * @spec openspec/changes/archive/2026-11-25-chat-about-a-document-cites-its-sources/specs/document-chat/spec.md#requirement-every-scoped-answer-cites-its-sources-req-dchat-003
	 */
	private function scopedContextBlock(array $context): string {
		$block = "\n\nThe user asks about one document or the documents in one folder. Answer ONLY from the "
			. "numbered passages below, which come from it. After every claim, put the number of the passage "
			. "it rests on in square brackets, for example [1] or [2]. Do not answer from general knowledge. "
			. "If the passages do not answer the question, say that the document does not answer it.\n\n";

		return $block . "PASSAGES:\n" . (string)($context['text'] ?? '');
	}//end scopedContextBlock()
}//end class
