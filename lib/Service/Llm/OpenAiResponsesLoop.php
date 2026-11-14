<?php

/**
 * Hermiq OpenAiResponsesLoop.
 *
 * One turn against the OpenAI Responses API (`POST {base}/responses`), for the
 * OpenAI and OpenAI-compatible providers in `apiMode: responses`
 * (models-provider-connections D4). The system blocks become `instructions`,
 * the history becomes `input`, the turn's tools become function tools, and
 * `store` is always false so the provider keeps no conversation state.
 *
 * Every `function_call` output item runs through the executor the caller
 * gives, which is the same governed executor the Anthropic path uses
 * (grants, guardrails, approval gate, redaction, trace), and returns as a
 * `function_call_output` item, until the model answers in text or the turn's
 * tool call cap is used. Hosted tools and other output the loop cannot run
 * are refused with a clear error.
 *
 * The transport is the caller's: a closure that posts one payload and returns
 * the decoded body, so the broker path and the keyless path share this loop.
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
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use Closure;
use LLPhant\Chat\Enums\ChatRole;
use LLPhant\Chat\Message as LLPhantMessage;
use stdClass;

/**
 * The Responses API request, its tool loop and its answer.
 *
 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
 */
final class OpenAiResponsesLoop {

	/**
	 * Output item types the loop reads past without acting on them.
	 *
	 * @var array<int,string>
	 */
	private const PASSIVE_ITEMS = ['message', 'reasoning'];

	/**
	 * Tokens over every request of the turn.
	 *
	 * @var array<string,int>
	 */
	private array $usage = [];

	/**
	 * Whether the tool call cap ended the turn.
	 *
	 * @var boolean
	 */
	private bool $hitToolCap = false;

	/**
	 * Constructor.
	 *
	 * @param Closure      $post         Posts one payload, returns the decoded body: fn(array): array.
	 * @param Closure|null $toolExecutor Runs a requested tool: fn(string $name, array $input): string;
	 *                                   null for a turn without tools.
	 * @param int          $maxToolCalls Tool calls the turn may make.
	 */
	public function __construct(
		private readonly Closure $post,
		private readonly ?Closure $toolExecutor,
		private readonly int $maxToolCalls,
	) {
	}//end __construct()

	/**
	 * The first request of a turn.
	 *
	 * @param string                    $model          The model.
	 * @param array<int,LLPhantMessage> $messageHistory The system prompt, the history and the user turn.
	 * @param array<int,mixed>          $functions      The turn's tools (`name`, `description`, `parameters`).
	 * @param int|null                  $maxTokens      The agent's output ceiling, or null.
	 *
	 * @return array<string,mixed> The payload.
	 *
	 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
	 */
	public function payload(string $model, array $messageHistory, array $functions, ?int $maxTokens): array {
		$instructions = [];
		$input = [];
		foreach ($messageHistory as $message) {
			if ($message instanceof LLPhantMessage === false) {
				continue;
			}

			if ($message->role === ChatRole::System) {
				$instructions[] = (string)$message->content;
				continue;
			}

			$input[] = $this->inputItem(message: $message);
		}

		$payload = [
			'model' => $model,
			'input' => $input,
			'store' => false,
		];
		if ($instructions !== []) {
			$payload['instructions'] = implode("\n\n", $instructions);
		}

		$tools = $this->tools(functions: $functions);
		if ($tools !== [] && $this->toolExecutor !== null) {
			$payload['tools'] = $tools;
		}

		if ($maxTokens !== null && $maxTokens > 0) {
			$payload['max_output_tokens'] = $maxTokens;
		}

		return $payload;
	}//end payload()

	/**
	 * Run the turn: post, run each function call the model asks for, post the
	 * results back, until the model answers in text.
	 *
	 * @param array<string,mixed> $payload The first request, from payload().
	 *
	 * @return string The answer text.
	 *
	 * @throws ProviderUnavailableException When the answer asks for something the loop cannot run.
	 *
	 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
	 */
	public function run(array $payload): string {
		$this->usage = [];
		$this->hitToolCap = false;
		$calls = 0;

		while (true) {
			$body = ($this->post)($payload);
			$this->addUsage(body: $body);
			$output = $body['output'] ?? [];
			if (is_array($output) === false) {
				$output = [];
			}

			$functionCalls = $this->functionCalls(output: $output);
			if ($functionCalls === []) {
				return $this->answerText(output: $output);
			}

			if ($this->toolExecutor === null) {
				throw new ProviderUnavailableException('The model asked for a tool on a turn without tools.', 502);
			}

			if ($calls + count($functionCalls) > $this->maxToolCalls) {
				$this->hitToolCap = true;
				return $this->answerText(output: $output);
			}

			foreach ($functionCalls as $call) {
				$calls++;
				$payload['input'][] = $call;
				$payload['input'][] = [
					'type' => 'function_call_output',
					'call_id' => (string)($call['call_id'] ?? ''),
					'output' => ($this->toolExecutor)((string)($call['name'] ?? ''), $this->arguments(call: $call)),
				];
			}
		}//end while
	}//end run()

