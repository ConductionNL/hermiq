<?php

/**
 * Hermiq Nextcloud AI message mapper.
 *
 * Maps hermiq's LLPhant history and OpenAI-style function definitions onto the input
 * of Nextcloud's `core:text2text:chat` and `core:text2text:chatwithtools` task types,
 * and the `tool_calls` output back. The shapes follow what Nextcloud's providers read
 * (integration_openai's createChatCompletion, the context_agent convention): history
 * entries are JSON `{role, content}` strings, an assistant turn carries `tool_calls` as
 * `[{id, name, args}]`, and tool results go in `tool_message` as
 * `[{tool_call_id, name, content}]`. Pure: no collaborators, no state.
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
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCP\TaskProcessing\Task;
use stdClass;

/**
 * Maps hermiq chat turns onto Nextcloud's chat task shapes and back.
 *
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
 */
class NextcloudChatMapper {

	/**
	 * Map LLPhant history onto the chat task's `system_prompt`, `input` and `history`.
	 *
	 * @param array $messageHistory LLPhant messages.
	 *
	 * @return array{system: string, input: string, history: list<string>}
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function splitHistory(array $messageHistory): array {
		$system = [];
		$turns = [];
		foreach ($messageHistory as $message) {
			$role = (string)$message->role->value;
			$content = $message->content;
			if (is_string($content) === false) {
				$content = (string)json_encode($content);
			}

			if ($role === 'system') {
				$system[] = $content;
				continue;
			}

			$turns[] = ['role' => $role, 'content' => $content];
		}

		$input = '';
		$last = end($turns);
		if ($last !== false && $last['role'] === 'user') {
			array_pop($turns);
			$input = $last['content'];
		}

		// The engine stores the user's turn before it builds the history, then appends
		// the same turn again, so it arrives twice. Send it once, as `input`.
		$previous = end($turns);
		if ($previous !== false && $previous['role'] === 'user' && $previous['content'] === $input) {
			array_pop($turns);
		}

		return [
			'system' => implode("\n\n", $system),
			'input' => $input,
			'history' => array_map(fn (array $turn): string => $this->encode(value: $turn), $turns),
		];
	}//end splitHistory()

	/**
	 * Map OpenAI-style function definitions onto the chatwithtools `tools` JSON.
	 *
	 * @param array $functions Function definitions `{name, description, parameters}`.
	 *
	 * @return string The tools JSON: `[{type: "function", function: {...}}]`.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function buildTools(array $functions): string {
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

			// An empty PHP array encodes as a JSON list; the schema needs an object.
			if (isset($schema['properties']) === false || $schema['properties'] === []) {
				$schema['properties'] = new stdClass();
			}

			$tools[] = [
				'type' => 'function',
				'function' => [
					'name' => (string)$function['name'],
					'description' => (string)($function['description'] ?? ''),
					'parameters' => $schema,
				],
			];
		}//end foreach

		return $this->encode(value: $tools);
	}//end buildTools()

	/**
	 * Parse the chatwithtools `tool_calls` output: `[{id, name, args}]`.
	 *
	 * @param mixed $raw The output slot value.
	 *
	 * @return list<array{id: string, name: string, args: array<string, mixed>}>
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function parseToolCalls(mixed $raw): array {
		if (is_string($raw) === false || trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$calls = [];
		foreach ($decoded as $call) {
			if (is_array($call) === false || isset($call['name']) === false) {
				continue;
			}

			$args = ($call['args'] ?? ($call['arguments'] ?? []));
			if (is_string($args) === true) {
				$args = json_decode($args, true);
			}

			if (is_array($args) === false) {
				$args = [];
			}

			$calls[] = ['id' => (string)($call['id'] ?? ''), 'name' => (string)$call['name'], 'args' => $args];
		}

		return $calls;
	}//end parseToolCalls()

	/**
	 * JSON-encode one value.
	 *
	 * @param array $value The value.
	 *
	 * @return string The JSON.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function encode(array $value): string {
		return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end encode()

	/**
	 * JSON-encode a list, or the empty string for an empty one.
	 *
	 * @param array $list The list.
	 *
	 * @return string The JSON, or ''.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function encodeList(array $list): string {
		if ($list === []) {
			return '';
		}

		return $this->encode(value: array_values($list));
	}//end encodeList()

	/**
	 * Move one sent round into the history: the user's turn (when there was one), the
	 * tool results of the previous round, then the assistant turn that asked for tools.
	 *
	 * @param list<string> $history The history so far.
	 * @param string $input The user's turn sent this round, or ''.
	 * @param list<array{tool_call_id: string, name: string, content: string}> $toolMessages The previous round's tool results.
	 * @param string $text The assistant's text this round.
	 * @param list<array{id: string, name: string, args: array<string, mixed>}> $toolCalls The tool calls it asked for.
	 *
	 * @return list<string> The extended history.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function appendRound(array $history, string $input, array $toolMessages, string $text, array $toolCalls): array {
		if ($input !== '') {
			$history[] = $this->encode(value: ['role' => 'user', 'content' => $input]);
		}

		foreach ($toolMessages as $toolMessage) {
			$history[] = $this->encode(value: ['role' => 'tool'] + $toolMessage);
		}

		$history[] = $this->encode(
			value: [
				'role' => 'assistant',
				'content' => $text,
				'tool_calls' => array_map(
					static fn (array $call): array => ['id' => $call['id'], 'name' => $call['name'], 'args' => $call['args']],
					$toolCalls
				),
			]
		);

		return $history;
	}//end appendRound()

	/**
	 * The most useful error text of a failed task.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The error.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
	 */
	public function errorOf(Task $task): string {
		$error = null;
		if (method_exists($task, 'getUserFacingErrorMessage') === true) {
			$error = $task->getUserFacingErrorMessage();
		}

		if ($error === null || $error === '') {
			$error = $task->getErrorMessage();
		}

		if ($error === null || $error === '') {
			return 'status ' . $task->getStatus();
		}

		return $error;
	}//end errorOf()
}//end class
