<?php

/**
 * Hermiq AnthropicMessageFormat.
 *
 * The Anthropic Messages API wire shapes: an LLPhant history as `{system, messages}`,
 * OpenAI-style function definitions as Anthropic `tools`, a decoded response as the
 * engine-facing `{text, toolCalls, stopReason, content, usage}`, and governed tool
 * results as `tool_result` content blocks.
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
 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use stdClass;

/**
 * Maps between Hermiq's engine shapes and the Anthropic Messages API. Stateless.
 *
 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
 */
final class AnthropicMessageFormat {

	/**
	 * Split an LLPhant message history into Anthropic's `{system, messages}` shape.
	 *
	 * System turns are concatenated into the top-level `system` string (Anthropic keeps
	 * the system prompt out of `messages`); user/assistant turns become
	 * `{role, content}` entries.
	 *
	 * @param array $messageHistory Array of LLPhant Message objects.
	 *
	 * @return array{system: string|array<int, array{type: string, text: string}>, messages: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function mapHistoryToAnthropicMessages(array $messageHistory): array {
		$systemParts = [];
		$messages = [];
		foreach ($messageHistory as $msg) {
			$role = $msg->role->value;
			if ($role === 'system') {
				$systemParts[] = (string)$msg->content;
				continue;
			}

			// @todo llm-cli-runner-exapp / anthropic-agent-provider — Hermiq's engine never
			// stores prior tool_use/tool_result turns as LLPhant messages today (tool turns
			// live only inside this call's Anthropic loop). If a future history carries them
			// (e.g. LLPhant Message::toolCalls), map them to tool_use/tool_result content
			// blocks here. Until then, pass text turns through — the common path.
			// Chat-attachments-and-images: a turn with native parts carries its image
			// and document blocks ahead of the text.
			if ($msg instanceof AttachmentMessage) {
				$messages[] = ['role' => $role, 'content' => $msg->anthropicContent()];
				continue;
			}

			$messages[] = [
				'role' => $role,
				'content' => (string)$msg->content,
			];
		}

		return [
			'system' => $this->systemField(systemParts: $systemParts),
			'messages' => $messages,
		];

	}//end mapHistoryToAnthropicMessages()

	/**
	 * The `system` field: one system turn as a string, several as text blocks in order.
	 *
	 * Models-prompt-and-answer-cache: the turn sends its stable block and its
	 * per-turn block as two system turns; as separate text blocks the cache
	 * breakpoint sits on the stable one, and the per-turn one changes freely.
	 *
	 * @param array<int, string> $systemParts The system turns' text, in order.
	 *
	 * @return string|array<int, array{type: string, text: string}>
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-2
	 */
	private function systemField(array $systemParts): string|array {
		$systemParts = array_values(array_filter($systemParts, static fn (string $part): bool => $part !== ''));
		if (count($systemParts) <= 1) {
			return (string)($systemParts[0] ?? '');
		}

		return array_map(static fn (string $part): array => ['type' => 'text', 'text' => $part], $systemParts);
	}//end systemField()

