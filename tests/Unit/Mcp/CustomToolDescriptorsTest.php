<?php

/**
 * CustomToolDescriptors unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-a-custom-tool-is-offered-under-a-two-segment-id-and-only-by-exact-grant-req-custool-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\CustomToolDescriptors;
use OCA\Hermiq\Service\CustomTool\CustomToolDefinition;
use OCA\Hermiq\Tests\Unit\Service\CustomTool\CustomToolDefinitionTest;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use PHPUnit\Framework\TestCase;

/**
 * The descriptor of a custom tool.
 */
final class CustomToolDescriptorsTest extends TestCase {

	/**
	 * A stored tool, changed.
	 *
	 * @param array<string, mixed> $change The change.
	 *
	 * @return array<string, mixed>
	 */
	private static function tool(array $change=[]): array {
		return array_replace(CustomToolDefinition::normalise(payload: CustomToolDefinitionTest::vergunningcheck()), $change);
	}//end tool()

	/**
	 * The id has two segments, so no three-segment wildcard reaches it.
	 *
	 * @return void
	 */
	public function testTheIdHasTwoSegmentsAndNoWildcardReachesIt(): void {
		$id = CustomToolDescriptors::describe(tool: self::tool())['id'];

		$this->assertSame('hermiq.custom_vergunningcheck', $id);
		$this->assertCount(2, explode('.', $id));
		foreach (['hermiq.custom.*', 'hermiq.*.*', 'hermiq.custom_vergunningcheck.*'] as $wildcard) {
			$this->assertFalse(fnmatch($wildcard, $id), $wildcard);
		}

		$this->assertSame('vergunningcheck', CustomToolDescriptors::slugOf(toolId: $id));
		$this->assertNull(CustomToolDescriptors::slugOf(toolId: 'hermiq.sendMail'));
		$this->assertNull(CustomToolDescriptors::slugOf(toolId: 'hermiq.custom_../x'));
	}//end testTheIdHasTwoSegmentsAndNoWildcardReachesIt()

	/**
	 * Hints follow what the admin declared; reach is external for every target.
	 *
	 * @return void
	 */
	public function testHintsFollowWhatItChangesAndReachStaysExternal(): void {
		$readOnly = CustomToolDescriptors::describe(tool: self::tool());
		$this->assertTrue($readOnly['readOnlyHint']);
		$this->assertFalse($readOnly['destructiveHint']);
		$this->assertSame('read', $readOnly['scope']);
		$this->assertSame(ToolReachResolver::REACH_EXTERNAL, $readOnly['reach']);
		$this->assertSame(['adres'], $readOnly['inputSchema']['required']);

		$integriq = CustomToolDescriptors::describe(tool: self::tool(['target' => ['type' => 'integriq', 'source' => 'bag-api', 'method' => 'GET', 'path' => '/a']]));
		$this->assertTrue($integriq['readOnlyHint'], 'marked read-only by the admin');
		$this->assertSame(ToolReachResolver::REACH_EXTERNAL, $integriq['reach'], 'but the call still leaves the instance');

		$writes = CustomToolDescriptors::describe(tool: self::tool(['changesData' => 'yes']));
		$this->assertFalse($writes['readOnlyHint']);
		$this->assertSame('update', $writes['scope']);

		$irreversible = CustomToolDescriptors::describe(tool: self::tool(['changesData' => 'irreversible']));
		$this->assertTrue($irreversible['destructiveHint']);
	}//end testHintsFollowWhatItChangesAndReachStaysExternal()

	/**
	 * A disabled or slug-less tool is not offered.
	 *
	 * @return void
	 */
	public function testADisabledToolIsNotOffered(): void {
		$this->assertNull(CustomToolDescriptors::describe(tool: self::tool(['enabled' => false])));
		$this->assertNull(CustomToolDescriptors::describe(tool: self::tool(['slug' => ''])));
	}//end testADisabledToolIsNotOffered()
}//end class
