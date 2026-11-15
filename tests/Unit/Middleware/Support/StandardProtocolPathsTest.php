<?php

/**
 * Unit tests for the API paths that speak a protocol defined outside the app.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Middleware\Support;

use OCA\Hermiq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each protocol path is recognised by its exact shape, near misses are not, and
 * request paths are reduced to the part after the app root.
 *
 * @covers \OCA\Hermiq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function paths(): array {
		return [
			'mcp root'                 => ['/api/mcp', true],
			'mcp sub path'             => ['/api/mcp/run', true],
			'mcp lookalike'            => ['/api/mcpx', false],
			'agent webhook'            => ['/api/agents/abc-123/webhook', true],
			'agent webhook nested id'  => ['/api/agents/a/b/webhook', false],
			'agent webhook sub path'   => ['/api/agents/abc/webhook/extra', false],
			'chat stream'              => ['/api/chat/stream', true],
			'chat stream sub path'     => ['/api/chat/stream/1', false],
			'health'                   => ['/api/health', true],
			'health trailing slash'    => ['/api/health/', false],
			'metrics'                  => ['/api/metrics', true],
			'versioned health'         => ['/api/v1/health', false],
			'plain api path'           => ['/api/agents', false],
			'no leading slash'         => ['api/health', true],
			'request path'             => ['/index.php/apps/hermiq/api/metrics', true],
			'request path, other api'  => ['/index.php/apps/hermiq/api/sessions', false],
			'other app with same path' => ['/index.php/apps/other/api/health', false],
		];
	}//end paths()

	/**
	 * Each path is recognised as a standard protocol path or not.
	 *
	 * @param string $path     The path.
	 * @param bool   $expected Whether it speaks a standard protocol.
	 *
	 * @return void
	 */
	#[DataProvider('paths')]
	public function testMatches(string $path, bool $expected): void {
		$this->assertSame(expected: $expected, actual: StandardProtocolPaths::matches(path: $path));
	}//end testMatches()

	/**
	 * A path under the app root is cut to the part after it; anything else only
	 * gains a single leading slash.
	 *
	 * @return void
	 */
	public function testAppRelative(): void {
		$this->assertSame(expected: '/api/mcp/run', actual: StandardProtocolPaths::appRelative(path: '/index.php/apps/hermiq/api/mcp/run'));
		$this->assertSame(expected: '/api/mcp/run', actual: StandardProtocolPaths::appRelative(path: 'apps/hermiq/api/mcp/run'));
		$this->assertSame(expected: '/', actual: StandardProtocolPaths::appRelative(path: '/apps/hermiq/'));
		$this->assertSame(expected: '/api/health', actual: StandardProtocolPaths::appRelative(path: '///api/health'));
		$this->assertSame(expected: '/apps/hermiqx/api/health', actual: StandardProtocolPaths::appRelative(path: '/apps/hermiqx/api/health'));
		$this->assertSame(expected: '/apps/hermiq', actual: StandardProtocolPaths::appRelative(path: '/apps/hermiq'));
		$this->assertSame(expected: '/', actual: StandardProtocolPaths::appRelative(path: ''));
	}//end testAppRelative()

	/**
	 * Every declared pattern is a valid expression anchored at the start of the path.
	 *
	 * @return void
	 */
	public function testEveryPatternIsAnchoredUnderApi(): void {
		$this->assertCount(expectedCount: 5, haystack: StandardProtocolPaths::PATTERNS);
		foreach (StandardProtocolPaths::PATTERNS as $protocol => $pattern) {
			$this->assertStringStartsWith(prefix: '#^/api/', string: $pattern, message: $protocol);
			$this->assertSame(expected: 0, actual: preg_match($pattern, '/x/api/mcp'), message: $protocol);
		}
	}//end testEveryPatternIsAnchoredUnderApi()
}//end class
