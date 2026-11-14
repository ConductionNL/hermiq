<?php

/**
 * Hermiq Trace Exporter.
 *
 * Turns a finished run or chat turn into one trace: a root span per run or turn, a span
 * for the model call, and a span per tool, guardrail or context step, built from what
 * hermiq already records (the audit context and the run trace). Tool arguments and
 * results are never copied; prompt and answer text only with `redacted-text`, and then
 * after the organisation's output filter and `RedactionService::redact()`, capped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\TraceExport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-traces-carry-metadata-by-default-and-never-tool-arguments-or-results-req-trex-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\TraceExport;

use DateTimeImmutable;
use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
use OCA\Hermiq\Service\RedactionService;
use OCP\IConfig;
use Throwable;

/**
 * Build trace records (root span plus children) from runs and chat turns.
 */
class TraceExporter {

	/**
	 * The longest prompt or answer text exported, in characters.
	 *
	 * @var integer
	 */
	public const TEXT_CAP = 4000;

	/**
	 * What a step span may carry, besides its timing: never arguments or results.
	 *
	 * @var list<string>
	 */
	private const STEP_FIELDS = ['outcome', 'durationMs', 'seq'];

	/**
	 * Constructor.
	 *
	 * @param GuardrailPolicyServiceInterface $guardrails The organisation's output filter.
	 * @param RedactionService                $redaction  Masks secrets and personal data.
	 * @param IConfig|null                    $config     The instance config; its secret keys the user hash.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GuardrailPolicyServiceInterface $guardrails,
		private readonly RedactionService $redaction,
		private readonly ?IConfig $config = null,
	) {

	}//end __construct()

	/**
	 * The trace of a finished scheduled, flow or webhook run, from its audit context.
	 *
	 * @param array<string, mixed> $context      The run's audit context.
	 * @param string               $trigger      `schedule`, `flow`, `webhook` or `skill`.
	 * @param string               $content      `metadata` or `redacted-text`.
	 * @param string               $organisation The organisation the run belongs to, '' when none.
	 *
	 * @return array<string, mixed> The trace record.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-2-the-exporter
	 */
	public function fromRun(array $context, string $trigger, string $content, string $organisation = ''): array {
		$start = $this->nanos(value: ($context['startedAt'] ?? null));
		$end = max($start, $this->nanos(value: ($context['endedAt'] ?? null)));

		$attributes = [
			'hermiq.trigger' => $trigger,
			'hermiq.agent.id' => (string)($context['agentId'] ?? ''),
			'hermiq.run.id' => (string)($context['runId'] ?? ($context['correlationId'] ?? '')),
			'hermiq.status' => (string)($context['status'] ?? ''),
			'hermiq.duration_ms' => (int)(($end - $start) / 1000000),
		];
		$attributes += $this->userAttribute(uid: (string)($context['runAsUser'] ?? ''));
		if ($content === 'redacted-text') {
			$attributes['hermiq.input'] = $this->clean(text: (string)($context['prompt'] ?? ''), organisation: $organisation);
			$attributes['hermiq.output'] = $this->clean(text: (string)($context['summary'] ?? ''), organisation: $organisation);
		}

		$status = $this->statusOf(status: $attributes['hermiq.status']);

		return $this->trace(
			root: $this->span(name: 'hermiq.run', start: $start, end: $end, status: $status, attributes: $attributes),
			disclosure: (array)($context['providerDisclosure'] ?? []),
			usage: (array)($context['usage'] ?? []),
			steps: (array)($context['steps'] ?? [])
		);

	}//end fromRun()

