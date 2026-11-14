<?php

/**
 * The computer tools are offered only on a model path that reads images in a tool
 * result, and their descriptors say what each one may do.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#scenario-a-text-only-model-does-not-see-the-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Mcp\ComputerToolDescriptors
 */
final class ComputerToolDescriptorsTest extends TestCase {

	/**
	 * A turn's function list: two ordinary tools and two computer tools.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function functions(): array {
		return [
			['name' => 'hermiq_webFetch', 'mcpId' => 'hermiq.webFetch'],
			['name' => 'hermiq_computerScreenshot', 'mcpId' => 'hermiq.computerScreenshot'],
			['name' => 'hermiq.computerClick'],
			['name' => 'opencatalogi_search', 'mcpId' => 'opencatalogi.search'],
		];
	}

	public function testOnlyTheAnthropicHttpPathCarriesImages(): void {
		self::assertTrue(ComputerToolDescriptors::carriesImages(provider: 'anthropic', executionMode: 'http'));
		self::assertFalse(ComputerToolDescriptors::carriesImages(provider: 'anthropic', executionMode: 'cli'));
		self::assertFalse(ComputerToolDescriptors::carriesImages(provider: 'ollama', executionMode: 'http'));
		self::assertFalse(ComputerToolDescriptors::carriesImages(provider: 'openai', executionMode: 'http'));
	}

	public function testAnImagelessPathLosesExactlyTheComputerTools(): void {
		self::assertSame(
			[
				['name' => 'hermiq_webFetch', 'mcpId' => 'hermiq.webFetch'],
				['name' => 'opencatalogi_search', 'mcpId' => 'opencatalogi.search'],
			],
			ComputerToolDescriptors::forDriver(functions: self::functions(), provider: 'ollama', executionMode: 'http')
		);
		self::assertSame(self::functions(), ComputerToolDescriptors::forDriver(functions: self::functions(), provider: 'anthropic', executionMode: 'http'));
	}

	public function testTheActionsAreDestructiveAndEveryToolNamesATargetAndReachesOut(): void {
		$byId = array_column(ComputerToolDescriptors::ALL, null, 'id');

		self::assertSame(ComputerToolDescriptors::IDS, array_keys($byId));
		foreach ($byId as $id => $tool) {
			self::assertSame('external', $tool['reach'], $id);
			self::assertContains('target', $tool['inputSchema']['required'], $id);
			self::assertSame(in_array($id, ComputerToolDescriptors::ACTION_IDS, true), $tool['destructiveHint'], $id);
			self::assertSame($id === ComputerToolDescriptors::SCREENSHOT, $tool['readOnlyHint'], $id);
		}
	}
}
