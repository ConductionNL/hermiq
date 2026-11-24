<?php

/**
 * Minimal OpenRegister FederatedConfigService stub for standalone unit runs and static analysis.
 *
 * Only the method hermiq calls, with the signature of openregister
 * lib/Service/Config/FederatedConfigService.php. Registered at TEST TIME only;
 * the real class wins whenever OpenRegister is enabled. Tests extend it to
 * answer a search.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

/**
 * The shared federated config engine.
 */
class FederatedConfigService {

	/**
	 * Discover published bundles and say how the search went.
	 *
	 * @param string              $topic        The discovery topic.
	 * @param string              $term         A free-text term, '' for none.
	 * @param string|null         $credentialId A broker credential, or null.
	 * @param BrokerIdentity|null $identity     The app and acting user.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>, brokerUsed: bool, rateLimited: bool, status: int}
	 */
	public function discoverOutcome(string $topic, string $term = '', ?string $credentialId = null, ?BrokerIdentity $identity = null): array {
		return ['outcome' => 'ok', 'cards' => [], 'brokerUsed' => false, 'rateLimited' => false, 'status' => 200];
	}//end discoverOutcome()

	/**
	 * Read a package's files out of a GitHub repository at a ref.
	 *
	 * @param string              $repo         The `owner/repo`.
	 * @param string|null         $ref          A branch, tag or commit; the default branch when null.
	 * @param array<int, string>  $paths        Exact paths, and prefixes ending in `/`; [] reads every file.
	 * @param string|null         $credentialId Optional broker credential.
	 * @param BrokerIdentity|null $identity     The app and acting user a brokered read names.
	 *
	 * @return array{files: array<string, string>, truncated: bool, source: string}
	 */
	public function fetchPackage(
		string $repo,
		?string $ref = null,
		array $paths = [],
		?string $credentialId = null,
		?BrokerIdentity $identity = null
	): array {
		return ['files' => [], 'truncated' => false, 'source' => 'none'];
	}//end fetchPackage()
}//end class
