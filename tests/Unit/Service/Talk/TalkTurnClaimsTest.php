<?php

/**
 * Hermiq TalkTurnClaims tests.
 *
 * @category Tests
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
 *
 * @spec openspec/changes/instant-talk/tasks.md#1-claims-and-dedup
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkTurnClaims;
use OCA\Hermiq\Service\Talk\TalkTurnId;
use OCA\Hermiq\Tests\Unit\Support\InMemoryMemcache;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Claims are insert-only, TTL'd, and remember finished turns.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnClaims
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnId
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
final class TalkTurnClaimsTest extends TestCase {

	/**
	 * A claim store over the given cache.
	 *
	 * @param ICache|null $cache  The distributed cache, or null for none configured.
	 * @param array       $config App config values by key.
	 *
	 * @return TalkTurnClaims
	 */
	private function claims(?ICache $cache, array $config = []): TalkTurnClaims {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$factory->method('createDistributed')->willReturn($cache);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => ($config[$key] ?? $default)
		);

		return new TalkTurnClaims($factory, $appConfig, $this->createMock(LoggerInterface::class));
	}//end claims()

	/**
	 * The turn id is deterministic, names the agent, and is empty without a message id.
	 *
	 * @return void
	 */
	public function testTheTurnIdIsDeterministicPerRoomMessageAndAgent(): void {
		$id = TalkTurnId::derive(roomToken: 'room-1', messageId: '42', agentId: 'agent-a');

		$this->assertSame($id, TalkTurnId::derive(roomToken: 'room-1', messageId: '42', agentId: 'agent-a'));
		$this->assertSame(64, strlen($id));
		$this->assertNotSame($id, TalkTurnId::derive(roomToken: 'room-1', messageId: '42', agentId: 'agent-b'));
		$this->assertNotSame($id, TalkTurnId::derive(roomToken: 'room-1', messageId: '43', agentId: 'agent-a'));
		$this->assertSame('', TalkTurnId::derive(roomToken: 'room-1', messageId: '', agentId: 'agent-a'));
	}//end testTheTurnIdIsDeterministicPerRoomMessageAndAgent()

	/**
	 * Two stores sharing one cache: only the first claim wins, and a finished
	 * turn is refused even after its claim is gone.
	 *
	 * @return void
	 */
	public function testOnlyOneExecutorHoldsAClaimAndADoneTurnStaysDone(): void {
		$cache = new InMemoryMemcache();
		$web = $this->claims(cache: $cache);
		$cron = $this->claims(cache: $cache);

		$this->assertTrue($web->isAtomic());
		$this->assertTrue($web->claim(turnId: 'turn-1'));
		$this->assertFalse($cron->claim(turnId: 'turn-1'), 'A live claim must refuse a second executor.');

		$web->markDone(turnId: 'turn-1');
		$this->assertFalse($cache->hasKey('claim-turn-1'), 'Finishing releases the claim.');
		$this->assertFalse($cron->claim(turnId: 'turn-1'), 'A finished turn must never run again.');
	}//end testOnlyOneExecutorHoldsAClaimAndADoneTurnStaysDone()

	/**
	 * A claim that expired without the turn finishing is claimable again —
	 * the queue's crash recovery.
	 *
	 * @return void
	 */
	public function testAnExpiredClaimCanBeTakenAgain(): void {
		$cache = new InMemoryMemcache();
		$claims = $this->claims(cache: $cache);

		$this->assertTrue($claims->claim(turnId: 'turn-1'));
		$cache->expire(key: 'claim-turn-1');

		$this->assertTrue($claims->claim(turnId: 'turn-1'));
	}//end testAnExpiredClaimCanBeTakenAgain()

	/**
	 * The claim TTL is never at or below the turn timeout budget.
	 *
	 * @return void
	 */
	public function testTheClaimTtlOutlivesTheTimeoutBudget(): void {
		$cache = new InMemoryMemcache();
		$this->claims(cache: $cache, config: [TalkTurnClaims::TIMEOUT_KEY => 120])->claim(turnId: 'a');
		$this->assertSame(180, $cache->ttls['claim-a'], 'Default TTL is the budget plus a minute.');

		$this->claims(cache: $cache, config: [TalkTurnClaims::TIMEOUT_KEY => 120, TalkTurnClaims::CLAIM_TTL_KEY => 30])->claim(turnId: 'b');
		$this->assertSame(121, $cache->ttls['claim-b'], 'A TTL configured below the budget is raised above it.');
	}//end testTheClaimTtlOutlivesTheTimeoutBudget()

	/**
	 * Without a distributed memcache claims are not atomic and every claim is
	 * granted, so turns keep running as before; an empty turn id is always granted.
	 *
	 * @return void
	 */
	public function testWithoutAMemcacheClaimsAreGrantedAndNotAtomic(): void {
		$none = $this->claims(cache: null);
		$this->assertFalse($none->isAtomic());
		$this->assertTrue($none->claim(turnId: 'turn-1'));
		$this->assertTrue($none->claim(turnId: 'turn-1'));

		$plain = $this->claims(cache: $this->createMock(ICache::class));
		$this->assertFalse($plain->isAtomic(), 'A cache without add() cannot arbitrate.');

		$atomic = $this->claims(cache: new InMemoryMemcache());
		$this->assertTrue($atomic->claim(turnId: ''));
		$this->assertTrue($atomic->claim(turnId: ''));
	}//end testWithoutAMemcacheClaimsAreGrantedAndNotAtomic()

	/**
	 * A failing cache grants the claim rather than losing the turn.
	 *
	 * @return void
	 */
	public function testAFailingCacheGrantsTheClaim(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->willThrowException(new RuntimeException('redis down'));

		$this->assertTrue($this->claims(cache: $cache)->claim(turnId: 'turn-1'));
	}//end testAFailingCacheGrantsTheClaim()

	/**
	 * The instant-turn slots respect the cap and are released.
	 *
	 * @return void
	 */
	public function testInstantSlotsRespectTheCap(): void {
		$cache = new InMemoryMemcache();
		$claims = $this->claims(cache: $cache);

		$this->assertTrue($claims->takeSlot(cap: 2));
		$this->assertTrue($claims->takeSlot(cap: 2));
		$this->assertFalse($claims->takeSlot(cap: 2), 'The third concurrent instant turn stays queued.');
		$this->assertSame(2, $cache->get('instant-running'), 'A refused slot is given back.');

		$claims->releaseSlot();
		$this->assertTrue($claims->takeSlot(cap: 2));

		$this->assertFalse($this->claims(cache: null)->takeSlot(cap: 2), 'No memcache, no instant turns.');
	}//end testInstantSlotsRespectTheCap()
}//end class
