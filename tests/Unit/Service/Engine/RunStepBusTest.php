<?php

/**
 * Unit tests for RunStepBus — a cli turn's tool calls, made visible.
 *
 * 🔴 The property these tests exist for: a tool that runs in a SEPARATE HTTP
 * request still reaches the conversation's step list. The engine's in-process
 * tool calls always did; a governed `cli` turn's did not, and a turn that made
 * five tool calls appeared to the user as one silent minute.
 *
 * The second property is that this bus can never break the thing it observes.
 * Steps are display material with a one-turn life, so every failure — no cache
 * configured, a cache that throws, junk in the bucket — degrades to "no steps"
 * rather than to an error on the tool call.
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
 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\RunStepBus;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the cross-request step bus.
 *
 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
 */
class RunStepBusTest extends TestCase {
	/**
	 * An in-memory stand-in for the distributed cache.
	 *
	 * A real double rather than a mock with expectations: these tests are about
	 * what comes BACK OUT of the bus across separate calls, which is exactly
	 * what a mock asserting on `set()` arguments cannot show.
	 *
	 * @return ICache The cache double.
	 */
	private function cache(): ICache {
		return new class implements ICache {
			/**
			 * The stored values.
			 *
			 * @var array<string, mixed>
			 */
			public array $store = [];

			/**
			 * Read one value.
			 *
			 * @param string $key The key.
			 *
			 * @return mixed The value, or null.
			 */
			public function get($key): mixed {
				return ($this->store[$key] ?? null);
			}

			/**
			 * Write one value.
			 *
			 * @param string $key   The key.
			 * @param mixed  $value The value.
			 * @param int    $ttl   Seconds to live.
			 *
			 * @return bool Always true.
			 */
			public function set($key, $value, $ttl = 0): bool {
				$this->store[$key] = $value;
				return true;
			}

			/**
			 * Whether a key is present.
			 *
			 * @param string $key The key.
			 *
			 * @return bool True when present.
			 */
			public function hasKey($key): bool {
				return array_key_exists($key, $this->store);
			}

			/**
			 * Drop one key.
			 *
			 * @param string $key The key.
			 *
			 * @return bool Always true.
			 */
			public function remove($key): bool {
				unset($this->store[$key]);
				return true;
			}

			/**
			 * Drop everything matching a prefix.
			 *
			 * @param string $prefix The prefix.
			 *
			 * @return bool Always true.
			 */
			public function clear($prefix = ''): bool {
				$this->store = [];
				return true;
			}

			/**
			 * Whether this cache backend can be used.
			 *
			 * @return bool Always true — the "no cache" case is exercised by
			 *              failing `createDistributed()`, which is how the
			 *              absence actually reaches the bus.
			 */
			public static function isAvailable(): bool {
				return true;
			}
		};
	}//end cache()

	/**
	 * Build a bus over a given cache, or over none at all.
	 *
	 * @param ICache|null $cache The cache to serve, or null to fail creation.
	 *
	 * @return RunStepBus The bus.
	 */
	private function bus(?ICache $cache): RunStepBus {
		$factory = $this->createMock(ICacheFactory::class);
		if ($cache === null) {
			$factory->method('createDistributed')
				->willThrowException(new RuntimeException('no cache configured'));
		} else {
			$factory->method('createDistributed')->willReturn($cache);
		}

		return new RunStepBus(cacheFactory: $factory);
	}//end bus()

	/**
	 * A recorded step comes back out against its conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-a-governed-tool-call-appears-as-a-step
	 */
	public function testARecordedStepIsReadableBack(): void {
		$bus = $this->bus($this->cache());
		$bus->record(
			conversationId: 'conv-1',
			toolName: 'openregister.contact.search',
			arguments: ['query' => 'ann'],
			result: 'two matches',
			durationMs: 42
		);

		$steps = $bus->read(conversationId: 'conv-1');

		$this->assertCount(1, $steps);
		$this->assertSame('openregister.contact.search', $steps[0]['name']);
		$this->assertSame(['query' => 'ann'], $steps[0]['arguments']);
		$this->assertSame('two matches', $steps[0]['result']);
		$this->assertSame(42, $steps[0]['durationMs']);
		$this->assertSame('ok', $steps[0]['outcome']);
		$this->assertFalse($steps[0]['truncated']);
	}//end testARecordedStepIsReadableBack()

	/**
	 * Steps from one conversation never appear in another.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-a-governed-tool-call-appears-as-a-step
	 */
	public function testStepsAreScopedToTheirConversation(): void {
		$bus = $this->bus($this->cache());
		$bus->record(conversationId: 'conv-1', toolName: 'a', arguments: [], result: '', durationMs: 1);
		$bus->record(conversationId: 'conv-2', toolName: 'b', arguments: [], result: '', durationMs: 1);

		$this->assertCount(1, $bus->read(conversationId: 'conv-1'));
		$this->assertSame('b', $bus->read(conversationId: 'conv-2')[0]['name']);
		$this->assertSame([], $bus->read(conversationId: 'conv-3'));
	}//end testStepsAreScopedToTheirConversation()

