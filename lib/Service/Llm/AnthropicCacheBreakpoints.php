<?php

/**
 * Hermiq AnthropicCacheBreakpoints
 *
 * Where an Anthropic Messages request marks its prompt-cache breakpoints.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

/**
 * Marks the three prompt-cache breakpoints of an Anthropic request.
 *
 * Anthropic caches the request prefix up to each `cache_control` breakpoint and
 * accepts at most four. Hermiq sets three: the last tool definition, the
 * stable system block, and the end of the conversation so far. In a tool round
 * the conversation ends in the last tool result, so the third breakpoint sits
 * there. A breakpoint changes cost and speed, never the answer.
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
 */
final class AnthropicCacheBreakpoints {

	/**
	 * The lifetimes Anthropic accepts; anything else falls back to the default.
	 */
	public const TTLS = ['5m', '1h'];

	/**
	 * Build the marker for one lifetime.
	 *
	 * @param string $ttl `5m` (the default) or `1h`.
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
	 */
	public function __construct(
		private readonly string $ttl='5m',
	) {
	}//end __construct()

	/**
	 * The marker the admin's Anthropic settings ask for, or null when caching is off.
	 *
	 * Caching is on unless `promptCaching` is exactly false.
	 *
	 * @param array<string, mixed> $anthropicConfig The `anthropicConfig` settings.
	 *
	 * @return self|null
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
	 */
	public static function fromConfig(array $anthropicConfig): ?self {
		if (($anthropicConfig['promptCaching'] ?? true) === false) {
			return null;
		}

		$ttl = (string)($anthropicConfig['promptCacheTtl'] ?? '5m');
		if (in_array($ttl, self::TTLS, true) === false) {
			$ttl = '5m';
		}

		return new self(ttl: $ttl);
	}//end fromConfig()

	/**
	 * The request with its breakpoints.
	 *
	 * @param array<string, mixed> $payload The Messages request body.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
	 */
	public function apply(array $payload): array {
		if (is_array($payload['tools'] ?? null) === true && $payload['tools'] !== []) {
			$last = array_key_last($payload['tools']);
			$payload['tools'][$last]['cache_control'] = $this->cacheControl();
		}

		if (array_key_exists('system', $payload) === true) {
			$payload['system'] = $this->markSystem(system: $payload['system']);
		}

		if (is_array($payload['messages'] ?? null) === true) {
			$payload['messages'] = $this->markConversation(messages: $payload['messages']);
		}

		return $payload;
	}//end apply()

	/**
	 * The breakpoint itself.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
	 */
	public function cacheControl(): array {
		if ($this->ttl === '1h') {
			return ['type' => 'ephemeral', 'ttl' => '1h'];
		}

		return ['type' => 'ephemeral'];
	}//end cacheControl()

	/**
	 * The system prompt with a breakpoint on its stable (first) block.
	 *
	 * @param mixed $system A string, or a list of text blocks with the stable one first.
	 *
	 * @return mixed
	 */
	private function markSystem(mixed $system): mixed {
		if (is_string($system) === true) {
			if ($system === '') {
				return $system;
			}

			return [['type' => 'text', 'text' => $system, 'cache_control' => $this->cacheControl()]];
		}

		if (is_array($system) === true && isset($system[0]) === true && is_array($system[0]) === true) {
			$system[0]['cache_control'] = $this->cacheControl();
		}

		return $system;
	}//end markSystem()

	/**
	 * The messages with a breakpoint at the end of the conversation so far: on
	 * the last tool result in a tool round, else on the message before the
	 * newest user message.
	 *
	 * @param array<int, mixed> $messages The messages, oldest first.
	 *
	 * @return array<int, mixed>
	 */
	private function markConversation(array $messages): array {
		$messages = array_values($messages);
		$newest   = (count($messages) - 1);
		if ($newest < 0) {
			return $messages;
		}

		$toolResult = $this->lastToolResultIndex(message: $messages[$newest]);
		if ($toolResult !== null) {
			$messages[$newest]['content'][$toolResult]['cache_control'] = $this->cacheControl();
			return $messages;
		}

		if ($newest === 0 || is_array($messages[$newest - 1]) === false) {
			return $messages;
		}

		$messages[$newest - 1] = $this->markMessage(message: $messages[$newest - 1]);
		return $messages;
	}//end markConversation()

	/**
	 * The index of the last tool result block of a user message, if any.
	 *
	 * @param mixed $message The message.
	 *
	 * @return int|string|null
	 */
	private function lastToolResultIndex(mixed $message): int|string|null {
		if (is_array($message) === false || ($message['role'] ?? '') !== 'user' || is_array($message['content'] ?? null) === false) {
			return null;
		}

		$found = null;
		foreach ($message['content'] as $index => $block) {
			if (is_array($block) === true && ($block['type'] ?? '') === 'tool_result') {
				$found = $index;
			}
		}

		return $found;
	}//end lastToolResultIndex()

	/**
	 * A message with a breakpoint on its last content block.
	 *
	 * @param array<string, mixed> $message The message.
	 *
	 * @return array<string, mixed>
	 */
	private function markMessage(array $message): array {
		$content = ($message['content'] ?? null);
		if (is_string($content) === true && $content !== '') {
			$message['content'] = [['type' => 'text', 'text' => $content, 'cache_control' => $this->cacheControl()]];
			return $message;
		}

		if (is_array($content) === true && $content !== []) {
			$last = array_key_last($content);
			if (is_array($content[$last]) === true) {
				$message['content'][$last]['cache_control'] = $this->cacheControl();
			}
		}

		return $message;
	}//end markMessage()
}//end class
