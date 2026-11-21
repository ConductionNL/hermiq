<?php

/**
 * Keeps a failing provider out of an organisation's turns for a while.
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Llm
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCP\ICache;
use OCP\ICacheFactory;
use Throwable;

/**
 * The cooldown of design D5: after a rate limit or a server error the provider
 * is skipped for the organisation for its `retry-after`, or 60 seconds, at most
 * 600. The entry lives in Nextcloud's distributed cache and expires by itself;
 * without a cache nothing cools down and every turn starts at the first hop.
 *
 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
 */
class ProviderCooldown {

	/**
	 * The cooldown when the provider sent no retry-after.
	 */
	public const DEFAULT_SECONDS = 60;

	/**
	 * The longest cooldown.
	 */
	public const MAX_SECONDS = 600;

	/**
	 * The failure kinds that start a cooldown.
	 */
	private const COOLING_KINDS = [ProviderCallFailedException::RATE_LIMIT, ProviderCallFailedException::SERVER];

	/**
	 * The distributed cache, or null when none is configured.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Creates the distributed cache.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		try {
			$this->cache = $cacheFactory->createDistributed('hermiq_provider_cooldown');
		} catch (Throwable $e) {
			$this->cache = null;
		}
	}//end __construct()

	/**
	 * Start the provider's cooldown for the organisation when the failure calls for one.
	 *
	 * @param string                      $organisation The organisation uuid ('' for none).
	 * @param string                      $provider     The provider that failed.
	 * @param ProviderCallFailedException $failure      Its typed failure.
	 *
	 * @return int The cooldown in seconds, 0 when none was started.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	public function startFor(string $organisation, string $provider, ProviderCallFailedException $failure): int {
		if ($this->cache === null || in_array($failure->kind, self::COOLING_KINDS, true) === false) {
			return 0;
		}

		$seconds = self::secondsFor(retryAfter: $failure->retryAfterSeconds);
		$this->cache->set(self::key(organisation: $organisation, provider: $provider), $seconds, $seconds);

		return $seconds;
	}//end startFor()

	/**
	 * Whether the provider is cooling down for the organisation.
	 *
	 * @param string $organisation The organisation uuid ('' for none).
	 * @param string $provider     The provider.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	public function isCooling(string $organisation, string $provider): bool {
		if ($this->cache === null) {
			return false;
		}

		return $this->cache->get(self::key(organisation: $organisation, provider: $provider)) !== null;
	}//end isCooling()

	/**
	 * The cooldown period for a retry-after.
	 *
	 * @param int|null $retryAfter The provider's retry-after in seconds, if sent.
	 *
	 * @return int Between 1 and 600 seconds.
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	public static function secondsFor(?int $retryAfter): int {
		if ($retryAfter === null || $retryAfter <= 0) {
			return self::DEFAULT_SECONDS;
		}

		return min($retryAfter, self::MAX_SECONDS);
	}//end secondsFor()

	/**
	 * The cache key, per organisation and provider.
	 *
	 * @param string $organisation The organisation uuid.
	 * @param string $provider     The provider.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/models-several-models-per-turn/specs/tenant-model-policy/spec.md#requirement-a-failing-provider-cools-down-per-organisation-req-mfail-004
	 */
	private static function key(string $organisation, string $provider): string {
		return 'fallback:'.$organisation.':'.$provider;
	}//end key()
}//end class
