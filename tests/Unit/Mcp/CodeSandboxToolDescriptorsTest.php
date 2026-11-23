<?php

/**
 * The runCode descriptor follows where code runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-an-agent-runs-code-only-with-a-grant-and-an-enabled-ai-feature-req-sandbox-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Mcp;

use OCA\Hermiq\Mcp\CodeSandboxToolDescriptors;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Mcp\CodeSandboxToolDescriptors
 */
class CodeSandboxToolDescriptorsTest extends TestCase {

	/**
	 * The own sandbox: reach self, files lift it to user; write-classified.
	 *
	 * @return void
	 */
	public function testTheOwnSandboxReachesSelf(): void {
		$tools = CodeSandboxToolDescriptors::forReach(reach: 'self');

		$this->assertSame(['hermiq.runCode', 'hermiq.installTool'], array_column($tools, 'id'));
		$this->assertSame('hermiq.runCode', $tools[0]['id']);
		$this->assertSame('self', $tools[0]['reach']);
		$this->assertSame(['files' => 'user'], $tools[0]['reachWithArguments']);
		$this->assertFalse($tools[0]['readOnlyHint']);
		$this->assertSame('create', $tools[0]['scope']);
		$this->assertSame(['language', 'code'], $tools[0]['inputSchema']['required']);
		$this->assertSame(CodeSandboxToolDescriptors::MAX_FILES, $tools[0]['inputSchema']['properties']['files']['maxItems']);
	}//end testTheOwnSandboxReachesSelf()

	/**
	 * A hosted sandbox: reach external, files keep it external.
	 *
	 * @return void
	 */
	public function testAHostedSandboxReachesExternal(): void {
		$tool = CodeSandboxToolDescriptors::forReach(reach: 'external')[0];

		$this->assertSame('external', $tool['reach']);
		$this->assertSame(['files' => 'external'], $tool['reachWithArguments']);
	}//end testAHostedSandboxReachesExternal()

	/**
	 * Any other value reads as the own sandbox.
	 *
	 * @return void
	 */
	public function testAnUnknownReachReadsAsSelf(): void {
		$this->assertSame('self', CodeSandboxToolDescriptors::forReach(reach: 'instance')[0]['reach']);
		$this->assertSame('user', CodeSandboxToolDescriptors::atLeastUser(reach: 'self'));
		$this->assertSame('instance', CodeSandboxToolDescriptors::atLeastUser(reach: 'instance'));
	}//end testAnUnknownReachReadsAsSelf()
}//end class
