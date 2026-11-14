<?php

/**
 * The context meter uses the provider's own count for a turn without tool calls
 * and an estimate, marked as one, for anything else.
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
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-a-person-sees-how-full-the-models-context-is-req-atrn-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextUsageMeter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContextUsageMeter.
 */
class ContextUsageMeterTest extends TestCase {

	/**
	 * Ollama reporting prompt tokens with a declared window: the count stands, not estimated.
	 *
	 * @return void
	 */
	public function testTheProvidersCountStandsWithTheDeclaredWindow(): void {
		$usage = (new ContextUsageMeter())->measure(
			usage: ['promptTokens' => 105000, 'completionTokens' => 1300, 'llmSeconds' => 2.1],
			promptCharacters: 999,
			answer: 'An answer.',
			windowTokens: 128000,
			usedTools: false
		);

		$this->assertSame(['usedTokens' => 106300, 'windowTokens' => 128000, 'estimated' => false], $usage);
	}//end testTheProvidersCountStandsWithTheDeclaredWindow()

	/**
	 * A provider without a count (Fireworks) is estimated at four characters per token.
	 *
	 * @return void
	 */
	public function testWithoutACountTheUsageIsEstimated(): void {
		$usage = (new ContextUsageMeter())->measure(
			usage: ['llmSeconds' => 1.0],
			promptCharacters: 397,
			answer: 'ééé',
			windowTokens: null,
			usedTools: false
		);

		// (397 + 3 multibyte characters) / 4 = 100.
		$this->assertSame(['usedTokens' => 100, 'windowTokens' => null, 'estimated' => true], $usage);

		$rounded = (new ContextUsageMeter())->measure(usage: [], promptCharacters: 401, answer: '', windowTokens: 8000, usedTools: false);
		$this->assertSame(['usedTokens' => 101, 'windowTokens' => 8000, 'estimated' => true], $rounded);
	}//end testWithoutACountTheUsageIsEstimated()

	/**
	 * A turn with tool calls sums several requests, so its count is not the context: estimated.
	 *
	 * @return void
	 */
	public function testATurnWithToolCallsIsEstimated(): void {
		$usage = (new ContextUsageMeter())->measure(
			usage: ['promptTokens' => 9000, 'completionTokens' => 100],
			promptCharacters: 4000,
			answer: '',
			windowTokens: 128000,
			usedTools: true
		);

		$this->assertSame(['usedTokens' => 1000, 'windowTokens' => 128000, 'estimated' => true], $usage);
	}//end testATurnWithToolCallsIsEstimated()

	/**
	 * A zero or malformed count is no count; a malformed completion count adds nothing.
	 *
	 * @return void
	 */
	public function testAZeroOrMalformedCountIsNoCount(): void {
		$meter = new ContextUsageMeter();
		$this->assertTrue($meter->measure(usage: ['promptTokens' => 0], promptCharacters: 8, answer: '', windowTokens: null, usedTools: false)['estimated']);
		$this->assertTrue($meter->measure(usage: ['promptTokens' => '12'], promptCharacters: 8, answer: '', windowTokens: null, usedTools: false)['estimated']);
		$this->assertSame(
			50,
			$meter->measure(usage: ['promptTokens' => 50, 'completionTokens' => 'x'], promptCharacters: 8, answer: '', windowTokens: null, usedTools: false)['usedTokens']
		);
	}//end testAZeroOrMalformedCountIsNoCount()
}//end class
