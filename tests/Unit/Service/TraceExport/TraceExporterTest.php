<?php

/**
 * Unit tests for TraceExporter and OtlpPayloadBuilder.
 *
 * A run with a tool step becomes a root, a model and a tool span with the
 * OpenTelemetry generative AI attribute names and no arguments or results; with
 * redacted text a secret in the answer arrives masked the way RedactionService masks it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\TraceExport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\TraceExport;

use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
use OCA\Hermiq\Service\Redaction\AssignmentRedactor;
use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\FormBodyRedactor;
use OCA\Hermiq\Service\Redaction\PhoneRedactor;
use OCA\Hermiq\Service\Redaction\TokenMasker;
use OCA\Hermiq\Service\Redaction\VendorKeyRedactor;
use OCA\Hermiq\Service\RedactionService;
use OCA\Hermiq\Service\TraceExport\OtlpPayloadBuilder;
use OCA\Hermiq\Service\TraceExport\TraceExporter;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a run's trace carries, and what it never carries.
 */
#[CoversClass(TraceExporter::class)]
#[CoversClass(OtlpPayloadBuilder::class)]
#[UsesClass(RedactionService::class)]
#[UsesClass(AssignmentRedactor::class)]
#[UsesClass(CredentialRedactor::class)]
#[UsesClass(FormBodyRedactor::class)]
#[UsesClass(PhoneRedactor::class)]
#[UsesClass(TokenMasker::class)]
#[UsesClass(VendorKeyRedactor::class)]
final class TraceExporterTest extends TestCase {

	private GuardrailPolicyServiceInterface&MockObject $guardrails;

	/**
	 * A guardrail policy that leaves text as it is.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guardrails = $this->createMock(GuardrailPolicyServiceInterface::class);
		$this->guardrails->method('effectivePolicyFor')->willReturn(['outputFilters' => ['piiAction' => 'off']]);
		$this->guardrails->method('filterOutputValue')->willReturnArgument(1);
		$this->guardrails->method('withheldPlaceholder')->willReturn('[withheld by policy]');

	}//end setUp()

	/**
	 * The exporter over a real redaction service.
	 *
	 * @return TraceExporter
	 */
	private function exporter(): TraceExporter {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');
		$config->method('getSystemValueString')->willReturn('instance-secret');

		return new TraceExporter(guardrails: $this->guardrails, redaction: new RedactionService($config), config: $config);

	}//end exporter()

	/**
	 * A scheduled run's audit context with one tool step whose arguments and result hold text.
	 *
	 * @return array<string, mixed>
	 */
	private function runContext(): array {
		return [
			'status' => 'success',
			'agentId' => 'agent-1',
			'runId' => 'run-1',
			'runAsUser' => 'alice',
			'startedAt' => '2026-11-14T02:00:00+00:00',
			'endedAt' => '2026-11-14T02:00:05+00:00',
			'prompt' => 'Check the open cases. Key sk-proj-abcdefghijklmnopqrstuvwxyz0123456789ABCD',
			'summary' => 'Done. The key is sk-proj-abcdefghijklmnopqrstuvwxyz0123456789ABCD',
			'usage' => ['promptTokens' => 120, 'completionTokens' => 40, 'llmSeconds' => 2.5],
			'providerDisclosure' => ['provider' => 'openai', 'model' => 'gpt-4o', 'residency' => 'eu', 'feature' => 'agents'],
			'steps' => [
				[
					'seq' => 1,
					'type' => 'tool',
					'name' => 'hermiq.readFile',
					'startedAt' => '2026-11-14T02:00:01+00:00',
					'endedAt' => '2026-11-14T02:00:02+00:00',
					'durationMs' => 1000,
					'outcome' => 'ok',
					'arguments' => ['path' => '/secret/case.txt'],
					'result' => 'THE FILE TEXT',
				],
			],
		];

	}//end runContext()

