<?php

/**
 * Hermiq ReasoningOllamaChat.
 *
 * LLPhant's OllamaChat, which also keeps the model's reasoning: the `thinking`
 * field Ollama returns next to the answer when a call asks for `think: true`
 * (chat-how-an-answer-came-about D3).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use GuzzleHttp\Psr7\Response;
use LLPhant\Chat\OllamaChat;
use Psr\Http\Message\ResponseInterface;

/**
 * Reasoning is asked for per call with the `think` model option
 * (`setModelOption('think', true)`). The vendor patch
 * llphant-ollama-think-keepalive adds `think: false` only when the call does not
 * set `think` itself (an array union, the call's own keys win), so an agent
 * without reasoning still sends `think: false`.
 *
 * Only a blocking `/chat` answer is read here; a streamed answer's reasoning
 * arrives in chunks the parent's stream decoder consumes, and is not kept.
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */
class ReasoningOllamaChat extends OllamaChat {

	/**
	 * The reasoning of every blocking chat request since the last reset, joined
	 * by a blank line; '' when none was returned.
	 *
	 * @var string
	 */
	public string $lastThinking = '';

	/**
	 * Whether this chat asks for reasoning (`think: true`).
	 *
	 * @var boolean
	 */
	public bool $asksForReasoning = false;

	/**
	 * Ask for reasoning on the next calls, and forget the reasoning of earlier ones.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	public function askForReasoning(): void {
		$this->asksForReasoning = true;
		$this->setModelOption(option: 'think', value: true);
		$this->resetThinking();
	}//end askForReasoning()

	/**
	 * Forget the reasoning of earlier calls.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	public function resetThinking(): void {
		$this->lastThinking = '';
	}//end resetThinking()

	/**
	 * Send the request, and keep the `thinking` of a blocking chat answer. The
	 * body is read once and handed on unchanged, so the parent parses it as before.
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $path   The API path.
	 * @param array<string, mixed> $json   The request body.
	 *
	 * @return ResponseInterface The response, with its body intact.
	 *
	 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	protected function sendRequest(string $method, string $path, array $json): ResponseInterface {
		$response = parent::sendRequest(method: $method, path: $path, json: $json);
		if ($path !== 'chat' || ($json['stream'] ?? false) !== false) {
			return $response;
		}

		$contents = $response->getBody()->getContents();
		$this->keepThinking(contents: $contents);

		return new Response(
			$response->getStatusCode(),
			$response->getHeaders(),
			$contents,
			$response->getProtocolVersion(),
			$response->getReasonPhrase()
		);
	}//end sendRequest()

	/**
	 * Add the answer's `message.thinking` to what was kept.
	 *
	 * @param string $contents The raw response body.
	 *
	 * @return void
	 */
	private function keepThinking(string $contents): void {
		$data = json_decode($contents, true);
		if (is_array($data) === false || is_array($data['message'] ?? null) === false) {
			return;
		}

		$thinking = trim((string)($data['message']['thinking'] ?? ''));
		if ($thinking === '') {
			return;
		}

		if ($this->lastThinking === '') {
			$this->lastThinking = $thinking;
			return;
		}

		$this->lastThinking .= "\n\n" . $thinking;
	}//end keepThinking()
}//end class
