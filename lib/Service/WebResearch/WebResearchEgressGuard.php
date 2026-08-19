<?php

/**
 * Hermiq WebResearchEgressGuard.
 *
 * The SSRF/allowlist/denylist gate every outbound web-research call passes through
 * BEFORE any `OCP\Http\Client\IClientService` request is issued — the single guard
 * `WebSearchClient` and `WebFetchService` both call (design.md: "one guard, two call
 * sites, so a future third caller cannot accidentally bypass it").
 *
 * Two distinct trust tiers (design.md "Security Considerations"):
 *
 *   1. `$isAdminConfiguredEndpoint = true` — the admin-entered search backend. May
 *      legitimately be a private/internal address (a self-hosted SearXNG on the same
 *      Docker network or LAN is the expected sovereign default), so the private/
 *      loopback/link-local/RFC1918/ULA block and the allowlist/denylist are NOT
 *      applied. The cloud-metadata block, HTTPS-or-explicit-opt-in, and (by the
 *      caller, via request options) the response size cap and timeout still apply.
 *   2. `$isAdminConfiguredEndpoint = false` — `web.fetch`'s target: untrusted by
 *      construction (chosen by the LLM, typically from a `web.search` result the
 *      agent has never seen before). The FULL guard applies.
 *
 * The hostname is resolved and the check runs against the RESOLVED address, not the
 * hostname string, immediately before the request — so a hostname that resolves
 * safely at allowlist-config time cannot be rebound to an internal address later
 * (DNS rebinding). `resolveAddresses()`/`dnsGetRecord()` are protected, overridable
 * seams for tests, mirroring the exact technique NC core's own
 * `OC\Http\Client\DnsPinMiddleware::dnsGetRecord()` already uses for the same
 * problem.
 *
 * Verified against HEAD (2026-07-14): `OCP\Http\Client\IClientService::newClient()`'s
 * Guzzle stack ALSO performs its own resolved-IP validation + curl-level IP pinning
 * (`CURLOPT_RESOLVE`, via `DnsPinMiddleware::addDnsPinning()`) by default (system
 * config `dns_pinning`, default true) whenever a request does NOT set
 * `nextcloud.allow_local_address = true` — which closes the exact DNS-rebinding
 * TOCTOU gap proposal.md's "Open Questions" flagged as unresolved, transparently, as
 * long as `web.fetch` requests never opt into `allow_local_address`. This guard's own
 * resolve-then-check is kept regardless, for two reasons: (1) it is what produces the
 * clean, structured `{"error": {...}}` envelope the spec's acceptance criteria require
 * BEFORE any request is attempted (NC's own guard would instead throw
 * `LocalServerException`, uncaught, from inside `IClient::get()`), and (2) it is
 * independently unit-testable without a real DNS/curl stack. The two layers are
 * complementary defense-in-depth, not a duplicate — see `WebFetchService`'s docblock
 * for how the request options are built.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
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

namespace OCA\Hermiq\Service\WebResearch;

/**
 * SSRF/allowlist/denylist validation for every web-research outbound destination.
 *
 * @SuppressWarnings(PHPMD.LongVariable) $isAdminConfiguredEndpoint names the design's
 *   two trust tiers exactly (see the file docblock) across all three guard methods —
 *   the length IS the clarity.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-3-webresearchegressguard-ssrf--allowlistdenylist
 */
class WebResearchEgressGuard {

	/**
	 * Cloud-metadata addresses. Blocked UNCONDITIONALLY — even for the
	 * admin-configured search endpoint, even when allowlisted (design.md: "no
	 * legitimate search backend is ever the metadata service").
	 *
	 * @var string[]
	 */
	private const CLOUD_METADATA_ADDRESSES = ['169.254.169.254', 'fd00:ec2::254'];

