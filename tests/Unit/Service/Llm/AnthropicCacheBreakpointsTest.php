<?php

/**
 * Where AnthropicCacheBreakpoints puts its three breakpoints.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\AnthropicCacheBreakpoints;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Llm\AnthropicCacheBreakpoints
 */
class AnthropicCacheBreakpointsTest extends TestCase {

	private const CC = ['type' => 'ephemeral'];

	/**
	 * How many breakpoints a request carries.
	 *
	 * @param array<string, mixed> $payload The request.
	 *
	 * @return int
	 */
	private static function breakpoints(array $payload): int {
		return substr_count((string)json_encode($payload), '"cache_control"');
	}//end breakpoints()

	public function testCachingIsOnUnlessSwitchedOffAndTheLifetimeIsOneOfTwo(): void {
		$this->assertNotNull(AnthropicCacheBreakpoints::fromConfig([]));
		$this->assertNotNull(AnthropicCacheBreakpoints::fromConfig(['promptCaching' => true]));
		$this->assertNotNull(AnthropicCacheBreakpoints::fromConfig(['promptCaching' => 'false']));
		$this->assertNull(AnthropicCacheBreakpoints::fromConfig(['promptCaching' => false]));

		$this->assertSame(self::CC, AnthropicCacheBreakpoints::fromConfig([])->cacheControl());
		$this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], AnthropicCacheBreakpoints::fromConfig(['promptCacheTtl' => '1h'])->cacheControl());
		$this->assertSame(self::CC, AnthropicCacheBreakpoints::fromConfig(['promptCacheTtl' => '2d'])->cacheControl());
	}

	public function testARequestWithToolsSystemAndHistoryCarriesThreeBreakpoints(): void {
		$payload = (new AnthropicCacheBreakpoints())->apply(
			[
				'model' => 'claude-x',
				'system' => 'You are the case assistant.',
				'tools' => [['name' => 'a'], ['name' => 'b']],
				'messages' => [
					['role' => 'user', 'content' => 'First question'],
					['role' => 'assistant', 'content' => 'First answer'],
					['role' => 'user', 'content' => 'Second question'],
				],
			]
		);

		$this->assertSame(3, self::breakpoints($payload));
		$this->assertSame(['name' => 'a'], $payload['tools'][0]);
		$this->assertSame(['name' => 'b', 'cache_control' => self::CC], $payload['tools'][1]);
		$this->assertSame([['type' => 'text', 'text' => 'You are the case assistant.', 'cache_control' => self::CC]], $payload['system']);
		$this->assertSame('First question', $payload['messages'][0]['content']);
		$this->assertSame([['type' => 'text', 'text' => 'First answer', 'cache_control' => self::CC]], $payload['messages'][1]['content']);
		$this->assertSame(['role' => 'user', 'content' => 'Second question'], $payload['messages'][2]);
		$this->assertSame('claude-x', $payload['model']);
	}

	public function testInAToolRoundTheConversationBreakpointSitsOnTheLastToolResult(): void {
		$payload = (new AnthropicCacheBreakpoints())->apply(
			[
				'messages' => [
					['role' => 'user', 'content' => 'List my cases'],
					['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'a'], ['type' => 'tool_use', 'id' => 'b']]],
					['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'a'], ['type' => 'tool_result', 'tool_use_id' => 'b'], ['type' => 'text', 'text' => 'note']]],
				],
			]
		);

		$this->assertSame(1, self::breakpoints($payload));
		$this->assertSame(self::CC, $payload['messages'][2]['content'][1]['cache_control']);
		$this->assertArrayNotHasKey('cache_control', $payload['messages'][2]['content'][0]);
		$this->assertArrayNotHasKey('cache_control', $payload['messages'][1]['content'][1]);
	}

	public function testABlockListedSystemHasItsStableFirstBlockMarked(): void {
		$payload = (new AnthropicCacheBreakpoints(ttl: '1h'))->apply(
			['system' => [['type' => 'text', 'text' => 'stable'], ['type' => 'text', 'text' => 'this turn']], 'messages' => []]
		);

		$this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $payload['system'][0]['cache_control']);
		$this->assertArrayNotHasKey('cache_control', $payload['system'][1]);
		$this->assertSame([], $payload['messages']);
	}

	public function testNothingToMarkLeavesTheRequestAsItIs(): void {
		$marker = new AnthropicCacheBreakpoints();

		$this->assertSame(['model' => 'm'], $marker->apply(['model' => 'm']));
		$this->assertSame(['system' => '', 'tools' => [], 'messages' => [['role' => 'user', 'content' => 'Hello']]], $marker->apply(['system' => '', 'tools' => [], 'messages' => [['role' => 'user', 'content' => 'Hello']]]));
		$this->assertSame(['messages' => ['x', ['role' => 'user', 'content' => 'Hi']]], $marker->apply(['messages' => ['x', ['role' => 'user', 'content' => 'Hi']]]));
		$this->assertSame(
			['messages' => [['role' => 'assistant', 'content' => ''], ['role' => 'user', 'content' => 'Hi']]],
			$marker->apply(['messages' => [['role' => 'assistant', 'content' => ''], ['role' => 'user', 'content' => 'Hi']]])
		);
		$this->assertSame(
			['messages' => [['role' => 'assistant', 'content' => ['plain']], ['role' => 'user', 'content' => 'Hi']]],
			$marker->apply(['messages' => [['role' => 'assistant', 'content' => ['plain']], ['role' => 'user', 'content' => 'Hi']]])
		);
		$this->assertSame(['system' => [], 'messages' => []], $marker->apply(['system' => [], 'messages' => []]));
	}
}