	/**
	 * The tokens of every request of the last turn.
	 *
	 * @return array<string,int> `promptTokens` and `completionTokens`, or [] when none were reported.
	 *
	 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
	 */
	public function usage(): array {
		return $this->usage;
	}//end usage()

	/**
	 * Whether the tool call cap ended the last turn.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004
	 */
	public function hitToolCap(): bool {
		return $this->hitToolCap;
	}//end hitToolCap()

	/**
	 * One history message as an input item.
	 *
	 * @param LLPhantMessage $message The message.
	 *
	 * @return array<string,mixed>
	 */
	private function inputItem(LLPhantMessage $message): array {
		$role = 'user';
		$textType = 'input_text';
		if ($message->role === ChatRole::Assistant) {
			$role = 'assistant';
			$textType = 'output_text';
		}

		$content = [['type' => $textType, 'text' => (string)$message->content]];
		if ($message instanceof AttachmentMessage) {
			foreach ($message->parts as $part) {
				$content[] = $this->attachmentPart(part: $part);
			}
		}

		return ['role' => $role, 'content' => $content];
	}//end inputItem()

	/**
	 * One attachment as an input content part.
	 *
	 * @param array{kind:string,name:string,mimeType:string,base64:string} $part The attachment.
	 *
	 * @return array<string,string>
	 */
	private function attachmentPart(array $part): array {
		$dataUrl = 'data:' . $part['mimeType'] . ';base64,' . $part['base64'];
		if ($part['kind'] === ModelCapabilityRegistry::CAPABILITY_IMAGE) {
			return ['type' => 'input_image', 'image_url' => $dataUrl];
		}

		return ['type' => 'input_file', 'filename' => $part['name'], 'file_data' => $dataUrl];
	}//end attachmentPart()

	/**
	 * The turn's tools as Responses API function tools.
	 *
	 * @param array<int,mixed> $functions The turn's tools.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function tools(array $functions): array {
		$tools = [];
		foreach ($functions as $function) {
			if (is_array($function) === false || isset($function['name']) === false) {
				continue;
			}

			$schema = $function['parameters'] ?? null;
			if (is_array($schema) === false) {
				$schema = ['type' => 'object'];
			}

			// An argument-less tool's empty properties must serialise as {}, not [].
			if (isset($schema['properties']) === false || $schema['properties'] === []) {
				$schema['properties'] = new stdClass();
			}

			$tools[] = [
				'type' => 'function',
				'name' => (string)$function['name'],
				'description' => (string)($function['description'] ?? ''),
				'parameters' => $schema,
			];
		}//end foreach

		return $tools;
	}//end tools()

	/**
	 * The function calls of an answer; anything the loop cannot run is refused.
	 *
	 * @param array<int,mixed> $output The answer's output items.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ProviderUnavailableException On a hosted tool or another item the loop cannot run.
	 */
	private function functionCalls(array $output): array {
		$calls = [];
		foreach ($output as $item) {
			$type = '';
			if (is_array($item) === true) {
				$type = (string)($item['type'] ?? '');
			}

			if ($type === 'function_call') {
				$calls[] = $item;
				continue;
			}

			if (in_array($type, self::PASSIVE_ITEMS, true) === false) {
				throw new ProviderUnavailableException(
					'The model answered with "' . $type . '", which Hermiq does not run. Only function tools are sent in Responses API mode.',
					502
				);
			}
		}

		return $calls;
	}//end functionCalls()

	/**
	 * A function call's arguments.
	 *
	 * @param array<string,mixed> $call The call item.
	 *
	 * @return array<string,mixed> The decoded arguments, or [] when they are not a JSON object.
	 */
	private function arguments(array $call): array {
		$decoded = json_decode((string)($call['arguments'] ?? ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end arguments()

	/**
	 * The text of an answer's message items.
	 *
	 * @param array<int,mixed> $output The answer's output items.
	 *
	 * @return string
	 */
	private function answerText(array $output): string {
		$text = '';
		foreach ($output as $item) {
			if (is_array($item) === false || ($item['type'] ?? '') !== 'message') {
				continue;
			}

			foreach ((array)($item['content'] ?? []) as $part) {
				if (is_array($part) === true && ($part['type'] ?? '') === 'output_text') {
					$text .= (string)($part['text'] ?? '');
				}
			}
		}

		return $text;
	}//end answerText()

	/**
	 * Add one response's usage to the turn's total.
	 *
	 * @param array<string,mixed> $body The decoded response.
	 *
	 * @return void
	 */
	private function addUsage(array $body): void {
		$usage = $body['usage'] ?? [];
		if (is_array($usage) === false) {
			return;
		}

		$prompt = ((int)($this->usage['promptTokens'] ?? 0) + (int)($usage['input_tokens'] ?? 0));
		$completion = ((int)($this->usage['completionTokens'] ?? 0) + (int)($usage['output_tokens'] ?? 0));
		if (($prompt + $completion) > 0) {
			$this->usage = ['promptTokens' => $prompt, 'completionTokens' => $completion];
		}
	}//end addUsage()
}//end class
