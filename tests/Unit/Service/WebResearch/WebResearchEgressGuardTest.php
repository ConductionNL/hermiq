<?php

/**
 * Unit tests for WebResearchEgressGuard (web-research-tool) — the SSRF/allowlist/
 * denylist gate at the heart of this security-critical change.
 *
 * Covers: rejection of a resolved private/loopback/link-local address, unconditional
 * rejection of the cloud-metadata address (even allowlisted), denylist/allowlist
 * enforcement (web.fetch targets only, never the admin-configured search endpoint),
 * the search-endpoint private-address exemption, the HTTPS-or-explicit-opt-in scheme
 * check, and the fail-closed DNS-resolution-failure path.
 *
 * `resolveAddresses()`/`dnsGetRecord()` are overridden per test via an anonymous
 * subclass so a "test hostname resolving to 10.0.0.5" (test-plan.md TC-3/TC-4) needs
 * no real DNS lookup — mirrors NC core's own `DnsPinMiddleware::dnsGetRecord()`
 * testability pattern.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-the-admin-configured-search-endpoint-is-exempt-from-the-private-address-block
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WebResearchEgressGuard.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-3-webresearchegressguard-ssrf--allowlistdenylist
 */
class WebResearchEgressGuardTest extends TestCase
{

    /**
     * A guard whose DNS resolution is stubbed to a fixed set of addresses for every
     * host, regardless of what is actually queried.
     *
     * @param string[] $addresses The addresses `resolveAddresses()` returns.
     *
     * @return WebResearchEgressGuard
     */
    private function guardResolvingTo(array $addresses): WebResearchEgressGuard
    {
        return new class($addresses) extends WebResearchEgressGuard
        {
            /**
             * @param string[] $addresses The stubbed resolution result.
             */
            public function __construct(private readonly array $addresses)
            {
            }

            /**
             * @param string $host The host that would have been resolved.
             *
             * @return string[]
             */
            protected function resolveAddresses(string $host): array
            {
                return $this->addresses;
            }
        };

    }//end guardResolvingTo()

    /**
     * A guard whose DNS resolution always fails (empty result) — the fail-closed path.
     *
     * @return WebResearchEgressGuard
     */
    private function guardWithFailedResolution(): WebResearchEgressGuard
    {
        return $this->guardResolvingTo(addresses: []);

    }//end guardWithFailedResolution()

