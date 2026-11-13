<?php

/**
 * Characterisation of FacadeToolInvoker's single dispatch chokepoint: the
 * frames it emits around a facade call, the trace step and its outcome
 * (including an override after an approval), and a result that cannot be
 * encoded.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
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
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\FacadeCall;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The dispatch chokepoint.
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */
class FacadeToolInvokerDispatchTest extends TestCase {

	/**
	 * Frames emitted, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $frames = [];

	/**
	 * Dispatch one call through an invoker whose facade answers with the envelope.
	 *
	 * @param array<string, mixed> $envelope        The facade's answer.
	 * @param string|null          $outcomeOverride The trace outcome override.
	 * @param RunTraceCollector    $trace           The trace.
	 *
	 * @return string The encoded result.
	 */
	private function dispatch(array $envelope, ?string $outcomeOverride, RunTraceCollector $trace): string {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->expects($this->once())->method('invokeTool')->with('demo_list', ['limit' => 2])->willReturn($envelope);

		$channel = new StreamYieldChannel();
		$channel->onToolCall(fn (array $payload) => $this->frames[] = ['call', $payload]);
		$channel->onToolResult(fn (array $payload) => $this->frames[] = ['result', $payload]);

		$invoker = new FacadeToolInvoker(facade: $facade, channel: $channel, trace: $trace);
		$method = new ReflectionMethod($invoker, 'dispatchToFacade');
		$method->setAccessible(true);

		return $method->invoke($invoker, 'demo_list', new FacadeCall(arguments: ['limit' => 2], outcomeOverride: $outcomeOverride));
	}//end dispatch()

	/**
	 * A successful call: a call frame, one `ok` step, a result frame, the encoded result.
	 *
	 * @return void
	 */
	public function testASuccessfulCall(): void {
		$trace = new RunTraceCollector();

		$encoded = $this->dispatch(envelope: ['result' => ['items' => [1]], 'isError' => false], outcomeOverride: null, trace: $trace);

		$this->assertSame('{"items":[1]}', $encoded);
		$this->assertSame(
			[
				['call', ['toolId' => 'demo_list', 'arguments' => ['limit' => 2]]],
				['result', ['toolId' => 'demo_list', 'result' => ['items' => [1]], 'isError' => false]],
			],
			$this->frames
		);
		$this->assertSame('ok', $trace->toArray()[0]['outcome']);
	}//end testASuccessfulCall()

	/**
	 * The outcome override applies to a successful call only.
	 *
	 * @return void
	 */
	public function testTheOutcomeOverrideAppliesToASuccessOnly(): void {
		$approved = new RunTraceCollector();
		$this->dispatch(envelope: ['result' => [], 'isError' => false], outcomeOverride: 'invoked_after_approval', trace: $approved);
		$this->assertSame('invoked_after_approval', $approved->toArray()[0]['outcome']);

		$failed = new RunTraceCollector();
		$this->dispatch(envelope: ['result' => ['error' => 'x'], 'isError' => true], outcomeOverride: 'invoked_after_approval', trace: $failed);
		$this->assertSame('error', $failed->toArray()[0]['outcome']);
	}//end testTheOutcomeOverrideAppliesToASuccessOnly()

	/**
	 * A result that cannot be encoded answers a fixed error string.
	 *
	 * @return void
	 */
	public function testAnUnencodableResult(): void {
		$encoded = $this->dispatch(envelope: ['result' => ['value' => INF], 'isError' => false], outcomeOverride: null, trace: new RunTraceCollector());

		$this->assertSame('{"error":"Tool result could not be encoded"}', $encoded);
		$this->assertCount(2, $this->frames);
	}//end testAnUnencodableResult()
}//end class
