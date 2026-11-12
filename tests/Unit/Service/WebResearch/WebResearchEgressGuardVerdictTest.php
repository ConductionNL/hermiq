<?php

/**
 * Golden test for WebResearchEgressGuard::assertSafe: the exact verdict of each
 * check, their order, case folding of scheme and host, and that DNS is only
 * consulted once the scheme and lists have passed.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\WebResearch
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

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use PHPUnit\Framework\TestCase;

final class WebResearchEgressGuardVerdictTest extends TestCase {

	/**
	 * Hosts handed to DNS.
	 *
	 * @var array<int, string>
	 */
	public array $resolved = [];

	public function testAnUnparseableUrlIsRejectedFirst(): void {
		self::assertSame(['allowed' => false, 'code' => 'invalid_url', 'message' => 'The URL could not be parsed.'], $this->guard(['8.8.8.8'])->assertSafe(url: 'ftp:///x', isAdminConfiguredEndpoint: false));
		self::assertSame([], $this->resolved);
	}//end testAnUnparseableUrlIsRejectedFirst()

	public function testTheSchemeIsCheckedBeforeTheListsAndCaseFolded(): void {
		$guard = $this->guard(['8.8.8.8']);

		self::assertSame(
			['allowed' => false, 'code' => 'insecure_scheme', 'message' => 'Only https:// URLs are allowed (enable the insecure-HTTP opt-in to allow http://).'],
			$guard->assertSafe(url: 'http://bad.example/', isAdminConfiguredEndpoint: false, denylist: ['bad.example'])
		);
		self::assertTrue($guard->assertSafe(url: 'HTTPS://Good.Example/', isAdminConfiguredEndpoint: false, allowlist: ['good.example'])['allowed']);
		self::assertTrue($guard->assertSafe(url: 'HTTP://good.example/', isAdminConfiguredEndpoint: false, allowInsecureHttp: true)['allowed']);
		self::assertSame(['good.example', 'good.example'], $this->resolved);
	}//end testTheSchemeIsCheckedBeforeTheListsAndCaseFolded()

	public function testTheListsCarryTheLowercasedHostInTheirSentences(): void {
		$guard = $this->guard(['8.8.8.8']);

		self::assertSame(
			['allowed' => false, 'code' => 'denylisted_host', 'message' => "Host 'bad.example' is on the configured denylist."],
			$guard->assertSafe(url: 'https://BAD.example/', isAdminConfiguredEndpoint: false, allowlist: ['bad.example'], denylist: ['bad.example'])
		);
		self::assertSame(
			['allowed' => false, 'code' => 'not_allowlisted', 'message' => "Host 'other.example' is not on the configured allowlist."],
			$guard->assertSafe(url: 'https://Other.Example/', isAdminConfiguredEndpoint: false, allowlist: ['good.example'])
		);
		self::assertSame([], $this->resolved);
	}//end testTheListsCarryTheLowercasedHostInTheirSentences()

	public function testAFailedResolutionNamesTheHost(): void {
		self::assertSame(
			['allowed' => false, 'code' => 'dns_resolution_failed', 'message' => "Host 'gone.example' could not be resolved."],
			$this->guard([])->assertSafe(url: 'https://Gone.Example/', isAdminConfiguredEndpoint: true)
		);
		self::assertSame(['gone.example'], $this->resolved);
	}//end testAFailedResolutionNamesTheHost()

	public function testAPrivateAddressIsRejectedForFetchButNotForTheSearchEndpoint(): void {
		$guard = $this->guard(['10.0.0.5']);

		self::assertSame('private_address', $guard->assertSafe(url: 'https://intra.example/', isAdminConfiguredEndpoint: false)['code']);
		self::assertSame(['allowed' => true, 'code' => null, 'message' => null], $guard->assertSafe(url: 'https://intra.example/', isAdminConfiguredEndpoint: true, denylist: ['intra.example']));
	}//end testAPrivateAddressIsRejectedForFetchButNotForTheSearchEndpoint()

	public function testTheMetadataBlockOutranksThePrivateBlockWhateverTheOrder(): void {
		$verdict = $this->guard(['10.0.0.5', '169.254.169.254'])->assertSafe(url: 'https://mixed.example/', isAdminConfiguredEndpoint: false);

		self::assertSame(['allowed' => false, 'code' => 'metadata_address', 'message' => 'The destination resolves to a cloud-metadata address, which is always blocked.'], $verdict);
		self::assertSame(
			['allowed' => false, 'code' => 'private_address', 'message' => "Host 'mixed.example' resolves to a private/loopback/link-local address, which is blocked."],
			$this->guard(['8.8.8.8', '10.0.0.5'])->assertSafe(url: 'https://mixed.example/', isAdminConfiguredEndpoint: false)
		);
	}//end testTheMetadataBlockOutranksThePrivateBlockWhateverTheOrder()

	public function testResolutionCollectsAThenAaaaRecordsAndSkipsAFailedType(): void {
		$lookups = [];
		$guard = new class($lookups) extends WebResearchEgressGuard {
			public function __construct(private array &$lookups) {
			}

			public function resolve(string $host): array {
				return $this->resolveAddresses(host: $host);
			}

			protected function dnsGetRecord(string $hostname, int $type): array|false {
				$this->lookups[] = [$hostname, $type];
				if ($hostname === 'v6less.example' && $type === DNS_AAAA) {
					return false;
				}

				if ($type === DNS_A) {
					return [['ip' => '8.8.8.8'], ['host' => 'x'], ['ip' => '8.8.4.4', 'ipv6' => 'ignored']];
				}

				return [['ipv6' => '2001:db8::1']];
			}
		};

		self::assertSame(['8.8.8.8', '8.8.4.4', '2001:db8::1'], $guard->resolve('dual.example'));
		self::assertSame(['8.8.8.8', '8.8.4.4'], $guard->resolve('v6less.example'));
		self::assertSame(['192.0.2.7'], $guard->resolve('192.0.2.7'));
		self::assertSame([['dual.example', DNS_A], ['dual.example', DNS_AAAA], ['v6less.example', DNS_A], ['v6less.example', DNS_AAAA]], $lookups);
	}//end testResolutionCollectsAThenAaaaRecordsAndSkipsAFailedType()

	/**
	 * A guard whose resolution is stubbed and recorded.
	 *
	 * @param array<int, string> $addresses What every host resolves to.
	 *
	 * @return WebResearchEgressGuard
	 */
	private function guard(array $addresses): WebResearchEgressGuard {
		return new class($addresses, $this) extends WebResearchEgressGuard {
			public function __construct(private readonly array $addresses, private readonly WebResearchEgressGuardVerdictTest $test) {
			}

			protected function resolveAddresses(string $host): array {
				$this->test->resolved[] = $host;
				return $this->addresses;
			}
		};
	}//end guard()
}//end class