	/**
	 * The trace of a finished chat turn, from the engine's result envelope.
	 *
	 * @param array<string, mixed> $turn         The turn: `conversationId`, `userId`, `agentId`,
	 *                                           `prompt`, `startedAt` (unix seconds as float) and
	 *                                           the envelope's `message`, `usage` and `steps`.
	 * @param string               $content      `metadata` or `redacted-text`.
	 * @param string               $organisation The organisation of the turn, '' when none.
	 *
	 * @return array<string, mixed> The trace record.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-2-the-exporter
	 */
	public function fromChatTurn(array $turn, string $content, string $organisation = ''): array {
		$start = (int)round(((float)($turn['startedAt'] ?? microtime(true))) * 1000000000);
		$end = max($start, (int)round(((float)($turn['endedAt'] ?? microtime(true))) * 1000000000));

		$attributes = [
			'hermiq.trigger' => 'chat',
			'hermiq.agent.id' => (string)($turn['agentId'] ?? ''),
			'hermiq.conversation.id' => (string)($turn['conversationId'] ?? ''),
			'hermiq.status' => 'ok',
			'hermiq.duration_ms' => (int)(($end - $start) / 1000000),
		];
		$attributes += $this->userAttribute(uid: (string)($turn['userId'] ?? ''));
		if ($content === 'redacted-text') {
			$attributes['hermiq.input'] = $this->clean(text: (string)($turn['prompt'] ?? ''), organisation: $organisation);
			$attributes['hermiq.output'] = $this->clean(text: (string)($turn['message'] ?? ''), organisation: $organisation);
		}

		return $this->trace(
			root: $this->span(name: 'hermiq.run', start: $start, end: $end, status: 'ok', attributes: $attributes),
			disclosure: (array)($turn['providerDisclosure'] ?? []),
			usage: (array)($turn['usage'] ?? []),
			steps: (array)($turn['steps'] ?? [])
		);

	}//end fromChatTurn()

	/**
	 * Assemble a trace from its root span, the model call and the steps.
	 *
	 * @param array<string, mixed> $root       The root span.
	 * @param array<string, mixed> $disclosure The provider disclosure (provider, model, residency, feature).
	 * @param array<string, mixed> $usage      The token usage the driver reported.
	 * @param array<int, mixed>    $steps      The run trace's steps.
	 *
	 * @return array<string, mixed> The trace record.
	 */
	private function trace(array $root, array $disclosure, array $usage, array $steps): array {
		$children = [];
		$model = $this->modelSpan(root: $root, disclosure: $disclosure, usage: $usage);
		if ($model !== null) {
			$children[] = $model;
		}

		foreach ($steps as $step) {
			if (is_array($step) === true) {
				$children[] = $this->stepSpan(step: $step, root: $root);
			}
		}

		return [
			'traceId' => bin2hex(random_bytes(16)),
			'root' => $root,
			'children' => $children,
		];

	}//end trace()

	/**
	 * The model call's span, with OpenTelemetry's generative AI attribute names.
	 *
	 * @param array<string, mixed> $root       The root span, whose end the call shares.
	 * @param array<string, mixed> $disclosure The provider disclosure.
	 * @param array<string, mixed> $usage      The token usage.
	 *
	 * @return array<string, mixed>|null The span, or null when nothing names a model call.
	 */
	private function modelSpan(array $root, array $disclosure, array $usage): ?array {
		$model = (string)($disclosure['model'] ?? '');
		$provider = (string)($disclosure['provider'] ?? '');
		if ($model === '' && $provider === '' && $usage === []) {
			return null;
		}

		$attributes = [
			'gen_ai.operation.name' => 'chat',
			'gen_ai.system' => $provider,
			'gen_ai.request.model' => $model,
		];
		$input = ($usage['promptTokens'] ?? ($usage['inputTokens'] ?? null));
		$output = ($usage['completionTokens'] ?? ($usage['outputTokens'] ?? null));
		if (is_numeric($input) === true) {
			$attributes['gen_ai.usage.input_tokens'] = (int)$input;
		}

		if (is_numeric($output) === true) {
			$attributes['gen_ai.usage.output_tokens'] = (int)$output;
		}

		foreach (['residency', 'feature'] as $field) {
			if ((string)($disclosure[$field] ?? '') !== '') {
				$attributes['hermiq.' . $field] = (string)$disclosure[$field];
			}
		}

		$start = $root['start'];
		if (is_numeric($usage['llmSeconds'] ?? null) === true) {
			$start = max($root['start'], ($root['end'] - (int)round((float)$usage['llmSeconds'] * 1000000000)));
		}

		return $this->span(name: trim('chat ' . $model), start: $start, end: $root['end'], status: 'ok', attributes: $attributes);

	}//end modelSpan()

