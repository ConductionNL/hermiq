<?php

/**
 * The shared hint sets keep the browser and computer descriptors' MCP hints:
 * the reads change nothing, the actions are destructive updates.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/browser-use/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\BrowserToolDescriptors;
use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Mcp\ToolHints;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Mcp\ToolHints
 * @uses \OCA\Hermiq\Mcp\BrowserToolDescriptors
 * @uses \OCA\Hermiq\Mcp\ComputerToolDescriptors
 */
final class ToolHintsTest extends TestCase {

	/**
	 * A read changes nothing; an action is a destructive update.
	 *
	 * @return void
	 */
	public function testTheHintSetsSayWhatEachKindMayDo(): void {
		self::assertSame(
			expected: ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'scope' => 'read'],
			actual: ToolHints::READ
		);
		self::assertSame(
			expected: ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'scope' => 'update'],
			actual: ToolHints::DESTRUCTIVE_UPDATE
		);
	}

	/**
	 * Every browser and computer tool carries the hint set of its kind.
	 *
	 * @return void
	 */
	public function testEveryBrowserAndComputerToolCarriesTheHintsOfItsKind(): void {
		$expected = [
			BrowserToolDescriptors::OPEN => ToolHints::READ,
			BrowserToolDescriptors::READ => ToolHints::READ,
			BrowserToolDescriptors::CLICK => ToolHints::DESTRUCTIVE_UPDATE,
			BrowserToolDescriptors::FILL => ToolHints::DESTRUCTIVE_UPDATE,
			ComputerToolDescriptors::SCREENSHOT => ToolHints::READ,
			ComputerToolDescriptors::CLICK => ToolHints::DESTRUCTIVE_UPDATE,
			ComputerToolDescriptors::TYPE => ToolHints::DESTRUCTIVE_UPDATE,
			ComputerToolDescriptors::KEY => ToolHints::DESTRUCTIVE_UPDATE,
		];

		foreach ([...BrowserToolDescriptors::ALL, ...ComputerToolDescriptors::ALL] as $descriptor) {
			self::assertSame(
				expected: $expected[$descriptor['id']],
				actual: array_intersect_key($descriptor, $expected[$descriptor['id']]),
				message: $descriptor['id']
			);
		}
	}
}
