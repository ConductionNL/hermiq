<?php

/**
 * Hermiq TalkChainBudget
 *
 * The two numeric limits of an agent-to-agent chain: the hop budget a chain
 * rooted at a human turn may spend, and the per-room cap on agent-triggered
 * turns per minute, counted in a distributed memcache.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCP\IAppConfig;
use OCP\ICacheFactory;

/**
 * The hop budget and the per-room rate cap of agent-to-agent chains.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
 */
class TalkChainBudget {

	/**
	 * The hop budget key and its default.
	 */
	public const MAX_HOPS_KEY = 'talk.agentChainMaxHops';

	public const DEFAULT_MAX_HOPS = 3;

	/**
	 * The per-room rate cap key and its default.
	 */
	public const RATE_KEY = 'talk.agentChainRoomRatePerMinute';

	public const DEFAULT_RATE = 6;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    The hop budget and the rate cap.
	 * @param ICacheFactory $cacheFactory Counts the per-room rate.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#7-verify
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * The most agent-triggered turns one chain may take (never negative).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function maxHops(): int {
		return max(0, $this->appConfig->getValueInt('hermiq', self::MAX_HOPS_KEY, self::DEFAULT_MAX_HOPS));
	}//end maxHops()

	/**
	 * Count one agent-triggered turn against the room's per-minute cap.
	 *
	 * @param string $roomToken The room.
	 *
	 * @return bool True when the turn is within the cap; false without a memcache.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-agent-to-agent-turns-are-mention-only-bounded-and-human-rooted
	 */
	public function takeRateSlot(string $roomToken): bool {
		$limit = max(0, $this->appConfig->getValueInt('hermiq', self::RATE_KEY, self::DEFAULT_RATE));
		if ($limit === 0 || $this->cacheFactory->isAvailable() === false) {
			return false;
		}

		$cache = $this->cacheFactory->createDistributed('hermiq_talk_chain');
		if (($cache instanceof \OCP\IMemcache) === false) {
			return false;
		}

		$key = 'rate-' . $roomToken . '-' . (int)floor(time() / 60);
		$cache->add($key, 0, 120);
		$count = $cache->inc($key);

		return (is_int($count) === true && $count <= $limit);
	}//end takeRateSlot()
}//end class