	/**
	 * A step's span: its type, name, outcome and timing, and nothing else.
	 *
	 * @param array<string, mixed> $step The run trace step.
	 * @param array<string, mixed> $root The root span, for a step without timing.
	 *
	 * @return array<string, mixed> The span.
	 */
	private function stepSpan(array $step, array $root): array {
		$start = $this->nanos(value: ($step['startedAt'] ?? null), fallback: $root['start']);
		$end = max($start, $this->nanos(value: ($step['endedAt'] ?? null), fallback: $start));
		$type = (string)($step['type'] ?? 'step');

		$attributes = ['hermiq.step.type' => 'hermiq.' . $type];
		foreach (self::STEP_FIELDS as $field) {
			if (isset($step[$field]) === true && is_scalar($step[$field]) === true) {
				$attributes['hermiq.step.' . strtolower($field)] = $step[$field];
			}
		}

		$outcome = (string)($step['outcome'] ?? 'ok');

		return $this->span(
			name: (string)($step['name'] ?? $type),
			start: $start,
			end: $end,
			status: $this->statusOf(status: $outcome),
			attributes: $attributes
		);

	}//end stepSpan()

	/**
	 * One span.
	 *
	 * @param string               $name       The span name.
	 * @param int                  $start      Start, unix nanoseconds.
	 * @param int                  $end        End, unix nanoseconds.
	 * @param string               $status     `ok` or `error`.
	 * @param array<string, mixed> $attributes Scalar attributes.
	 *
	 * @return array<string, mixed>
	 */
	private function span(string $name, int $start, int $end, string $status, array $attributes): array {
		return [
			'name' => $name,
			'start' => $start,
			'end' => $end,
			'status' => $status,
			'attributes' => $attributes,
		];

	}//end span()

	/**
	 * Whether a run status or step outcome is an error.
	 *
	 * @param string $status The status or outcome.
	 *
	 * @return string `error` or `ok`.
	 */
	private function statusOf(string $status): string {
		$status = strtolower($status);
		foreach (['error', 'fail', 'denied', 'blocked', 'refused', 'timeout'] as $word) {
			if (str_contains($status, $word) === true) {
				return 'error';
			}
		}

		return 'ok';

	}//end statusOf()

	/**
	 * The keyed hash a user id is exported as, so one person's runs group without naming them.
	 *
	 * @param string $uid The user id.
	 *
	 * @return array<string, string> The attribute, or none when there is no user.
	 */
	private function userAttribute(string $uid): array {
		if ($uid === '') {
			return [];
		}

		return ['hermiq.user.hash' => hash_hmac('sha256', $uid, (string)($this->config?->getSystemValueString('secret', '') ?? ''))];

	}//end userAttribute()

	/**
	 * Text as it may leave the server: through the output filter, redacted and capped.
	 *
	 * A filter that cannot be read withholds the text rather than send it unfiltered.
	 *
	 * @param string $text         The prompt or answer.
	 * @param string $organisation The organisation whose output filter applies.
	 *
	 * @return string The exportable text.
	 *
	 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#scenario-redacted-text-hides-a-secret
	 */
	private function clean(string $text, string $organisation): string {
		if ($text === '') {
			return '';
		}

		try {
			$filtered = $this->guardrails->filterOutputValue(
				policy: $this->guardrails->effectivePolicyFor(organisation: $organisation),
				value: $text
			);
		} catch (Throwable) {
			return $this->guardrails->withheldPlaceholder();
		}

		if (is_string($filtered) === false) {
			$filtered = (string)json_encode($filtered, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		return mb_substr($this->redaction->redact($filtered), 0, self::TEXT_CAP);

	}//end clean()

	/**
	 * A timestamp as unix nanoseconds.
	 *
	 * @param mixed $value    An ISO 8601 string, unix seconds, or nothing.
	 * @param int   $fallback The value when there is no usable timestamp; now when 0.
	 *
	 * @return int Unix nanoseconds.
	 */
	private function nanos(mixed $value, int $fallback = 0): int {
		if (is_numeric($value) === true) {
			return (int)round((float)$value * 1000000000);
		}

		if (is_string($value) === true && $value !== '') {
			try {
				$time = new DateTimeImmutable($value);
				return ((int)$time->format('U') * 1000000000) + ((int)$time->format('u') * 1000);
			} catch (Throwable) {
				// Unparseable: fall through to the fallback.
			}
		}

		if ($fallback !== 0) {
			return $fallback;
		}

		return (int)round(microtime(true) * 1000000000);

	}//end nanos()
}//end class