    /**
     * A `web.fetch` target whose hostname resolves to an RFC 1918 private address is
     * rejected.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-chosen-url-resolves-to-a-private-address
     */
    public function testRejectsResolvedPrivateAddress(): void
    {
        $guard  = $this->guardResolvingTo(['10.0.0.5']);
        $result = $guard->assertSafe(url: 'https://internal.example.test/path', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('private_address', $result['code']);

    }//end testRejectsResolvedPrivateAddress()

    /**
     * A loopback-resolving target is rejected (127.0.0.1).
     *
     * @return void
     */
    public function testRejectsResolvedLoopbackAddress(): void
    {
        $guard  = $this->guardResolvingTo(['127.0.0.1']);
        $result = $guard->assertSafe(url: 'https://loopback.example.test/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('private_address', $result['code']);

    }//end testRejectsResolvedLoopbackAddress()

    /**
     * A link-local-resolving target is rejected (169.254.1.1 — NOT the metadata
     * address itself, which has its own dedicated code).
     *
     * @return void
     */
    public function testRejectsResolvedLinkLocalAddress(): void
    {
        $guard  = $this->guardResolvingTo(['169.254.1.1']);
        $result = $guard->assertSafe(url: 'https://link-local.example.test/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('private_address', $result['code']);

    }//end testRejectsResolvedLinkLocalAddress()

    /**
     * An IPv6 unique-local-resolving target is rejected (fc00::/7).
     *
     * @return void
     */
    public function testRejectsResolvedIpv6UniqueLocalAddress(): void
    {
        $guard  = $this->guardResolvingTo(['fdff:ffff:ffff::1']);
        $result = $guard->assertSafe(url: 'https://ula.example.test/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('private_address', $result['code']);

    }//end testRejectsResolvedIpv6UniqueLocalAddress()

    /**
     * The cloud-metadata address (169.254.169.254) is rejected UNCONDITIONALLY, even
     * when the host is on the allowlist.
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-chosen-url-resolves-to-the-cloud-metadata-address
     */
    public function testRejectsCloudMetadataAddressEvenWhenAllowlisted(): void
    {
        $guard  = $this->guardResolvingTo(['169.254.169.254']);
        $result = $guard->assertSafe(
            url: 'https://metadata.example.test/latest/meta-data/',
            isAdminConfiguredEndpoint: false,
            allowlist: ['metadata.example.test']
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('metadata_address', $result['code']);

    }//end testRejectsCloudMetadataAddressEvenWhenAllowlisted()

    /**
     * The IPv6 cloud-metadata address (fd00:ec2::254) is also rejected unconditionally.
     *
     * @return void
     */
    public function testRejectsIpv6CloudMetadataAddress(): void
    {
        $guard  = $this->guardResolvingTo(['fd00:ec2::254']);
        $result = $guard->assertSafe(url: 'https://metadata6.example.test/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('metadata_address', $result['code']);

    }//end testRejectsIpv6CloudMetadataAddress()

    /**
     * The cloud-metadata block applies even to the admin-configured SEARCH endpoint
     * (design.md: "no legitimate search backend is ever the metadata service").
     *
     * @return void
     */
    public function testRejectsCloudMetadataAddressEvenForSearchEndpoint(): void
    {
        $guard  = $this->guardResolvingTo(['169.254.169.254']);
        $result = $guard->assertSafe(url: 'https://searxng.internal/search', isAdminConfiguredEndpoint: true);

        $this->assertFalse($result['allowed']);
        $this->assertSame('metadata_address', $result['code']);

    }//end testRejectsCloudMetadataAddressEvenForSearchEndpoint()

    /**
     * A denylisted host is rejected before any DNS resolution is attempted (no
     * `resolveAddresses()` call at all).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-denylisted-host-is-requested
     */
    public function testRejectsDenylistedHostBeforeResolution(): void
    {
        $guard = new class extends WebResearchEgressGuard
        {
            protected function resolveAddresses(string $host): array
            {
                throw new \LogicException('DNS resolution must not be attempted for a denylisted host.');
            }
        };

        $result = $guard->assertSafe(
            url: 'https://blocked.example.test/',
            isAdminConfiguredEndpoint: false,
            denylist: ['blocked.example.test']
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('denylisted_host', $result['code']);

    }//end testRejectsDenylistedHostBeforeResolution()

    /**
     * A non-empty allowlist rejects a target not on it, even though the address
     * would otherwise pass the SSRF checks (a public address).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-allowlist-is-configured-and-the-target-is-not-on-it
     */
    public function testRejectsNotAllowlistedHost(): void
    {
        $guard  = $this->guardResolvingTo(['8.8.8.8']);
        $result = $guard->assertSafe(
            url: 'https://not-listed.example.test/',
            isAdminConfiguredEndpoint: false,
            allowlist: ['allowed.example.test']
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('not_allowlisted', $result['code']);

    }//end testRejectsNotAllowlistedHost()

    /**
     * An allowlisted, publicly-resolving host passes.
     *
     * @return void
     */
    public function testAllowsAllowlistedPublicHost(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(
            url: 'https://allowed.example.test/page',
            isAdminConfiguredEndpoint: false,
            allowlist: ['allowed.example.test']
        );

        $this->assertTrue($result['allowed']);

    }//end testAllowsAllowlistedPublicHost()

    /**
     * With no allowlist configured (denylist-only mode), a public address passes.
     *
     * @return void
     */
    public function testAllowsPublicAddressWithNoAllowlistConfigured(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(url: 'https://public.example.test/', isAdminConfiguredEndpoint: false);

        $this->assertTrue($result['allowed']);

    }//end testAllowsPublicAddressWithNoAllowlistConfigured()

    /**
     * The admin-configured search endpoint on an internal/private address is NOT
     * rejected for being private (the deliberate, narrow exemption).
     *
     * @return void
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-a-self-hosted-searxng-instance-is-on-an-internal-docker-network-address
     */
    public function testSearchEndpointExemptFromPrivateAddressBlock(): void
    {
        $guard  = $this->guardResolvingTo(['172.20.0.5']);
        $result = $guard->assertSafe(
            url: 'http://searxng:8080/search',
            isAdminConfiguredEndpoint: true,
            allowInsecureHttp: true
        );

        $this->assertTrue($result['allowed']);

    }//end testSearchEndpointExemptFromPrivateAddressBlock()

    /**
     * The allowlist/denylist is NOT applied to the search endpoint — a denylisted
     * hostname is still reachable when called AS the search endpoint.
     *
     * @return void
     */
    public function testAllowlistDenylistDoNotApplyToSearchEndpoint(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(
            url: 'https://searxng.example.test/search',
            isAdminConfiguredEndpoint: true,
            denylist: ['searxng.example.test']
        );

        $this->assertTrue($result['allowed'], 'The denylist must not apply to the admin-configured search endpoint.');

    }//end testAllowlistDenylistDoNotApplyToSearchEndpoint()

    /**
     * A non-HTTPS URL is rejected by default.
     *
     * @return void
     */
    public function testRejectsHttpByDefault(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(url: 'http://example.test/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('insecure_scheme', $result['code']);

    }//end testRejectsHttpByDefault()

    /**
     * A non-HTTPS URL is accepted when the admin has explicitly opted in.
     *
     * @return void
     */
    public function testAllowsHttpWhenExplicitlyOptedIn(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(url: 'http://example.test/', isAdminConfiguredEndpoint: false, allowInsecureHttp: true);

        $this->assertTrue($result['allowed']);

    }//end testAllowsHttpWhenExplicitlyOptedIn()

    /**
     * A scheme other than http/https with a HOST (e.g. `ftp://`) is always
     * rejected, even with the insecure-HTTP opt-in enabled.
     *
     * @return void
     */
    public function testRejectsNonHttpScheme(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(url: 'ftp://example.test/file', isAdminConfiguredEndpoint: false, allowInsecureHttp: true);

        $this->assertFalse($result['allowed']);
        $this->assertSame('insecure_scheme', $result['code']);

    }//end testRejectsNonHttpScheme()

    /**
     * A `file://` URL has no host at all and is rejected as unparseable (there is
     * no host to validate), not merely as an insecure scheme.
     *
     * @return void
     */
    public function testRejectsFileUrlAsUnparseable(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(url: 'file:///etc/passwd', isAdminConfiguredEndpoint: false, allowInsecureHttp: true);

        $this->assertFalse($result['allowed']);
        $this->assertSame('invalid_url', $result['code']);

    }//end testRejectsFileUrlAsUnparseable()

    /**
     * A URL with no parseable host is rejected.
     *
     * @return void
     */
    public function testRejectsUnparseableUrl(): void
    {
        $guard  = $this->guardResolvingTo(['93.184.216.34']);
        $result = $guard->assertSafe(url: 'not-a-url', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('invalid_url', $result['code']);

    }//end testRejectsUnparseableUrl()

    /**
     * DNS resolution failure fails CLOSED (rejected), not open.
     *
     * @return void
     */
    public function testFailsClosedOnDnsResolutionFailure(): void
    {
        $guard  = $this->guardWithFailedResolution();
        $result = $guard->assertSafe(url: 'https://nonexistent.example.test/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('dns_resolution_failed', $result['code']);

    }//end testFailsClosedOnDnsResolutionFailure()

    /**
     * A bare IP-literal host is treated as its own resolved address — no DNS lookup
     * needed — and is still subject to the SSRF checks.
     *
     * @return void
     */
    public function testIpLiteralHostIsCheckedDirectly(): void
    {
        $guard  = new WebResearchEgressGuard();
        $result = $guard->assertSafe(url: 'https://127.0.0.1/', isAdminConfiguredEndpoint: false);

        $this->assertFalse($result['allowed']);
        $this->assertSame('private_address', $result['code']);

    }//end testIpLiteralHostIsCheckedDirectly()
}//end class