	/**
	 * Map OpenAI-style function definitions to Anthropic `tools`.
	 *
	 * OpenAI's `{name, description, parameters}` becomes Anthropic's `{name, description,
	 * input_schema}` (the ONLY structural difference is `parameters` → `input_schema`).
	 * Entries without a name are skipped; a missing schema defaults to an empty object
	 * schema so the model still sees a valid, argument-less tool.
	 *
	 * @param array $functions OpenAI-style function definitions.
	 *
	 * @return array<int, array<string, mixed>> Anthropic tool definitions.
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function buildAnthropicTools(array $functions): array {
		$tools = [];
		foreach ($functions as $function) {
			if (is_array($function) === false || isset($function['name']) === false) {
				continue;
			}

			$schema = $function['parameters'] ?? null;
			if (is_array($schema) === false) {
				$schema = ['type' => 'object'];
			}

			if (isset($schema['type']) === false) {
				$schema['type'] = 'object';
			}

			// Anthropic requires `input_schema.properties` to be a JSON object.
			// An argument-less tool has an empty properties map, and PHP's
			// json_encode emits an empty PHP array as `[]` (a JSON array), which
			// Anthropic rejects with "Input should be an object". Force an empty
			// stdClass so it serialises as `{}`.
			// `isset()` is already false for a null properties, so an explicit
			// `=== null` here would be unreachable.
			if (isset($schema['properties']) === false || $schema['properties'] === []) {
				$schema['properties'] = new stdClass();
			}

			$tools[] = [
				'name' => (string)$function['name'],
				'description' => (string)($function['description'] ?? ''),
				'input_schema' => $schema,
			];
		}//end foreach

		return $tools;
	}//end buildAnthropicTools()

	/**
	 * Parse an Anthropic Messages API response into the engine-facing shape.
	 *
	 * Concatenates every `type: "text"` content block into `text`, extracts every
	 * `type: "tool_use"` block into `toolCalls` (`{id, name, input}` — the same
	 * descriptor shape the runner path normalises into), and carries the `stop_reason`,
	 * the raw `content` array (echoed back verbatim as the assistant tool_use turn on the
	 * next loop pass), and token `usage`.
	 *
	 * @param array $data Decoded Anthropic Messages API response.
	 *
	 * Every `type: "thinking"` block (extended thinking, chat-how-an-answer-came-about D3)
	 * is concatenated into `reasoning`, apart from the answer text; a `redacted_thinking`
	 * block carries no readable text and is left out.
	 *
	 * @return array{text: string, toolCalls: array<int, array<string, mixed>>,
	 *     stopReason: string, content: array<int, mixed>, usage: array<string, int>,
	 *     reasoning: string}
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function parseAnthropicResponse(array $data): array {
		$content = [];
		if (is_array($data['content'] ?? null) === true) {
			$content = $data['content'];
		}

		return [
			'text' => $this->anthropicText(content: $content),
			'toolCalls' => $this->anthropicToolCalls(content: $content),
			'stopReason' => (string)($data['stop_reason'] ?? ''),
			'content' => $content,
			'usage' => $this->anthropicUsage(data: $data),
			'reasoning' => $this->anthropicThinking(content: $content),
		];

	}//end parseAnthropicResponse()

	/**
	 * The concatenated text of every `thinking` block, blocks separated by a blank line.
	 *
	 * @param array<int, mixed> $content The response's content blocks.
	 *
	 * @return string The reasoning, or '' when the response has none.
	 *
	 * @spec openspec/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
	 */
	private function anthropicThinking(array $content): string {
		$parts = [];
		foreach ($content as $block) {
			if (is_array($block) === true && ($block['type'] ?? '') === 'thinking') {
				$thinking = trim((string)($block['thinking'] ?? ''));
				if ($thinking !== '') {
					$parts[] = $thinking;
				}
			}
		}

		return implode("\n\n", $parts);
	}//end anthropicThinking()

	/**
	 * The concatenated text of every `text` block.
	 *
	 * @param array<int, mixed> $content The response's content blocks.
	 *
	 * @return string
	 */
	private function anthropicText(array $content): string {
		$text = '';
		foreach ($content as $block) {
			if (is_array($block) === true && ($block['type'] ?? '') === 'text') {
				$text .= (string)($block['text'] ?? '');
			}
		}

		return $text;
	}//end anthropicText()