	/**
	 * Metadata only: root, model and tool spans, and no prompt, answer, argument or result.
	 *
	 * @return void
	 */
	public function testARunWithAToolCallCarriesMetadataOnly(): void {
		$trace = $this->exporter()->fromRun(context: $this->runContext(), trigger: 'schedule', content: 'metadata');

		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trace['traceId']);
		self::assertSame('hermiq.run', $trace['root']['name']);
		self::assertSame('schedule', $trace['root']['attributes']['hermiq.trigger']);
		self::assertSame('agent-1', $trace['root']['attributes']['hermiq.agent.id']);
		self::assertSame(5000, $trace['root']['attributes']['hermiq.duration_ms']);
		self::assertSame(hash_hmac('sha256', 'alice', 'instance-secret'), $trace['root']['attributes']['hermiq.user.hash']);
		self::assertArrayNotHasKey('hermiq.input', $trace['root']['attributes']);
		self::assertArrayNotHasKey('hermiq.output', $trace['root']['attributes']);

		[$model, $tool] = $trace['children'];
		self::assertSame('chat gpt-4o', $model['name']);
		self::assertSame('openai', $model['attributes']['gen_ai.system']);
		self::assertSame('gpt-4o', $model['attributes']['gen_ai.request.model']);
		self::assertSame(120, $model['attributes']['gen_ai.usage.input_tokens']);
		self::assertSame(40, $model['attributes']['gen_ai.usage.output_tokens']);
		self::assertSame('eu', $model['attributes']['hermiq.residency']);
		self::assertSame(2500000000, ($model['end'] - $model['start']));

		self::assertSame('hermiq.readFile', $tool['name']);
		self::assertSame('hermiq.tool', $tool['attributes']['hermiq.step.type']);
		self::assertSame('ok', $tool['attributes']['hermiq.step.outcome']);
		self::assertSame('ok', $tool['status']);

