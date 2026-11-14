<?php

/**
 * Hermiq OTLP Payload Builder.
 *
 * Turns trace records into the OTLP/HTTP JSON body of `ExportTraceServiceRequest`:
 * one resource (`service.name=hermiq`, the instance and the organisation) with one
 * scope whose spans are each trace's root and its children.
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

/**
 * Build the OTLP/HTTP JSON body for a batch of traces.
 */
class OtlpPayloadBuilder {

	/**
	 * OTLP status code for a span that completed.
	 *
	 * @var integer
	 */
	public const STATUS_OK = 1;

	/**
	 * OTLP status code for a span that failed.
	 *
	 * @var integer
	 */
	public const STATUS_ERROR = 2;

	/**
	 * OTLP span kind INTERNAL.
	 *
	 * @var integer
	 */
	private const KIND_INTERNAL = 1;

	/**
	 * The `ExportTraceServiceRequest` body for a batch.
	 *
	 * @param list<array<string, mixed>> $traces   The trace records (see TraceExporter).
	 * @param array<string, string>      $resource Extra resource attributes (instance, organisation).
	 *
	 * @return array<string, mixed> The JSON-encodable body.
	 *
	 * @spec openspec/changes/observability-export-traces/tasks.md#task-2-the-exporter
	 */
	public function build(array $traces, array $resource = []): array {
		$spans = [];
		foreach ($traces as $trace) {
			$traceId = (string)($trace['traceId'] ?? bin2hex(random_bytes(16)));
			$rootId = bin2hex(random_bytes(8));
			$spans[] = $this->span(span: (array)$trace['root'], traceId: $traceId, spanId: $rootId, parentId: '');
			foreach ((array)($trace['children'] ?? []) as $child) {
				$spans[] = $this->span(span: (array)$child, traceId: $traceId, spanId: bin2hex(random_bytes(8)), parentId: $rootId);
			}
		}

		$resourceAttributes = ['service.name' => 'hermiq'];
		foreach ($resource as $key => $value) {
			if ($value !== '') {
				$resourceAttributes[$key] = $value;
			}
		}

		return [
			'resourceSpans' => [
				[
					'resource' => ['attributes' => $this->attributes(values: $resourceAttributes)],
					'scopeSpans' => [
						[
							'scope' => ['name' => 'hermiq'],
							'spans' => $spans,
						],
					],
				],
			],
		];

	}//end build()

	/**
	 * One OTLP span.
	 *
	 * @param array<string, mixed> $span     The span record.
	 * @param string               $traceId  The 32-hex trace id.
	 * @param string               $spanId   The 16-hex span id.
	 * @param string               $parentId The parent's span id, '' for a root.
	 *
	 * @return array<string, mixed>
	 */
	private function span(array $span, string $traceId, string $spanId, string $parentId): array {
		$otlp = [
			'traceId' => $traceId,
			'spanId' => $spanId,
			'name' => (string)($span['name'] ?? 'hermiq.span'),
			'kind' => self::KIND_INTERNAL,
			// 64-bit nanoseconds travel as strings in OTLP JSON.
			'startTimeUnixNano' => (string)(int)($span['start'] ?? 0),
			'endTimeUnixNano' => (string)(int)($span['end'] ?? 0),
			'attributes' => $this->attributes(values: (array)($span['attributes'] ?? [])),
			'status' => ['code' => $this->statusCode(status: (string)($span['status'] ?? 'ok'))],
		];
		if ($parentId !== '') {
			$otlp['parentSpanId'] = $parentId;
		}

		return $otlp;

	}//end span()

	/**
	 * Attributes as OTLP key/value pairs. Non-scalar values are dropped.
	 *
	 * @param array<string, mixed> $values The attributes.
	 *
	 * @return list<array{key: string, value: array<string, mixed>}>
	 */
	private function attributes(array $values): array {
		$attributes = [];
		foreach ($values as $key => $value) {
			$typed = $this->value(value: $value);
			if ($typed !== null) {
				$attributes[] = ['key' => (string)$key, 'value' => $typed];
			}
		}

		return $attributes;

	}//end attributes()

	/**
	 * A scalar as an OTLP `AnyValue`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|null The typed value, or null for a non-scalar.
	 */
	private function value(mixed $value): ?array {
		if (is_bool($value) === true) {
			return ['boolValue' => $value];
		}

		if (is_int($value) === true) {
			return ['intValue' => (string)$value];
		}

		if (is_float($value) === true) {
			return ['doubleValue' => $value];
		}

		if (is_string($value) === true) {
			return ['stringValue' => $value];
		}

		return null;

	}//end value()

	/**
	 * The OTLP status code of a span status.
	 *
	 * @param string $status `ok` or `error`.
	 *
	 * @return int
	 */
	private function statusCode(string $status): int {
		if ($status === 'error') {
			return self::STATUS_ERROR;
		}

		return self::STATUS_OK;

	}//end statusCode()
}//end class
