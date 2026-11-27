<?php

/**
 * Unit tests for TalkChainBudget: the hop budget and the per-room rate cap.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
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

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkChainBudget;
use OCA\Hermiq\Tests\Unit\Support\InMemoryMemcache;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see TalkChainBudget}.
 *
 * @spec openspec/changes/instant-talk/tasks.md#7-verify
 */
final class TalkChainBudgetTest extends TestCase {

	/**
	 * A budget over the given limits and cache.
	 *
	 * @param int                   $maxHops The configured hop budget.
	 * @param int                   $rate    The configured per-minute cap.
	 * @param InMemoryMemcache|null $cache   The distributed cache; null when none is available.
	 *
	 * @return TalkChainBudget
	 */
	private function budget(int $maxHops, int $rate, ?InMemoryMemcache $cache): TalkChainBudget {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => match ($key) {
				TalkChainBudget::MAX_HOPS_KEY => $maxHops,
				TalkChainBudget::RATE_KEY => $rate,
				default => $default,
			}
		);
		$caches = $this->createMock(originalClassName: ICacheFactory::class);
		$caches->method('isAvailable')->willReturn($cache !== null);
		$caches->method('createDistributed')->willReturn($cache ?? new InMemoryMemcache());

		return new TalkChainBudget(appConfig: $config, cacheFactory: $caches);
	}//end budget()

	/**
	 * The hop budget is read from the config and never negative.
	 *
	 * @return void
	 */
	public function testTheHopBudgetIsNeverNegative(): void {
		$this->assertSame(expected: 5, actual: $this->budget(maxHops: 5, rate: 6, cache: null)->maxHops());
		$this->assertSame(expected: 0, actual: $this->budget(maxHops: -2, rate: 6, cache: null)->maxHops());
	}//end testTheHopBudgetIsNeverNegative()

	/**
	 * Turns within the per-minute cap are counted in; the one over it is refused.
	 *
	 * @return void
	 */
	public function testTheRateCapAdmitsUpToItsLimitPerRoom(): void {
		$budget = $this->budget(maxHops: 3, rate: 2, cache: new InMemoryMemcache());

		$this->assertTrue(condition: $budget->takeRateSlot(roomToken: 'room-1'));
		$this->assertTrue(condition: $budget->takeRateSlot(roomToken: 'room-1'));
		$this->assertFalse(condition: $budget->takeRateSlot(roomToken: 'room-1'));
		$this->assertTrue(condition: $budget->takeRateSlot(roomToken: 'room-2'), message: 'Each room has its own count.');
	}//end testTheRateCapAdmitsUpToItsLimitPerRoom()

	/**
	 * Without a memcache, or with a cap of zero, no agent-triggered turn is taken.
	 *
	 * @return void
	 */
	public function testNoCounterOrAZeroCapRefusesEveryTurn(): void {
		$this->assertFalse(condition: $this->budget(maxHops: 3, rate: 6, cache: null)->takeRateSlot(roomToken: 'room-1'));
		$this->assertFalse(condition: $this->budget(maxHops: 3, rate: 0, cache: new InMemoryMemcache())->takeRateSlot(roomToken: 'room-1'));
	}//end testNoCounterOrAZeroCapRefusesEveryTurn()
}//end class