	/**
	 * Validate a destination URL before any request is issued.
	 *
	 * @param string $url The destination URL.
	 * @param bool $isAdminConfiguredEndpoint Whether `$url` is the admin-entered
	 *                                        search endpoint (exempt from the
	 *                                        private-address block and the
	 *                                        allowlist/denylist) rather than an
	 *                                        agent-chosen `web.fetch` target.
	 * @param string[] $allowlist Exact-hostname allowlist (`web.fetch`
	 *                            targets only; ignored for the search
	 *                            endpoint).
	 * @param string[] $denylist Exact-hostname denylist (`web.fetch`
	 *                           targets only; ignored for the search
	 *                           endpoint).
	 * @param bool $allowInsecureHttp Admin opt-in for a non-HTTPS URL.
	 *
	 * @return array{allowed: bool, code: ?string, message: ?string} The verdict.
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-the-admin-configured-search-endpoint-is-exempt-from-the-private-address-block
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Not ad-hoc mode switches:
	 *   `$isAdminConfiguredEndpoint` is the spec's binding trust-tier input and
	 *   `$allowInsecureHttp` the admin's persisted non-HTTPS opt-in from config.
	 */
	public function assertSafe(
		string $url,
		bool $isAdminConfiguredEndpoint,
		array $allowlist = [],
		array $denylist = [],
		bool $allowInsecureHttp = false,
	): array {
		$parts = parse_url($url);
		if (is_array($parts) === false || empty($parts['host']) === true || empty($parts['scheme']) === true) {
			return $this->rejected(code: 'invalid_url', message: 'The URL could not be parsed.');
		}

		$scheme = strtolower((string)$parts['scheme']);
		$host = strtolower((string)$parts['host']);

		$verdict = $this->rejectionForScheme(scheme: $scheme, allowInsecureHttp: $allowInsecureHttp);
		if ($verdict !== null) {
			return $verdict;
		}

		$verdict = $this->rejectionForAllowDenyLists(
			host: $host,
			isAdminConfiguredEndpoint: $isAdminConfiguredEndpoint,
			allowlist: $allowlist,
			denylist: $denylist
		);
		if ($verdict !== null) {
			return $verdict;
		}

		$addresses = $this->resolveAddresses(host: $host);
		if ($addresses === []) {
			return $this->rejected(code: 'dns_resolution_failed', message: "Host '{$host}' could not be resolved.");
		}

		$verdict = $this->rejectionForAddresses(host: $host, addresses: $addresses, isAdminConfiguredEndpoint: $isAdminConfiguredEndpoint);
		if ($verdict !== null) {
			return $verdict;
		}

		return ['allowed' => true, 'code' => null, 'message' => null];
	}//end assertSafe()

	/**
	 * The scheme check: HTTPS is always allowed; HTTP only with the explicit opt-in;
	 * anything else is always rejected.
	 *
	 * @param string $scheme The lowercased URL scheme.
	 * @param bool $allowInsecureHttp Admin opt-in for a non-HTTPS URL.
	 *
	 * @return array{allowed: bool, code: ?string, message: ?string}|null A rejection, or null when the scheme passes.
	 */
	private function rejectionForScheme(string $scheme, bool $allowInsecureHttp): ?array {
		if ($scheme === 'https' || ($scheme === 'http' && $allowInsecureHttp === true)) {
			return null;
		}

		return $this->rejected(
			code: 'insecure_scheme',
			message: 'Only https:// URLs are allowed (enable the insecure-HTTP opt-in to allow http://).'
		);

	}//end rejectionForScheme()

	/**
	 * The denylist/allowlist check — skipped entirely for the admin-configured
	 * search endpoint (design.md: these lists govern `web.fetch` targets only).
	 *
	 * @param string $host The lowercased host.
	 * @param bool $isAdminConfiguredEndpoint Whether the denylist/allowlist should be skipped.
	 * @param string[] $allowlist Exact-hostname allowlist.
	 * @param string[] $denylist Exact-hostname denylist.
	 *
	 * @return array{allowed: bool, code: ?string, message: ?string}|null A rejection, or null when the host passes.
	 */
	private function rejectionForAllowDenyLists(string $host, bool $isAdminConfiguredEndpoint, array $allowlist, array $denylist): ?array {
		if ($isAdminConfiguredEndpoint === true) {
			return null;
		}

		if ($this->matchesHostList(host: $host, list: $denylist) === true) {
			return $this->rejected(code: 'denylisted_host', message: "Host '{$host}' is on the configured denylist.");
		}

		if ($allowlist !== [] && $this->matchesHostList(host: $host, list: $allowlist) === false) {
			return $this->rejected(code: 'not_allowlisted', message: "Host '{$host}' is not on the configured allowlist.");
		}

		return null;
	}//end rejectionForAllowDenyLists()

	/**
	 * The resolved-address checks: the cloud-metadata block (always, even for the
	 * search endpoint) and the private/loopback/link-local/RFC1918/ULA block
	 * (skipped for the search endpoint).
	 *
	 * @param string $host The lowercased host (for the error message).
	 * @param string[] $addresses The resolved addresses.
	 * @param bool $isAdminConfiguredEndpoint Whether the private-address block should be skipped.
	 *
	 * @return array{allowed: bool, code: ?string, message: ?string}|null A rejection, or null when every address passes.
	 */
	private function rejectionForAddresses(string $host, array $addresses, bool $isAdminConfiguredEndpoint): ?array {
		foreach ($addresses as $address) {
			if ($this->isCloudMetadataAddress(address: $address) === true) {
				return $this->rejected(
					code: 'metadata_address',
					message: 'The destination resolves to a cloud-metadata address, which is always blocked.'
				);
			}
		}

		if ($isAdminConfiguredEndpoint === true) {
			return null;
		}

		foreach ($addresses as $address) {
			if ($this->isPrivateOrReservedAddress(address: $address) === true) {
				return $this->rejected(
					code: 'private_address',
					message: "Host '{$host}' resolves to a private/loopback/link-local address, which is blocked."
				);
			}
		}

		return null;
	}//end rejectionForAddresses()

