<?php

/**
 * Unit tests for StreamYieldChannel (agent-engine-port).
 *
 * Covers the pure-forwarding contract of the ported channel: multiple callbacks
 * per event type in registration order, all four event kinds, and the
 * no-replay-on-late-registration rule.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SSE stream yield channel.
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
 */
class StreamYieldChannelTest extends TestCase {

	/**
	 * All three event kinds reach their registered callbacks with their payloads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testForwardsAllEventKinds(): void {
		$channel = new StreamYieldChannel();

		$tokens = [];
		$toolCalls = [];
		$results = [];

		$channel->onToken(function (string $delta) use (&$tokens): void {
			$tokens[] = $delta;
		});
		$channel->onToolCall(function (array $payload) use (&$toolCalls): void {
			$toolCalls[] = $payload;
		});
		$channel->onToolResult(function (array $payload) use (&$results): void {
			$results[] = $payload;
		});

		$channel->emitToken(delta: 'Hel');
		$channel->emitToken(delta: 'lo');
		$channel->emitToolCall(payload: ['toolId' => 't1', 'arguments' => ['a' => 1]]);
		$channel->emitToolResult(payload: ['toolId' => 't1', 'result' => ['ok' => true], 'isError' => false]);

		$this->assertSame(['Hel', 'lo'], $tokens);
		$this->assertCount(1, $toolCalls);
		$this->assertSame('t1', $toolCalls[0]['toolId']);
		$this->assertCount(1, $results);
		$this->assertFalse($results[0]['isError']);

	}//end testForwardsAllEventKinds()

	/**
	 * The channel exposes no heartbeat pub/sub: the SSE keepalive is the
	 * controller's own clock (`forwardWithHeartbeat()` plus the frame sent
	 * right after the headers), never a producer-side emit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testChannelExposesNoHeartbeatPubSub(): void {
		$this->assertFalse(
			condition: method_exists(StreamYieldChannel::class, 'onHeartbeat'),
			message: 'StreamYieldChannel must not expose a heartbeat consumer half.'
		);
		$this->assertFalse(
			condition: method_exists(StreamYieldChannel::class, 'emitHeartbeat'),
			message: 'StreamYieldChannel must not expose a heartbeat producer half.'
		);

	}//end testChannelExposesNoHeartbeatPubSub()

	/**
	 * Multiple callbacks per event type fire in registration order; a callback
	 * registered AFTER an emit only sees subsequent events (no replay).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-1
	 */
	public function testRegistrationOrderAndNoReplay(): void {
		$channel = new StreamYieldChannel();
		$seen = [];

		$channel->onToken(function (string $delta) use (&$seen): void {
			$seen[] = 'first:' . $delta;
		});
		$channel->onToken(function (string $delta) use (&$seen): void {
			$seen[] = 'second:' . $delta;
		});

		$channel->emitToken(delta: 'a');

		// Late registration: must NOT replay 'a'.
		$channel->onToken(function (string $delta) use (&$seen): void {
			$seen[] = 'late:' . $delta;
		});

		$channel->emitToken(delta: 'b');

		$this->assertSame(
			['first:a', 'second:a', 'first:b', 'second:b', 'late:b'],
			$seen
		);

	}//end testRegistrationOrderAndNoReplay()
}//end class
