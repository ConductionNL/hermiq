<?php

/**
 * Hermiq TalkTurnClaims.
 *
 * The claim store behind exactly-once Talk turns. An executor claims a turn id
 * before it runs the turn; a second executor that finds a live claim, or the
 * turn already done, exits without side effects.
 *
 * Decision (instant-talk task 1.2): the store is Nextcloud's distributed
 * memcache, not an OpenRegister object. `ICache::add()` is set-if-absent with a
 * TTL in one call on every distributed backend core ships (Redis, Memcached,
 * APCu), which is the atomic insert-only claim this needs; an OpenRegister
 * object create is a read-then-write through the object API and cannot refuse
 * a concurrent duplicate. Where no distributed cache is configured, claims
 * cannot be atomic, so `isAtomic()` says so: the dispatcher then keeps the
 * queued job as the only executor (core removes a queued job before running
 * it, so the queue alone still runs each enqueued turn once), and a claim is
 * granted without a store so turns keep running exactly as before this change.
 *
 * Two keys per turn: the claim (TTL longer than the turn timeout budget, so a
 * slow turn is not run twice, and short enough that a crashed one is recovered
 * by the queue) and the done marker, written when the turn finishes, so the
 * queued job that runs after the claim expired does not answer again.
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
 * @spec openspec/changes/instant-talk/tasks.md#1-claims-and-dedup
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Talk;

use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Atomic, TTL'd claims on Talk turn ids.
 *
 * @psalm-api
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
class TalkTurnClaims {

	/**
	 * App config key: the turn timeout budget in seconds.
	 *
	 * @var string
	 */
	public const TIMEOUT_KEY = 'talk.turnTimeoutSeconds';

	/**
	 * App config key: the claim TTL in seconds.
	 *
	 * @var string
	 */
	public const CLAIM_TTL_KEY = 'talk.turnClaimTtlSeconds';

	/**
	 * Default turn timeout budget, in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_TIMEOUT = 300;

	/**
	 * How long a finished turn stays recorded as done, in seconds (one week).
	 *
	 * Longer than any background-job backlog an instance recovers from, so a
	 * queued job that runs late still finds the marker.
	 *
	 * @var int
	 */
	private const DONE_TTL = 604800;

	/**
	 * The cache, or null when no distributed memcache is configured.
	 *
	 * @var IMemcache|null
	 */
	private ?IMemcache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory Creates the distributed cache.
	 * @param IAppConfig      $appConfig    Reads the timeout and TTL settings.
	 * @param LoggerInterface $logger       PSR-3 logger.
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		if ($cacheFactory->isAvailable() === false) {
			return;
		}

		// Only a memcache has the atomic add(); a plain ICache (core's file
		// or null cache) cannot arbitrate a claim.
		$cache = $cacheFactory->createDistributed('hermiq-talk-turns');
		if ($cache instanceof IMemcache) {
			$this->cache = $cache;
		}
	}//end __construct()

	/**
	 * Whether claims are atomic on this instance.
	 *
	 * @return bool True when a distributed cache backs the claims.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function isAtomic(): bool {
		return $this->cache !== null;
	}//end isAtomic()

	/**
	 * The turn timeout budget in seconds.
	 *
	 * @return int At least one second.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function timeoutSeconds(): int {
		return max(1, $this->appConfig->getValueInt('hermiq', self::TIMEOUT_KEY, self::DEFAULT_TIMEOUT));
	}//end timeoutSeconds()

	/**
	 * The claim TTL in seconds: the configured value, never at or below the
	 * timeout budget, so a turn still inside its budget is never claimed twice.
	 *
	 * @return int The TTL.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function claimTtlSeconds(): int {
		$timeout = $this->timeoutSeconds();
		$ttl = $this->appConfig->getValueInt('hermiq', self::CLAIM_TTL_KEY, ($timeout + 60));

		return max(($timeout + 1), $ttl);
	}//end claimTtlSeconds()

	/**
	 * Claim a turn id. Insert-only: true for exactly one caller while the claim
	 * lives, false for every other one and for a turn that is already done.
	 *
	 * An empty turn id (a turn whose Talk message id is unknown) is granted
	 * without a store, as is every turn where no distributed cache exists.
	 *
	 * @param string $turnId The turn id.
	 *
	 * @return bool True when the caller now holds the claim and runs the turn.
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function claim(string $turnId): bool {
		if ($turnId === '' || $this->cache === null) {
			return true;
		}

		try {
			if ($this->cache->get('done-' . $turnId) !== null) {
				return false;
			}

			return $this->cache->add('claim-' . $turnId, '1', $this->claimTtlSeconds());
		} catch (Throwable $e) {
			// A cache that fails cannot arbitrate. Refusing would lose the turn
			// for good (every executor would refuse); granting risks one double
			// answer, which is the lesser failure — and it is logged.
			$this->logger->warning(
				message: '[TalkTurnClaims] The claim store failed; the turn runs unclaimed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'turnId' => $turnId,
					'error' => $e->getMessage(),
				]
			);
			return true;
		}//end try
	}//end claim()

	/**
	 * Record a turn as done and release its claim.
	 *
	 * @param string $turnId The turn id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
	 */
	public function markDone(string $turnId): void {
		if ($turnId === '' || $this->cache === null) {
			return;
		}

		try {
			$this->cache->set('done-' . $turnId, '1', self::DONE_TTL);
			$this->cache->remove('claim-' . $turnId);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TalkTurnClaims] Could not record the turn as done; its claim expires on its own',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'turnId' => $turnId,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end markDone()

	/**
	 * Take one of the limited instant-execution slots.
	 *
	 * @param int $cap The most instant turns allowed at once.
	 *
	 * @return bool True when a slot was taken; release it with `releaseSlot()`.
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	public function takeSlot(int $cap): bool {
		if ($this->cache === null || $cap < 1) {
			return false;
		}

		try {
			$running = $this->cache->inc('instant-running');
			if (is_int($running) === true && $running <= $cap) {
				return true;
			}

			$this->cache->dec('instant-running');
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkTurnClaims] Could not count instant turns; the turn stays queued',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}

		return false;
	}//end takeSlot()

	/**
	 * Release an instant-execution slot taken with `takeSlot()`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
	 */
	public function releaseSlot(): void {
		if ($this->cache === null) {
			return;
		}

		try {
			$this->cache->dec('instant-running');
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[TalkTurnClaims] Could not release an instant-turn slot',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}
	}//end releaseSlot()
}//end class
