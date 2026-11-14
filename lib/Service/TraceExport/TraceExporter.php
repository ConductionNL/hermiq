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
		$root = ['start' => $start, 'end' => $this->nanos(value: ($context['endedAt'] ?? null)), 'attributes' => $this->runAttributes(context: $context, trigger: $trigger)];
		$text = ['user' => ($context['runAsUser'] ?? ''), 'input' => ($context['prompt'] ?? ''), 'output' => ($context['summary'] ?? '')];

		return $this->assemble(root: $root, source: $text + ['content' => $content, 'organisation' => $organisation] + $context);

	}//end fromRun()

	/**
	 * The trace of a chat turn, from what the engine recorded for it.
	 *
	 * @param array<string, mixed> $turn         The turn: agentId, conversationId, userId, startedAt, endedAt (seconds),
	 *                                           providerDisclosure, usage, steps, and prompt and message for text.
	 * @param string               $content      `metadata` or `redacted-text`.
	 * @param string               $organisation The organisation the turn belongs to, '' when none.
	 *
	 * @return array<string, mixed> The trace record.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-2-the-exporter
	 */
	public function fromChatTurn(array $turn, string $content, string $organisation = ''): array {
		$attributes = [
			'hermiq.trigger' => 'chat',
			'hermiq.agent.id' => (string)($turn['agentId'] ?? ''),
			'hermiq.conversation.id' => (string)($turn['conversationId'] ?? ''),
			'hermiq.status' => 'ok',
		];
		$root = ['start' => $this->seconds(value: ($turn['startedAt'] ?? null)), 'end' => $this->seconds(value: ($turn['endedAt'] ?? null)), 'attributes' => $attributes];
		$text = ['user' => ($turn['userId'] ?? ''), 'input' => ($turn['prompt'] ?? ''), 'output' => ($turn['message'] ?? '')];

		return $this->assemble(root: $root, source: $text + ['content' => $content, 'organisation' => $organisation] + $turn);

	}//end fromChatTurn()

	/**
	 * The attributes of a run's root span that come from its audit context.
	 *
	 * @param array<string, mixed> $context The run's audit context.
	 * @param string               $trigger What started the run.
	 *
	 * @return array<string, mixed> The attributes.
	 */
	private function runAttributes(array $context, string $trigger): array {
		return [
			'hermiq.trigger' => $trigger,
			'hermiq.agent.id' => (string)($context['agentId'] ?? ''),
			'hermiq.run.id' => (string)($context['runId'] ?? ($context['correlationId'] ?? '')),
			'hermiq.status' => (string)($context['status'] ?? ''),
		];

	}//end runAttributes()

	/**
	 * The trace record: the root span completed with its duration, user hash and text,
	 * and its child spans.
	 *
	 * @param array<string, mixed> $root   The root's start, end (nanoseconds) and attributes.
	 * @param array<string, mixed> $source user, input, output, content, organisation,
	 *                                     providerDisclosure, usage and steps.
	 *
	 * @return array<string, mixed> The trace record.
	 */
	private function assemble(array $root, array $source): array {
		$start = $root['start'];
		$end = max($start, $root['end']);
		$attributes = $root['attributes'] + ['hermiq.duration_ms' => (int)(($end - $start) / 1000000)];
		$attributes += $this->userAttribute(uid: (string)$source['user']);
		$attributes += $this->textAttributes(source: $source);
		$status = $this->statusOf(status: (string)$attributes['hermiq.status']);

		return $this->trace(
			root: ['name' => 'hermiq.run', 'start' => $start, 'end' => $end, 'status' => $status, 'attributes' => $attributes],
			source: $source
		);

	}//end assemble()

	/**
	 * The prompt and answer text attributes, only with `redacted-text`.
	 *
	 * @param array<string, mixed> $source input, output, content and organisation.
	 *
	 * @return array<string, string> The attributes, none for `metadata`.
	 */
	private function textAttributes(array $source): array {
		if ($source['content'] !== 'redacted-text') {
			return [];
		}

		$organisation = (string)$source['organisation'];

		return [
			'hermiq.input' => $this->clean(text: (string)$source['input'], organisation: $organisation),
			'hermiq.output' => $this->clean(text: (string)$source['output'], organisation: $organisation),
		];

	}//end textAttributes()

	/**
	 * One trace record: the root span, the model span when anything is known about
	 * the model call, and a span per recorded step.
	 *
	 * @param array<string, mixed> $root   The root span.
	 * @param array<string, mixed> $source providerDisclosure, usage and steps.
	 *
	 * @return array<string, mixed> The trace record.
	 */
	private function trace(array $root, array $source): array {
		$children = [];
		$model = $this->modelSpan(root: $root, source: $source);
		if ($model !== null) {
			$children[] = $model;
		}

		foreach ((array)($source['steps'] ?? []) as $step) {
			if (is_array($step) === true) {
				$children[] = $this->stepSpan(step: $step, root: $root);
			}
		}

		return ['traceId' => bin2hex(random_bytes(16)), 'root' => $root, 'children' => $children];

	}//end trace()

	/**
	 * The model call span, with the GenAI semantic convention attributes.
	 *
	 * @param array<string, mixed> $root   The root span.
	 * @param array<string, mixed> $source providerDisclosure (model, provider, residency, feature) and usage.
	 *
	 * @return array<string, mixed>|null The span, or null when nothing is known about the call.
	 */
	private function modelSpan(array $root, array $source): ?array {
		$disclosure = (array)($source['providerDisclosure'] ?? []);
		$usage = (array)($source['usage'] ?? []);
		$model = (string)($disclosure['model'] ?? '');
		if ($model === '' && (string)($disclosure['provider'] ?? '') === '' && $usage === []) {
			return null;
		}

		return [
			'name' => trim('chat ' . $model),
			'start' => $this->modelStart(root: $root, usage: $usage),
			'end' => $root['end'],
			'status' => 'ok',
			'attributes' => $this->modelAttributes(disclosure: $disclosure, usage: $usage),
		];

	}//end modelSpan()

	/**
	 * The GenAI attributes of the model call span.
	 *
	 * @param array<string, mixed> $disclosure The provider disclosure.
	 * @param array<string, mixed> $usage      The token usage.
	 *
	 * @return array<string, mixed> The attributes.
	 */
	private function modelAttributes(array $disclosure, array $usage): array {
		$attributes = [
			'gen_ai.operation.name' => 'chat',
			'gen_ai.system' => (string)($disclosure['provider'] ?? ''),
			'gen_ai.request.model' => (string)($disclosure['model'] ?? ''),
		];
		$attributes += $this->tokenAttributes(usage: $usage);
		foreach (['residency', 'feature'] as $field) {
			if ((string)($disclosure[$field] ?? '') !== '') {
				$attributes['hermiq.' . $field] = (string)$disclosure[$field];
			}
		}

		return $attributes;

	}//end modelAttributes()

	/**
	 * The token counts of the model call, under either naming the providers use.
	 *
	 * @param array<string, mixed> $usage The token usage.
	 *
	 * @return array<string, integer> The attributes, only for counts that are known.
	 */
	private function tokenAttributes(array $usage): array {
		$attributes = [];
		$input = ($usage['promptTokens'] ?? ($usage['inputTokens'] ?? null));
		$output = ($usage['completionTokens'] ?? ($usage['outputTokens'] ?? null));
		if (is_numeric($input) === true) {
			$attributes['gen_ai.usage.input_tokens'] = (int)$input;
		}

		if (is_numeric($output) === true) {
			$attributes['gen_ai.usage.output_tokens'] = (int)$output;
		}

		return $attributes;

	}//end tokenAttributes()

	/**
	 * When the model call started: the root's end less the recorded model time, never
	 * before the root's start.
	 *
	 * @param array<string, mixed> $root  The root span.
	 * @param array<string, mixed> $usage The token usage, with llmSeconds when recorded.
	 *
	 * @return integer The start in nanoseconds.
	 */
	private function modelStart(array $root, array $usage): int {
		if (is_numeric($usage['llmSeconds'] ?? null) === false) {
			return $root['start'];
		}

		return max($root['start'], ($root['end'] - (int)round((float)$usage['llmSeconds'] * 1000000000)));

	}//end modelStart()

	/**
	 * A tool, guardrail or context step span: its name, type, timing and outcome only.
	 *
	 * @param array<string, mixed> $step One run trace entry.
	 * @param array<string, mixed> $root The root span, whose start stands in for a missing time.
	 *
	 * @return array<string, mixed> The span.
	 */
	private function stepSpan(array $step, array $root): array {
		$start = $this->nanos(value: ($step['startedAt'] ?? null), fallback: $root['start']);
		$type = (string)($step['type'] ?? 'step');

		return [
			'name' => (string)($step['name'] ?? $type),
			'start' => $start,
			'end' => max($start, $this->nanos(value: ($step['endedAt'] ?? null), fallback: $start)),
			'status' => $this->statusOf(status: (string)($step['outcome'] ?? 'ok')),
			'attributes' => $this->stepAttributes(step: $step, type: $type),
		];

	}//end stepSpan()

	/**
	 * A step span's attributes: its type and the allowed scalar fields.
	 *
	 * @param array<string, mixed> $step One run trace entry.
	 * @param string               $type The step's type.
	 *
	 * @return array<string, mixed> The attributes.
	 */
	private function stepAttributes(array $step, string $type): array {
		$attributes = ['hermiq.step.type' => 'hermiq.' . $type];
		foreach (self::STEP_FIELDS as $field) {
			if (isset($step[$field]) === true && is_scalar($step[$field]) === true) {
				$attributes['hermiq.step.' . strtolower($field)] = $step[$field];
			}
		}

		return $attributes;

	}//end stepAttributes()

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

		$filtered = $this->filtered(text: $text, organisation: $organisation);
		if ($filtered === null) {
			return $this->guardrails->withheldPlaceholder();
		}

		return mb_substr($this->redaction->redact($filtered), 0, self::TEXT_CAP);

	}//end clean()

	/**
	 * Text after the organisation's output filter, as a string.
	 *
	 * @param string $text         The prompt or answer.
	 * @param string $organisation The organisation whose output filter applies.
	 *
	 * @return string|null The filtered text, or null when the filter cannot be read.
	 */
	private function filtered(string $text, string $organisation): ?string {
		try {
			$filtered = $this->guardrails->filterOutputValue(
				policy: $this->guardrails->effectivePolicyFor(organisation: $organisation),
				value: $text
			);
		} catch (Throwable) {
			return null;
		}

		if (is_string($filtered) === false) {
			$filtered = (string)json_encode($filtered, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		return $filtered;

	}//end filtered()

	/**
	 * A timestamp as unix nanoseconds.
	 *
	 * @param mixed $value    An ISO 8601 string, unix seconds, or nothing.
	 * @param int   $fallback The value when there is no usable timestamp; now when 0.
	 *
	 * @return int Unix nanoseconds.
	 */
	private function nanos(mixed $value, int $fallback = 0): int {
		$nanos = $this->parsed(value: $value);
		if ($nanos !== null) {
			return $nanos;
		}

		if ($fallback !== 0) {
			return $fallback;
		}

		return (int)round(microtime(true) * 1000000000);

	}//end nanos()

	/**
	 * A timestamp as unix nanoseconds, when it is one.
	 *
	 * @param mixed $value An ISO 8601 string, unix seconds, or nothing.
	 *
	 * @return int|null Unix nanoseconds, or null when the value is no usable timestamp.
	 */
	private function parsed(mixed $value): ?int {
		if (is_numeric($value) === true) {
			return (int)round((float)$value * 1000000000);
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			$time = new DateTimeImmutable($value);
		} catch (Throwable) {
			return null;
		}

		return ((int)$time->format('U') * 1000000000) + ((int)$time->format('u') * 1000);

	}//end parsed()

	/**
	 * A chat turn's time, unix seconds as recorded by the engine, as unix nanoseconds.
	 *
	 * @param mixed $value Unix seconds, or nothing for now.
	 *
	 * @return int Unix nanoseconds.
	 */
	private function seconds(mixed $value): int {
		return (int)round(((float)($value ?? microtime(true))) * 1000000000);

	}//end seconds()
}//end class
