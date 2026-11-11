<?php

/**
 * Hermiq Anthropic message format: the Messages API wire shapes.
 *
 * Pins how an LLPhant history, OpenAI-style function definitions, a decoded
 * Messages API response and governed tool results map onto Anthropic's shapes.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the Anthropic Messages API wire shapes.
 */
class AnthropicMessageFormatTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @return ProviderFactory
	 */
	private function format(): ProviderFactory {
		return new ProviderFactory(
			$this->createMock(LlmSettingsHandler::class),
			$this->createMock(IManager::class),
			$this->createMock(IUserSession::class),
			new NullLogger()
		);
	}//end format()

	/**
	 * System turns are joined into `system`; other turns pass through as text.
	 *
	 * @return void
	 */
	public function testHistorySplitsSystemFromMessages(): void {
		$mapped = $this->format()->mapHistoryToAnthropicMessages(
			[Message::system('Eén.'), Message::user('Vraag'), Message::system('Twee.'), Message::assistant('Antwoord')]
		);

		$this->assertSame("Eén.\n\nTwee.", $mapped['system']);
		$this->assertSame(
			[
				['role' => 'user', 'content' => 'Vraag'],
				['role' => 'assistant', 'content' => 'Antwoord'],
			],
			$mapped['messages']
		);
	}//end testHistorySplitsSystemFromMessages()

	/**
	 * An empty history maps to an empty system prompt and no messages.
	 *
	 * @return void
	 */
	public function testEmptyHistory(): void {
		$this->assertSame(['system' => '', 'messages' => []], $this->format()->mapHistoryToAnthropicMessages([]));
	}//end testEmptyHistory()

	/**
	 * Nameless and non-array definitions are skipped; a missing schema or type is filled in.
	 *
	 * @return void
	 */
	public function testToolsSkipNamelessAndFillTheSchema(): void {
		$tools = $this->format()->buildAnthropicTools(
			['not-an-array', ['description' => 'no name'], ['name' => 'typed', 'parameters' => ['properties' => ['a' => ['type' => 'string']]]], ['name' => 'bare', 'parameters' => 'x']]
		);

		$this->assertCount(2, $tools);
		$this->assertSame(
			['name' => 'typed', 'description' => '', 'input_schema' => ['properties' => ['a' => ['type' => 'string']], 'type' => 'object']],
			$tools[0]
		);
		$this->assertSame('bare', $tools[1]['name']);
		$this->assertSame('object', $tools[1]['input_schema']['type']);
		$this->assertInstanceOf(\stdClass::class, $tools[1]['input_schema']['properties']);
	}//end testToolsSkipNamelessAndFillTheSchema()

	/**
	 * Text blocks are joined, tool_use blocks extracted, usage and stop reason carried.
	 *
	 * @return void
	 */
	public function testResponseIsParsed(): void {
		$content = [
			['type' => 'text', 'text' => 'Hallo '],
			['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search', 'input' => ['q' => 'x']],
			'not-a-block',
			['type' => 'text', 'text' => 'wereld'],
			['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'list', 'input' => 'bad'],
		];
		$parsed = $this->format()->parseAnthropicResponse(
			['content' => $content, 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 12, 'output_tokens' => '5']]
		);

		$this->assertSame('Hallo wereld', $parsed['text']);
		$this->assertSame(
			[
				['id' => 'tu_1', 'name' => 'search', 'input' => ['q' => 'x']],
				['id' => 'tu_2', 'name' => 'list', 'input' => []],
			],
			$parsed['toolCalls']
		);
		$this->assertSame('tool_use', $parsed['stopReason']);
		$this->assertSame($content, $parsed['content']);
		$this->assertSame(['promptTokens' => 12, 'completionTokens' => 5], $parsed['usage']);
	}//end testResponseIsParsed()

	/**
	 * A response without content or usage parses to empty values; non-array usage is none.
	 *
	 * @return void
	 */
	public function testEmptyResponseIsParsed(): void {
		$format = $this->format();

		$this->assertSame(
			['text' => '', 'toolCalls' => [], 'stopReason' => '', 'content' => [], 'usage' => ['promptTokens' => 0, 'completionTokens' => 0]],
			$format->parseAnthropicResponse([])
		);
		$this->assertSame([], $format->parseAnthropicResponse(['content' => 'x', 'usage' => 'none'])['usage']);
	}//end testEmptyResponseIsParsed()

	/**
	 * Tool results become tool_result blocks; is_error passes only when strictly true.
	 *
	 * @return void
	 */
	public function testToolResultBlocks(): void {
		$blocks = $this->format()->buildAnthropicToolResultBlocks(
			[
				['tool_use_id' => 'tu_1', 'content' => 'ok'],
				'skip',
				['tool_use_id' => 'tu_2', 'content' => 'boom', 'is_error' => true],
				['is_error' => 'yes'],
			]
		);

		$this->assertSame(
			[
				['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'ok'],
				['type' => 'tool_result', 'tool_use_id' => 'tu_2', 'content' => 'boom', 'is_error' => true],
				['type' => 'tool_result', 'tool_use_id' => '', 'content' => ''],
			],
			$blocks
		);
	}//end testToolResultBlocks()
}//end class