		$encoded = (string)json_encode($trace);
		self::assertStringNotContainsString('THE FILE TEXT', $encoded);
		self::assertStringNotContainsString('/secret/case.txt', $encoded);
		self::assertStringNotContainsString('open cases', $encoded);
		self::assertStringNotContainsString('alice', $encoded);

	}//end testARunWithAToolCallCarriesMetadataOnly()

	/**
	 * Redacted text: the answer's API key arrives masked, as RedactionService masks it.
	 *
	 * @return void
	 */
	public function testRedactedTextHidesASecret(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');
		$expected = (new RedactionService($config))->redact('Done. The key is sk-proj-abcdefghijklmnopqrstuvwxyz0123456789ABCD');

		$trace = $this->exporter()->fromRun(context: $this->runContext(), trigger: 'schedule', content: 'redacted-text');

		self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', $expected);
		self::assertSame($expected, $trace['root']['attributes']['hermiq.output']);
		self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', $trace['root']['attributes']['hermiq.input']);

	}//end testRedactedTextHidesASecret()

	/**
	 * Text passes the guardrail output filter first, is capped, and a filter that fails withholds it.
	 *
	 * @return void
	 */
	public function testTextIsFilteredCappedAndWithheldWhenTheFilterFails(): void {
		$guardrails = $this->createMock(GuardrailPolicyServiceInterface::class);
		$guardrails->method('effectivePolicyFor')->with('org-1')->willReturn(['outputFilters' => ['piiAction' => 'block']]);
		$guardrails->method('filterOutputValue')->willReturnCallback(
			static fn (array $policy, mixed $value): string => ($value === 'blocked answer') ? '[withheld by policy]' : str_repeat('x', 5000)
		);
		$config = $this->createMock(IConfig::class);
		$exporter = new TraceExporter(guardrails: $guardrails, redaction: new RedactionService($config), config: $config);

		$trace = $exporter->fromChatTurn(turn: ['prompt' => 'long', 'message' => 'blocked answer', 'startedAt' => 10.0, 'endedAt' => 11.5], content: 'redacted-text', organisation: 'org-1');

		self::assertSame('[withheld by policy]', $trace['root']['attributes']['hermiq.output']);
		self::assertSame(TraceExporter::TEXT_CAP, mb_strlen($trace['root']['attributes']['hermiq.input']));
		self::assertSame('chat', $trace['root']['attributes']['hermiq.trigger']);
		self::assertSame(1500, $trace['root']['attributes']['hermiq.duration_ms']);

		$failing = $this->createMock(GuardrailPolicyServiceInterface::class);
		$failing->method('effectivePolicyFor')->willThrowException(new RuntimeException('no policy'));
		$failing->method('withheldPlaceholder')->willReturn('[withheld by policy]');
		$withheld = (new TraceExporter(guardrails: $failing, redaction: new RedactionService($config)))
			->fromChatTurn(turn: ['message' => 'answer'], content: 'redacted-text');
		self::assertSame('[withheld by policy]', $withheld['root']['attributes']['hermiq.output']);

	}//end testTextIsFilteredCappedAndWithheldWhenTheFilterFails()

	/**
	 * A failed run and a failed step are error spans; a run without a model call has no model span.
	 *
	 * @return void
	 */
	public function testFailuresAreErrorSpans(): void {
		$trace = $this->exporter()->fromRun(
			context: ['status' => 'failed', 'steps' => [['type' => 'guardrail', 'name' => 'Output filter', 'outcome' => 'blocked']]],
			trigger: 'flow',
			content: 'metadata'
		);

		self::assertSame('error', $trace['root']['status']);
		self::assertCount(1, $trace['children']);
		self::assertSame('hermiq.guardrail', $trace['children'][0]['attributes']['hermiq.step.type']);
		self::assertSame('error', $trace['children'][0]['status']);

	}//end testFailuresAreErrorSpans()

	/**
	 * The OTLP body: one resource, the root without a parent, children pointing at it, typed values.
	 *
	 * @return void
	 */
	public function testTheOtlpBodyLinksChildrenToTheirRoot(): void {
		$trace = $this->exporter()->fromRun(context: $this->runContext(), trigger: 'schedule', content: 'metadata');

		$body = (new OtlpPayloadBuilder())->build(traces: [$trace], resource: ['hermiq.organisation' => 'org-1', 'hermiq.instance' => '']);

		$resource = $body['resourceSpans'][0]['resource']['attributes'];
		self::assertSame([['key' => 'service.name', 'value' => ['stringValue' => 'hermiq']], ['key' => 'hermiq.organisation', 'value' => ['stringValue' => 'org-1']]], $resource);

		$spans = $body['resourceSpans'][0]['scopeSpans'][0]['spans'];
		self::assertCount(3, $spans);
		self::assertArrayNotHasKey('parentSpanId', $spans[0]);
		self::assertSame($trace['traceId'], $spans[0]['traceId']);
		self::assertSame($spans[0]['spanId'], $spans[1]['parentSpanId']);
		self::assertSame($spans[0]['spanId'], $spans[2]['parentSpanId']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spans[2]['spanId']);
		self::assertSame((string)$trace['root']['start'], $spans[0]['startTimeUnixNano']);
		self::assertSame(OtlpPayloadBuilder::STATUS_OK, $spans[0]['status']['code']);

		$attributes = array_column($spans[1]['attributes'], 'value', 'key');
		self::assertSame(['intValue' => '120'], $attributes['gen_ai.usage.input_tokens']);
		self::assertSame(['stringValue' => 'gpt-4o'], $attributes['gen_ai.request.model']);

		$failed = (new OtlpPayloadBuilder())->build(traces: [['root' => ['name' => 'r', 'status' => 'error', 'attributes' => ['ok' => true, 'ratio' => 0.5, 'list' => [1]]]]]);
		$root = $failed['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
		self::assertSame(OtlpPayloadBuilder::STATUS_ERROR, $root['status']['code']);
		self::assertSame([['key' => 'ok', 'value' => ['boolValue' => true]], ['key' => 'ratio', 'value' => ['doubleValue' => 0.5]]], $root['attributes']);

	}//end testTheOtlpBodyLinksChildrenToTheirRoot()
}//end class
