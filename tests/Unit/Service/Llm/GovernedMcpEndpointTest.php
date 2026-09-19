<?php

/**
 * Unit tests for GovernedMcpEndpoint — the shared resolution of Hermiq's own
 * governed MCP origin.
 *
 * The property under test is EXACTNESS. The PDP admits this origin at a trust
 * tier that skips the private-address block, so a match that is one character
 * too generous is a hole. These cases are therefore mostly near-misses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\GovernedMcpEndpoint;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Exact-authority recognition of the governance origin.
 */
final class GovernedMcpEndpointTest extends TestCase {

	/**
	 * Build the service with a fixed published URL and override.
	 *
	 * @param string $published The URL Nextcloud publishes.
	 * @param string $override The `mcp_run_base_url` value.
	 *
	 * @return GovernedMcpEndpoint
	 */
	private function endpoint(string $published, string $override): GovernedMcpEndpoint {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn($published);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($override);
		return new GovernedMcpEndpoint($urls, $config);

	}//end endpoint()

	/**
	 * The container-facing override replaces the published origin, keeping the path.
	 *
	 * @return void
	 */
	public function testTheOverrideReplacesTheOriginAndKeepsThePath(): void {
		$this->assertSame(
			'http://nextcloud/apps/hermiq/api/mcp/run',
			GovernedMcpEndpoint::applyContainerOrigin('http://localhost/apps/hermiq/api/mcp/run', 'http://nextcloud/')
		);

	}//end testTheOverrideReplacesTheOriginAndKeepsThePath()

	/**
	 * With no override the published URL is used unchanged.
	 *
	 * @return void
	 */
	public function testWithoutAnOverrideThePublishedUrlIsUnchanged(): void {
		$this->assertSame(
			'https://cloud.example.org/apps/hermiq/api/mcp/run',
			GovernedMcpEndpoint::applyContainerOrigin('https://cloud.example.org/apps/hermiq/api/mcp/run', '')
		);

	}//end testWithoutAnOverrideThePublishedUrlIsUnchanged()

	/**
	 * A scheme-default port is filled in, so `http://nextcloud` is `nextcloud:80`.
	 *
	 * @return void
	 */
	public function testTheAuthorityFillsInTheSchemeDefaultPort(): void {
		$this->assertSame(
			'nextcloud:80',
			$this->endpoint('http://localhost/apps/hermiq/api/mcp/run', 'http://nextcloud')->authority()
		);
		$this->assertSame(
			'cloud.example.org:443',
			$this->endpoint('https://cloud.example.org/apps/hermiq/api/mcp/run', '')->authority()
		);

	}//end testTheAuthorityFillsInTheSchemeDefaultPort()

	/**
	 * An explicit port is kept.
	 *
	 * @return void
	 */
	public function testAnExplicitPortIsKept(): void {
		$this->assertSame(
			'nextcloud:8080',
			$this->endpoint('http://localhost/apps/hermiq/api/mcp/run', 'http://nextcloud:8080')->authority()
		);

	}//end testAnExplicitPortIsKept()

	/**
	 * The governance origin matches itself, case-insensitively on the host.
	 *
	 * @return void
	 */
	public function testTheGovernanceOriginMatchesItself(): void {
		$endpoint = $this->endpoint('http://localhost/apps/hermiq/api/mcp/run', 'http://nextcloud');
		$this->assertTrue($endpoint->matches('nextcloud', 80));
		$this->assertTrue($endpoint->matches('NextCloud', 80));

	}//end testTheGovernanceOriginMatchesItself()

	/**
	 * Near-misses do NOT match: a different port, a subdomain, a superstring, a
	 * parent domain. Each of these would be admitted by a suffix-matching
	 * `NO_PROXY` exemption; none is admitted here.
	 *
	 * @return void
	 */
	public function testNearMissesDoNotMatch(): void {
		$endpoint = $this->endpoint('http://localhost/apps/hermiq/api/mcp/run', 'http://nextcloud');
		$this->assertFalse($endpoint->matches('nextcloud', 8080), 'a different port is a different origin');
		$this->assertFalse($endpoint->matches('evil.nextcloud', 80), 'a subdomain is not the origin');
		$this->assertFalse($endpoint->matches('nextcloud.evil.example', 80), 'a superstring is not the origin');
		$this->assertFalse($endpoint->matches('nextcloud2', 80), 'a neighbour is not the origin');
		$this->assertFalse($endpoint->matches('', 80));

	}//end testNearMissesDoNotMatch()

	/**
	 * An unresolvable endpoint matches NOTHING — the failure direction is deny.
	 *
	 * @return void
	 */
	public function testAnUnresolvableEndpointMatchesNothing(): void {
		$endpoint = $this->endpoint('', '');
		$this->assertSame('', $endpoint->authority());
		$this->assertFalse($endpoint->matches('nextcloud', 80));

	}//end testAnUnresolvableEndpointMatchesNothing()
}//end class
