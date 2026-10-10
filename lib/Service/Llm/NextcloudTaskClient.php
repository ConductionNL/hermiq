<?php

/**
 * Hermiq Nextcloud AI (TaskProcessing) client.
 *
 * Sends hermiq's LLM work to the AI provider an administrator set up for the whole
 * instance, under Administration settings > Artificial intelligence. Hermiq holds no key
 * and calls no model API on this path: every call is an `OCP\TaskProcessing\Task` run as
 * the user hermiq works for, the same pipeline Nextcloud Assistant uses.
 *
 * - chat without tools: `core:text2text:chat`;
 * - agent turns with tools: `core:text2text:chatwithtools`, with hermiq running the tool
 *   loop so every call still goes through its governed executor;
 * - plain generation: `core:text2text`.
 *
 * Sync and async. A synchronous provider (integration_openai's are) runs inline through
 * `IManager::runTask()`. Any other provider is scheduled and waited on with a bound,
 * because `runTask()` polls an asynchronous provider without one. On timeout the task is
 * cancelled and the call fails with a clear error, never with a silent fallback.
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
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Runs hermiq's LLM calls as Nextcloud TaskProcessing tasks.
 *
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
 */
class NextcloudTaskClient {

	/**
	 * The admin-facing message when no Nextcloud provider serves a task type hermiq needs.
	 *
	 * @var string
	 */
	public const ADMIN_MESSAGE = 'Configure an AI provider under Administration settings > Artificial intelligence.';

	/**
	 * How long an interactive chat waits for an asynchronous provider, in seconds.
	 *
	 * @var integer
	 */
	public const TIMEOUT_INTERACTIVE = 120;

	/**
	 * How long background work waits for an asynchronous provider, in seconds.
	 *
	 * @var integer
	 */
	public const TIMEOUT_BACKGROUND = 600;

	/**
	 * The task types hermiq consumes, by the name the admin page shows them under.
	 *
	 * @var array<string, string>
	 */
	public const CONSUMED_TASK_TYPES = [
		'chat' => TextToTextChat::ID,
		'chatWithTools' => TextToTextChatWithTools::ID,
		'text' => TextToText::ID,
	];

	/**
	 * Default ceiling on tool calls in one turn, when the agent sets none.
	 *
	 * @var integer
	 */
	private const MAX_TOOL_CALLS = 10;

	/**
	 * Whether the last chat() stopped because the turn used every tool call it may make.
	 *
	 * @var boolean
	 */
	private bool $lastCallHitToolCap = false;

	/**
	 * Constructor.
	 *
	 * @param IManager $taskManager Nextcloud's TaskProcessing manager.
	 * @param LoggerInterface $logger Logger.
	 * @param ITimeFactory|null $timeFactory Clock for the bounded wait; null uses time().
	 * @param IL10N|null $l10n Translates the admin message; null leaves it in English.
	 * @param string $appName The app id a task is created under.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
	 */
	public function __construct(
		private readonly IManager $taskManager,
		private readonly LoggerInterface $logger,
		private readonly ?ITimeFactory $timeFactory = null,
		private readonly ?IL10N $l10n = null,
		private readonly string $appName = 'hermiq',
	) {
	}//end __construct()

	/**
	 * The admin-facing message, translated when a translator is available.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-3-1
	 */
	public function adminMessage(): string {
		if ($this->l10n === null) {
			return self::ADMIN_MESSAGE;
		}

		return $this->l10n->t('Configure an AI provider under Administration settings > Artificial intelligence.');
	}//end adminMessage()

