<?php

/**
 * Tests for EnsembleUsage: an ensemble turn's usage is the sum of its calls.
 *
 * @category Tests
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
 * @spec openspec/specs/model-ensemble/spec.md#requirement-an-ensemble-counts-every-call-against-the-budget-req-mens-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\EnsembleUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The summed usage and the per-model rows.
 */
#[CoversClass(EnsembleUsage::class)]
final class EnsembleUsageTest extends TestCase {

	/**
	 * Tokens and seconds are summed over every call; each call keeps its own row.
	 *
	 * @return void
	 */
	public function testEveryCallIsSummedAndKeptPerModel(): void {
		$calls = [
			['provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'usage' => ['promptTokens' => 120, 'completionTokens' => 30, 'llmSeconds' => 1.25]],
			['provider' => 'ollama', 'model' => 'qwen2.5', 'usage' => []],
			['provider' => 'ollama', 'model' => 'llama3.1', 'usage' => ['promptTokens' => 300, 'completionTokens' => 80, 'cacheReadTokens' => 5, 'llmSeconds' => 2.004, 'note' => 'x']],
		];

		$usage = EnsembleUsage::sum(calls: $calls);

		self::assertSame(420, $usage['promptTokens']);
		self::assertSame(110, $usage['completionTokens']);
		self::assertSame(5, $usage['cacheReadTokens']);
		self::assertSame(3.25, $usage['llmSeconds']);
		self::assertArrayNotHasKey('note', $usage);
		self::assertSame(
			[
				['provider' => 'anthropic', 'model' => 'claude-opus-4-8', 'usage' => $calls[0]['usage']],
				['provider' => 'ollama', 'model' => 'qwen2.5', 'usage' => []],
				['provider' => 'ollama', 'model' => 'llama3.1', 'usage' => $calls[2]['usage']],
			],
			$usage['usageByModel']
		);
	}//end testEveryCallIsSummedAndKeptPerModel()
}//end class