	/**
	 * `agentId` is stamped in by the transport, so it is not shown back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-a-governed-tool-call-appears-as-a-step
	 */
	public function testTheTransportsOwnArgumentIsNotEchoedBack(): void {
		$bus = $this->bus($this->cache());
		$bus->record(
			conversationId: 'conv-1',
			toolName: 'a',
			arguments: ['agentId' => 'agent-1', 'query' => 'ann'],
			result: '',
			durationMs: 1
		);

		$this->assertSame(['query' => 'ann'], $bus->read(conversationId: 'conv-1')[0]['arguments']);
	}//end testTheTransportsOwnArgumentIsNotEchoedBack()

	/**
	 * A long result is truncated, and SAYS that it was.
	 *
	 * Truncating silently would let a step claim to show a result it only shows
	 * the first 600 characters of.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-a-governed-tool-call-appears-as-a-step
	 */
	public function testALongResultIsTruncatedAndSaysSo(): void {
		$bus = $this->bus($this->cache());
		$bus->record(
			conversationId: 'conv-1',
			toolName: 'a',
			arguments: [],
			result: str_repeat('x', 900),
			durationMs: 1
		);

		$step = $bus->read(conversationId: 'conv-1')[0];

		$this->assertSame(600, mb_strlen($step['result']));
		$this->assertTrue($step['truncated']);
	}//end testALongResultIsTruncatedAndSaysSo()

	/**
	 * Draining empties the bucket, so the next turn starts clean.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-steps-do-not-outlive-their-turn
	 */
	public function testDrainReturnsTheStepsAndEmptiesTheBucket(): void {
		$bus = $this->bus($this->cache());
		$bus->record(conversationId: 'conv-1', toolName: 'a', arguments: [], result: '', durationMs: 1);

		$this->assertCount(1, $bus->drain(conversationId: 'conv-1'));
		$this->assertSame([], $bus->read(conversationId: 'conv-1'));
		$this->assertSame([], $bus->drain(conversationId: 'conv-1'));
	}//end testDrainReturnsTheStepsAndEmptiesTheBucket()

	/**
	 * The bucket is capped, and recording past the cap drops rather than throws.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
	 */
	public function testTheBucketIsCapped(): void {
		$bus = $this->bus($this->cache());
		for ($i = 0; $i < 60; $i++) {
			$bus->record(conversationId: 'conv-1', toolName: 'a', arguments: [], result: '', durationMs: 1);
		}

		$this->assertCount(50, $bus->read(conversationId: 'conv-1'));
	}//end testTheBucketIsCapped()

	/**
	 * With no cache configured the bus is a silent no-op.
	 *
	 * Not an error: an instance without a distributed cache shows no steps,
	 * exactly as it did before this class existed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-steps-do-not-outlive-their-turn
	 */
	public function testWithoutACacheEverythingDegradesToNothing(): void {
		$bus = $this->bus(null);
		$bus->record(conversationId: 'conv-1', toolName: 'a', arguments: [], result: '', durationMs: 1);

		$this->assertSame([], $bus->read(conversationId: 'conv-1'));
		$this->assertSame([], $bus->drain(conversationId: 'conv-1'));
		$bus->clear(conversationId: 'conv-1');
	}//end testWithoutACacheEverythingDegradesToNothing()

	/**
	 * An empty conversation id is refused rather than given its own bucket.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
	 */
	public function testAnEmptyConversationIdRecordsNothing(): void {
		$cache = $this->cache();
		$bus = $this->bus($cache);
		$bus->record(conversationId: '', toolName: 'a', arguments: [], result: '', durationMs: 1);

		$this->assertSame([], $bus->read(conversationId: ''));
		$this->assertSame([], $cache->store);
	}//end testAnEmptyConversationIdRecordsNothing()

	/**
	 * Junk in the bucket reads as no steps, not as a crash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-a-cli-turns-tool-calls-are-visible-in-the-chat
	 */
	public function testUnreadableContentReadsAsNoSteps(): void {
		$cache = $this->cache();
		$bus = $this->bus($cache);
		$bus->record(conversationId: 'conv-1', toolName: 'a', arguments: [], result: '', durationMs: 1);

		// Overwrite the bucket with something that is not a step list.
		$key = array_key_first($cache->store);
		$cache->store[$key] = 'not json';

		$this->assertSame([], $bus->read(conversationId: 'conv-1'));
	}//end testUnreadableContentReadsAsNoSteps()

	/**
	 * An explicit outcome is stored verbatim, so a failed call reads as failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#scenario-a-governed-tool-call-appears-as-a-step
	 */
	public function testAFailedCallIsRecordedAsFailed(): void {
		$bus = $this->bus($this->cache());
		$bus->record(
			conversationId: 'conv-1',
			toolName: 'a',
			arguments: [],
			result: 'boom',
			durationMs: 3,
			outcome: 'error'
		);

		$this->assertSame('error', $bus->read(conversationId: 'conv-1')[0]['outcome']);
	}//end testAFailedCallIsRecordedAsFailed()
}//end class