	/**
	 * Whether a Nextcloud provider serves the task type for this user.
	 *
	 * @param string $taskTypeId The task type id.
	 * @param string|null $userId The user the task would run as.
	 *
	 * @return boolean True when a provider is available.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-3-1
	 */
	public function isAvailable(string $taskTypeId, ?string $userId = null): bool {
		try {
			return in_array($taskTypeId, $this->taskManager->getAvailableTaskTypeIds(false, $userId), true);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[NextcloudTaskClient] Could not list the available task types',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end isAvailable()

	/**
	 * For each task type hermiq consumes, whether a provider serves it and which one.
	 *
	 * @return array<string, array{taskType: string, available: bool, provider: string}>
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-5-1
	 */
	public function availability(): array {
		$result = [];
		foreach (self::CONSUMED_TASK_TYPES as $key => $taskTypeId) {
			$available = $this->isAvailable(taskTypeId: $taskTypeId);
			$providerName = '';
			if ($available === true) {
				try {
					$providerName = (string)$this->taskManager->getPreferredProvider($taskTypeId)->getName();
				} catch (Throwable) {
					$providerName = '';
				}
			}

			$result[$key] = ['taskType' => $taskTypeId, 'available' => $available, 'provider' => $providerName];
		}

		return $result;
	}//end availability()

	/**
	 * Whether the last chat() stopped at the agent's tool-call cap.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function lastCallHitToolCap(): bool {
		return $this->lastCallHitToolCap;
	}//end lastCallHitToolCap()

	/**
	 * Generate text from one prompt (`core:text2text`).
	 *
	 * @param string $prompt The prompt.
	 * @param string|null $userId The user hermiq works for.
	 * @param integer $timeoutSeconds How long to wait for an asynchronous provider.
	 * @param string $customId Optional correlation id.
	 *
	 * @return string The generated text.
	 *
	 * @throws ProviderUnavailableException When no provider serves the type, the task fails,
	 *                                      or it returns no output.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-3
	 */
	public function generate(string $prompt, ?string $userId, int $timeoutSeconds = self::TIMEOUT_BACKGROUND, string $customId = ''): string {
		$output = $this->run(
			taskTypeId: TextToText::ID,
			input: ['input' => $prompt],
			userId: $userId,
			timeoutSeconds: $timeoutSeconds,
			customId: $customId
		);

		$text = $output['output'] ?? null;
		if (is_string($text) === false || $text === '') {
			throw new ProviderUnavailableException('Nextcloud AI returned no output.', 502);
		}

		return $text;
	}//end generate()

	/**
	 * Run one chat turn: `core:text2text:chat`, or `core:text2text:chatwithtools` when
	 * the turn has tools and an executor to run them.
	 *
	 * The history's system turns become `system_prompt`, its last user turn becomes
	 * `input`, and every turn in between becomes a JSON `{role, content}` history entry.
	 *
	 * @param array $messageHistory LLPhant messages, system first and the user turn last.
	 * @param string|null $userId The user hermiq works for.
	 * @param array $functions OpenAI-style function definitions.
	 * @param callable|null $toolExecutor Runs one tool: fn(string $name, array $args): string.
	 * @param integer|null $maxToolCalls The agent's tool-call cap; null uses the default.
	 * @param integer $timeoutSeconds How long to wait for an asynchronous provider.
	 *
	 * @return string The reply.
	 *
	 * @throws ProviderUnavailableException When no provider serves the type, a task fails,
	 *                                      or the wait runs out.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	public function chat(
		array $messageHistory,
		?string $userId,
		array $functions = [],
		?callable $toolExecutor = null,
		?int $maxToolCalls = null,
		int $timeoutSeconds = self::TIMEOUT_INTERACTIVE,
	): string {
		$this->lastCallHitToolCap = false;
		$split = $this->splitHistory(messageHistory: $messageHistory);

		if ($functions === [] || $toolExecutor === null) {
			$output = $this->run(
				taskTypeId: TextToTextChat::ID,
				input: [
					'system_prompt' => $split['system'],
					'input' => $split['input'],
					'history' => $split['history'],
				],
				userId: $userId,
				timeoutSeconds: $timeoutSeconds
			);

			return (string)($output['output'] ?? '');
		}

		// A turn with tools never degrades quietly: without a chatwithtools provider the
		// agent's tools cannot run, and running it without them would look healthy.
		if ($this->isAvailable(taskTypeId: TextToTextChatWithTools::ID, userId: $userId) === false) {
			throw new ProviderUnavailableException(
				'Nextcloud AI has no provider for chat with tools, so this agent\'s tools cannot run. ' . $this->adminMessage(),
				503
			);
		}

		return $this->chatWithTools(
			split: $split,
			userId: $userId,
			tools: $this->buildTools(functions: $functions),
			toolExecutor: $toolExecutor,
			cap: ($maxToolCalls ?? self::MAX_TOOL_CALLS),
			timeoutSeconds: $timeoutSeconds
		);
	}//end chat()

	/**
	 * The chatwithtools loop: run the turn, execute each tool call, send the results back,
	 * until the model answers without tool calls or the cap is reached.
	 *
	 * @param array{system: string, input: string, history: list<string>} $split The mapped history.
	 * @param string|null $userId The user hermiq works for.
	 * @param string $tools The tools JSON.
	 * @param callable $toolExecutor Runs one tool.
	 * @param integer $cap The tool-call cap.
	 * @param integer $timeoutSeconds How long to wait per task.
	 *
	 * @return string The reply.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-2
	 */
	private function chatWithTools(array $split, ?string $userId, string $tools, callable $toolExecutor, int $cap, int $timeoutSeconds): string {
		$history = $split['history'];
		$input = $split['input'];
		$toolMessages = [];
		$executed = 0;
		$text = '';

		// At most one task per allowed tool call plus the closing one.
		for ($iteration = 0; $iteration <= $cap; $iteration++) {
			$output = $this->run(
				taskTypeId: TextToTextChatWithTools::ID,
				input: [
					'system_prompt' => $split['system'],
					'input' => $input,
					'history' => $history,
					'tools' => $tools,
					'tool_message' => $this->encodeList(list: $toolMessages),
				],
				userId: $userId,
				timeoutSeconds: $timeoutSeconds
			);
			$text = (string)($output['output'] ?? '');
			$toolCalls = $this->parseToolCalls(raw: $output['tool_calls'] ?? '');

			if ($toolCalls === []) {
				break;
			}

			if ($executed >= $cap) {
				$this->lastCallHitToolCap = true;
				break;
			}

			// What was sent this round moves into the history: the user's turn, the tool
			// results of the previous round, then the assistant turn that asked for tools.
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

			$input = '';
			$toolMessages = [];
			foreach ($toolCalls as $toolCall) {
				$result = '{"isError":true,"result":{"error":"Tool call limit reached for this turn"}}';
				if ($executed < $cap) {
					$result = (string)$toolExecutor($toolCall['name'], $toolCall['args']);
					$executed++;
				}

				$toolMessages[] = ['tool_call_id' => $toolCall['id'], 'name' => $toolCall['name'], 'content' => $result];
			}
		}//end for

		if ($this->lastCallHitToolCap === true && trim($text) === '') {
			$text = 'Tool call limit reached for this turn.';
		}

		return $text;
	}//end chatWithTools()

	/**
	 * Run one task as the given user and return its output.
	 *
	 * @param string $taskTypeId The task type id.
	 * @param array<string, mixed> $input The task input.
	 * @param string|null $userId The user the task runs as.
	 * @param integer $timeoutSeconds How long to wait for an asynchronous provider.
	 * @param string $customId Optional correlation id.
	 *
	 * @return array<string, mixed> The task output.
	 *
	 * @throws ProviderUnavailableException When no provider serves the type, the preferred
	 *                                      provider is hermiq's own, the task fails, or the
	 *                                      wait runs out.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-4-2
	 */
	public function run(string $taskTypeId, array $input, ?string $userId, int $timeoutSeconds, string $customId = ''): array {
		if ($this->isAvailable(taskTypeId: $taskTypeId, userId: $userId) === false) {
			throw new ProviderUnavailableException($this->adminMessage(), 503);
		}

		try {
			$provider = $this->taskManager->getPreferredProvider($taskTypeId);
		} catch (Throwable $e) {
			throw new ProviderUnavailableException($this->adminMessage(), 503, $e);
		}

		// Hermiq providing a type it consumes would route its own request back to itself.
		if (str_starts_with((string)$provider->getId(), $this->appName . ':') === true) {
			throw new ProviderUnavailableException(
				"The preferred provider for {$taskTypeId} is hermiq itself, which would loop. " . $this->adminMessage(),
				508
			);
		}

		$task = new Task($taskTypeId, $input, $this->appName, $userId, $customId);

		try {
			if ($provider instanceof ISynchronousProvider) {
				$task = $this->taskManager->runTask($task);
			} else {
				$this->taskManager->scheduleTask($task);
				$task = $this->waitFor(task: $task, timeoutSeconds: $timeoutSeconds);
			}
		} catch (ProviderUnavailableException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[NextcloudTaskClient] Nextcloud AI task could not run',
				context: ['file' => __FILE__, 'line' => __LINE__, 'taskType' => $taskTypeId, 'error' => $e->getMessage()]
			);
			throw new ProviderUnavailableException('Nextcloud AI task could not run: ' . $e->getMessage(), 503, $e);
		}

		if ($task->getStatus() !== Task::STATUS_SUCCESSFUL) {
			throw new ProviderUnavailableException('Nextcloud AI task failed: ' . $this->errorOf(task: $task), 502);
		}

		return ($task->getOutput() ?? []);
	}//end run()

	/**
	 * Wait for a scheduled task to finish, at most the given time; cancel it on timeout.
	 *
	 * @param Task $task The scheduled task.
	 * @param integer $timeoutSeconds The bound.
	 *
	 * @return Task The finished task.
	 *
	 * @throws ProviderUnavailableException When the bound runs out.
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-1
	 */
	private function waitFor(Task $task, int $timeoutSeconds): Task {
		$taskId = (int)$task->getId();
		$deadline = $this->now() + $timeoutSeconds;

		while (true) {
			$task = $this->taskManager->getTask($taskId);
			$status = $task->getStatus();
			if ($status !== Task::STATUS_SCHEDULED && $status !== Task::STATUS_RUNNING) {
				return $task;
			}

			if ($this->now() >= $deadline) {
				try {
					$this->taskManager->cancelTask($taskId);
				} catch (Throwable $e) {
					$this->logger->info(
						message: '[NextcloudTaskClient] Could not cancel a timed-out task',
						context: ['file' => __FILE__, 'line' => __LINE__, 'taskId' => $taskId, 'error' => $e->getMessage()]
					);
				}

				throw new ProviderUnavailableException(
					"The AI provider did not answer within {$timeoutSeconds} seconds. " . $this->adminMessage(),
					504
				);
			}

			$this->pause();
		}//end while
	}//end waitFor()

	/**
	 * The current time in seconds.
	 *
	 * @return integer
	 */
	protected function now(): int {
		if ($this->timeFactory === null) {
			return time();
		}

		return $this->timeFactory->getTime();
	}//end now()

	/**
	 * Wait one second between polls. Overridden in tests.
	 *
	 * @return void
	 */
	protected function pause(): void {
		sleep(1);
	}//end pause()

	/**
	 * The most useful error text of a failed task.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The error.
	 */
	private function errorOf(Task $task): string {
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
	 */
	private function encode(array $value): string {
		return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end encode()

	/**
	 * JSON-encode a list, or the empty string for an empty one.
	 *
	 * @param array $list The list.
	 *
	 * @return string The JSON, or ''.
	 */
	private function encodeList(array $list): string {
		if ($list === []) {
			return '';
		}

		return $this->encode(value: array_values($list));
	}//end encodeList()
}//end class
