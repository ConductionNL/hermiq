<?php

/**
 * Hermiq GovernedMcpEndpoint unresolvable-route and malformed-URL unit tests.
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

use OCA\Hermiq\Service\Llm\GovernedMcpEndpoint;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the deny-direction edges of the governed MCP endpoint.
 *
 * @covers \OCA\Hermiq\Service\Llm\GovernedMcpEndpoint
 */
class GovernedMcpEndpointEdgeTest extends TestCase {

	/**
	 * A route the generator cannot resolve yields no URL, no authority and no match, and config is not read.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRouteYieldsNothing(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')
			->with('hermiq.mcpRun.handle')
			->willThrowException(new RuntimeException('no such route'));
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');

		$endpoint = new GovernedMcpEndpoint(urlGenerator: $urls, appConfig: $config);

		$this->assertSame('', $endpoint->url());
		$this->assertSame('', $endpoint->authority());
		$this->assertFalse($endpoint->matches('localhost', 80));
	}//end testAnUnresolvableRouteYieldsNothing()

	/**
	 * A published URL without a path keeps its own origin even when an override is set.
	 *
	 * @return void
	 */
	public function testAUrlWithoutAPathIsNotRewritten(): void {
		$this->assertSame(
			'http://localhost',
			GovernedMcpEndpoint::applyContainerOrigin(publishedUrl: 'http://localhost', baseOverride: 'http://nextcloud')
		);
		$this->assertSame(
			'http://nextcloud/apps/hermiq/mcp',
			GovernedMcpEndpoint::applyContainerOrigin(publishedUrl: 'http://localhost/apps/hermiq/mcp', baseOverride: '  http://nextcloud/  ')
		);
	}//end testAUrlWithoutAPathIsNotRewritten()

	/**
	 * A resolved URL without a host has no authority and matches nothing.
	 *
	 * @return void
	 */
	public function testAUrlWithoutAHostHasNoAuthority(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('/apps/hermiq/mcp');
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$endpoint = new GovernedMcpEndpoint(urlGenerator: $urls, appConfig: $config);

		$this->assertSame('/apps/hermiq/mcp', $endpoint->url());
		$this->assertSame('', $endpoint->authority());
		$this->assertFalse($endpoint->matches('', 80));
	}//end testAUrlWithoutAHostHasNoAuthority()
}//end class
