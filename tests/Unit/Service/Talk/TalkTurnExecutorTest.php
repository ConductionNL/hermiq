<?php

/**
 * Hermiq TalkTurnExecutor tests: exactly-once over two executors.
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

use OCA\Hermiq\Kernel\Contracts\TalkTurnServiceInterface;
use OCA\Hermiq\Service\Talk\TalkTurnClaims;
use OCA\Hermiq\Service\Talk\TalkTurnExecutor;
use OCA\Hermiq\Tests\Unit\Support\InMemoryMemcache;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Claim, run, record.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnExecutor
 * @uses \OCA\Hermiq\Service\Talk\TalkTurnClaims
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
final class TalkTurnExecutorTest extends TestCase {

	/**
	 * A handed-off turn with an id.
	 *
	 * @var array<string, mixed>
	 */
	private const TURN = [
		'conversationUuid' => 'conv-1',
		'speakerUid' => 'alice',
		'message' => 'Hallo',
		'roomToken' => 'room-1',
		'turnId' => 'turn-1',
		'agentId' => 'agent-a',
		'messageId' => '42',
		'chain' => ['hops' => 1],
	];

	/**
	 * The runTurn calls the service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * An executor over the shared cache, recording runTurn calls.
	 *
	 * @param InMemoryMemcache $cache The cache both executors share.
	 *
	 * @return TalkTurnExecutor
	 */
	private function executor(InMemoryMemcache $cache): TalkTurnExecutor {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$claims = new TalkTurnClaims($factory, $appConfig, $this->createMock(LoggerInterface::class));

		$service = $this->createMock(TalkTurnServiceInterface::class);
		$service->method('runTurn')->willReturnCallback(
			function (...$args): bool {
				$this->calls[] = $args;
				return true;
			}
		);

		return new TalkTurnExecutor($claims, $service);
	}//end executor()

	/**
	 * A redelivered turn — the instant path and the queued job both handed the
	 * same message — runs exactly once.
	 *
	 * @return void
	 */
	public function testADoubleDeliveryRunsTheTurnOnce(): void {
		$cache = new InMemoryMemcache();

		$this->assertSame('ran', $this->executor(cache: $cache)->execute(argument: self::TURN));
		$this->assertSame('skipped', $this->executor(cache: $cache)->execute(argument: self::TURN));

		$this->assertCount(1, $this->calls);
		$this->assertSame(
			['conv-1', 'alice', 'Hallo', 'room-1', 'agent-a', '42', ['hops' => 1]],
			$this->calls[0]
		);
	}//end testADoubleDeliveryRunsTheTurnOnce()

	/**
	 * The queued job finds a live claim of the instant path and does nothing.
	 *
	 * @return void
	 */
	public function testALiveClaimElsewhereSkipsTheTurn(): void {
		$cache = new InMemoryMemcache();
		$cache->add('claim-turn-1', '1', 360);

		$this->assertSame('skipped', $this->executor(cache: $cache)->execute(argument: self::TURN));
		$this->assertSame([], $this->calls);
	}//end testALiveClaimElsewhereSkipsTheTurn()

	/**
	 * A process that died mid-turn leaves a claim but no done marker; once the
	 * claim expires, the queued job runs the turn.
	 *
	 * @return void
	 */
	public function testACrashedTurnIsRecoveredOnceItsClaimExpires(): void {
		$cache = new InMemoryMemcache();
		$cache->add('claim-turn-1', '1', 360);
		$cache->expire(key: 'claim-turn-1');

		$this->assertSame('ran', $this->executor(cache: $cache)->execute(argument: self::TURN));
		$this->assertCount(1, $this->calls);
		$this->assertSame('1', $cache->get('done-turn-1'));
	}//end testACrashedTurnIsRecoveredOnceItsClaimExpires()

	/**
	 * A job enqueued before instant-talk (no turn id) still runs, unclaimed.
	 *
	 * @return void
	 */
	public function testALegacyArgumentRunsUnclaimed(): void {
		$legacy = ['conversationUuid' => 'conv-1', 'speakerUid' => 'alice', 'message' => 'Hallo', 'roomToken' => 'room-1'];
		$cache = new InMemoryMemcache();

		$this->assertSame('ran', $this->executor(cache: $cache)->execute(argument: $legacy));
		$this->assertSame('ran', $this->executor(cache: $cache)->execute(argument: $legacy));
		$this->assertSame('', $this->calls[0][4]);
		$this->assertSame([], $this->calls[0][6]);
	}//end testALegacyArgumentRunsUnclaimed()

	/**
	 * A malformed argument is dropped.
	 *
	 * @return void
	 */
	public function testAnInvalidArgumentIsDropped(): void {
		$executor = $this->executor(cache: new InMemoryMemcache());

		$this->assertSame('invalid', $executor->execute(argument: 'nope'));
		$this->assertSame('invalid', $executor->execute(argument: array_merge(self::TURN, ['roomToken' => ''])));
		$this->assertSame([], $this->calls);
	}//end testAnInvalidArgumentIsDropped()
}//end class