	/**
	 * Every `tool_use` block as `{id, name, input}`, with a non-array input as `[]`.
	 *
	 * @param array<int, mixed> $content The response's content blocks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function anthropicToolCalls(array $content): array {
		$toolCalls = [];
		foreach ($content as $block) {
			if (is_array($block) === false || ($block['type'] ?? '') !== 'tool_use') {
				continue;
			}

			$input = ($block['input'] ?? []);
			if (is_array($input) === false) {
				$input = [];
			}

			$toolCalls[] = [
				'id' => (string)($block['id'] ?? ''),
				'name' => (string)($block['name'] ?? ''),
				'input' => $input,
			];
		}

		return $toolCalls;
	}//end anthropicToolCalls()

	/**
	 * The response's token usage, or `[]` when it reports none.
	 *
	 * @param array<string, mixed> $data Decoded Anthropic Messages API response.
	 *
	 * @return array<string, int>
	 */
	private function anthropicUsage(array $data): array {
		$rawUsage = ($data['usage'] ?? []);
		if (is_array($rawUsage) === false) {
			return [];
		}

		// `input_tokens` counts only the uncached input; the prompt read from or
		// written to the cache is counted apart. promptTokens is every input token
		// the model read, so a budget stays conservative (REQ-PCACHE-003).
		$cacheRead  = (int)($rawUsage['cache_read_input_tokens'] ?? 0);
		$cacheWrite = (int)($rawUsage['cache_creation_input_tokens'] ?? 0);
		$usage      = [
			'promptTokens' => ((int)($rawUsage['input_tokens'] ?? 0) + $cacheRead + $cacheWrite),
			'completionTokens' => (int)($rawUsage['output_tokens'] ?? 0),
		];
		if (array_key_exists('cache_read_input_tokens', $rawUsage) === true) {
			$usage['cacheReadTokens'] = $cacheRead;
		}

		if (array_key_exists('cache_creation_input_tokens', $rawUsage) === true) {
			$usage['cacheWriteTokens'] = $cacheWrite;
		}

		return $usage;
	}//end anthropicUsage()

	/**
	 * Build the Anthropic `tool_result` content blocks for one user turn.
	 *
	 * Each `{tool_use_id, content, is_error?}` becomes a `{type: "tool_result",
	 * tool_use_id, content}` block (with `is_error` passed through when set). This is the
	 * inverse of `parseAnthropicResponse()`'s tool_use extraction — a governed tool
	 * result round-trips back into an Anthropic content block.
	 *
	 * @param array $toolResults `[{tool_use_id: string, content: string, is_error?: bool}, ...]`.
	 *
	 * @return array<int, array<string, mixed>> Anthropic tool_result content blocks.
	 *
	 * @spec openspec/specs/anthropic-agent-provider/spec.md#requirement-anthropic-is-a-selectable-chat-provider
	 */
	public function buildAnthropicToolResultBlocks(array $toolResults): array {
		$blocks = [];
		foreach ($toolResults as $toolResult) {
			if (is_array($toolResult) === false) {
				continue;
			}

			$block = [
				'type' => 'tool_result',
				'tool_use_id' => (string)($toolResult['tool_use_id'] ?? ''),
				'content' => $this->toolResultContent(content: (string)($toolResult['content'] ?? '')),
			];

			if (($toolResult['is_error'] ?? false) === true) {
				$block['is_error'] = true;
			}

			$blocks[] = $block;
		}

		return $blocks;
	}//end buildAnthropicToolResultBlocks()

	/**
	 * A tool result's content: the text as is, or an image block and the rest as text
	 * when the result carries a screenshot (tools-browser-and-computer-use D4).
	 *
	 * A result with a base64 `image` and an image `mimeType` (the computer tools) is
	 * shown to the model as the image, so it can see the screen; the other fields stay
	 * as JSON text with the image replaced by a note, so the bytes are sent once.
	 *
	 * @param string $content The JSON-encoded tool result.
	 *
	 * @return string|list<array<string, mixed>> The content, or the image and text blocks.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-computer-use-is-a-high-risk-ai-feature-that-a-grant-alone-cannot-unlock-req-computer-002
	 */
	private function toolResultContent(string $content): string|array {
		$decoded = json_decode($content, true);
		if (is_array($decoded) === false) {
			return $content;
		}

		$image = ($decoded['image'] ?? null);
		$mime  = ($decoded['mimeType'] ?? null);
		if (self::isAttachableImage(image: $image, mime: $mime) === false) {
			return $content;
		}

		$decoded['image'] = '(the screenshot is attached as an image)';

		return [
			['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $image]],
			['type' => 'text', 'text' => (string)json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
		];
	}//end toolResultContent()

	/**
	 * Whether a tool result's image can be attached as an image block: a
	 * non-empty base64 string of a supported image type.
	 *
	 * @param mixed $image The result's `image` value.
	 * @param mixed $mime  The result's `mimeType` value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use
	 */
	private static function isAttachableImage(mixed $image, mixed $mime): bool {
		if (is_string($image) === false || $image === '' || is_string($mime) === false) {
			return false;
		}

		return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) === true
			&& base64_decode($image, true) !== false;
	}//end isAttachableImage()
}//end class