	/**
	 * Whether `$host` exactly matches (case-insensitive) an entry in `$list`.
	 *
	 * V1 is exact-hostname-only, not wildcard/subdomain (proposal.md Open Questions
	 * resolved in favour of the simpler, more auditable first cut).
	 *
	 * @param string $host The lowercased host to match.
	 * @param string[] $list The allow/deny list entries.
	 *
	 * @return bool
	 */
	private function matchesHostList(string $host, array $list): bool {
		foreach ($list as $entry) {
			if (strtolower((string)$entry) === $host) {
				return true;
			}
		}

		return false;
	}//end matchesHostList()

	/**
	 * Resolve a host to its A/AAAA addresses. A bare IP literal resolves to itself
	 * (no DNS lookup needed). Overridable seam for tests — mirrors NC core's own
	 * `DnsPinMiddleware::dnsGetRecord()` pattern so a test double can return a fixed
	 * address for a given hostname without a real DNS lookup.
	 *
	 * @param string $host The hostname (or IP literal) to resolve.
	 *
	 * @return string[] The resolved addresses (empty when resolution fails).
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
	 */
	protected function resolveAddresses(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$addresses = [];
		foreach ([DNS_A, DNS_AAAA] as $type) {
			$records = $this->dnsGetRecord(hostname: $host, type: $type);
			if ($records === false) {
				continue;
			}

			foreach ($records as $record) {
				if (isset($record['ip']) === true) {
					$addresses[] = (string)$record['ip'];
				} elseif (isset($record['ipv6']) === true) {
					$addresses[] = (string)$record['ipv6'];
				}
			}
		}

		return $addresses;
	}//end resolveAddresses()

	/**
	 * Thin wrapper over `dns_get_record()` — overridable in tests.
	 *
	 * @param string $hostname The hostname to resolve.
	 * @param int $type A `DNS_*` record type constant.
	 *
	 * @return array<int, array<string, mixed>>|false
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-egress-guard-blocks-ssrf-shaped-destinations-for-webfetch
	 */
	protected function dnsGetRecord(string $hostname, int $type): array|false {
		return dns_get_record($hostname, $type);
	}//end dnsGetRecord()

	/**
	 * Whether an address is a cloud-metadata address — blocked unconditionally.
	 *
	 * @param string $address The resolved address.
	 *
	 * @return bool
	 */
	private function isCloudMetadataAddress(string $address): bool {
		$normalised = $this->normaliseAddress(address: $address);
		foreach (self::CLOUD_METADATA_ADDRESSES as $metadata) {
			if ($normalised === $this->normaliseAddress(address: $metadata)) {
				return true;
			}
		}

		return false;
	}//end isCloudMetadataAddress()

	/**
	 * Whether an address is loopback, link-local, RFC 1918 private, or IPv6
	 * unique-local.
	 *
	 * Mirrors Nextcloud core's own `OC\Net\IpAddressClassifier::isLocalAddress()`
	 * technique (used by `IClientService`'s `DnsPinMiddleware`/
	 * `Client::preventLocalAddress()`): `FILTER_FLAG_NO_PRIV_RANGE` excludes RFC 1918
	 * (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16) and IPv6 unique-local (fc00::/7);
	 * `FILTER_FLAG_NO_RES_RANGE` excludes loopback (127.0.0.0/8, ::1) and link-local
	 * (169.254.0.0/16, fe80::/10) among other IANA-reserved ranges.
	 * `filter_var()` returns `false` (invalid) for an address in any of those ranges
	 * when both flags are combined — the same superset check the platform's own HTTP
	 * client applies by default, reused here (not depended on directly — that class is
	 * `@internal` to NC core) so the app produces its OWN structured error before any
	 * request is attempted.
	 *
	 * @param string $address The resolved address.
	 *
	 * @return bool
	 */
	private function isPrivateOrReservedAddress(string $address): bool {
		return (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
	}//end isPrivateOrReservedAddress()

	/**
	 * Normalise an address for comparison (packed binary form when parseable, a
	 * lowercased string otherwise) so `169.254.169.254` and any equivalent
	 * representation compare equal.
	 *
	 * @param string $address The address to normalise.
	 *
	 * @return string
	 */
	private function normaliseAddress(string $address): string {
		if (filter_var($address, FILTER_VALIDATE_IP) === false) {
			return strtolower($address);
		}

		$packed = inet_pton($address);
		if ($packed === false) {
			return strtolower($address);
		}

		return $packed;
	}//end normaliseAddress()

	/**
	 * Build a rejection verdict.
	 *
	 * @param string $code The machine error code.
	 * @param string $message The human-readable message.
	 *
	 * @return array{allowed: bool, code: ?string, message: ?string}
	 */
	private function rejected(string $code, string $message): array {
		return ['allowed' => false, 'code' => $code, 'message' => $message];
	}//end rejected()
}//end class
