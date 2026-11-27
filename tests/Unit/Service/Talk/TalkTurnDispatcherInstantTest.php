<?php

/**
 * Hermiq TalkTurnDispatcher instant-path tests.
 *
 * Which immediate path a turn takes — triggerable runner, post-response run,
 * or the queue alone — and that the sender's request never waits for the turn.
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
 * @spec openspec/changes/instant-talk/tasks.md#2-immediate-execution-paths
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\BackgroundJob\TalkTurnJob;
use OCA\Hermiq\Kernel\Contracts\TalkTurnServiceInterface;
use OCA\Hermiq\Service\Talk\TalkInstantPath;
use OCA\Hermiq\Service\Talk\TalkPostResponseRunner;
use OCA\Hermiq\Service\Talk\TalkTurnClaims;
use OCA\Hermiq\Service\Talk\TalkTurn;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\Hermiq\Service\Talk\TalkTurnExecutor;
use OCA\Hermiq\Tests\Unit\Support\InMemoryMemcache;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Path selection and the non-blocking hand-off.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnDispatcher
 * @covers \OCA\Hermiq\Service\Talk\TalkPostResponseRunner
 * @uses \OCA\Hermiq\Service\Talk\TalkTurnExecutor
 * @uses \OCA\Hermiq\Service\Talk\TalkTurnClaims
 *
 * @spec openspec/changes/instant-talk/specs/instant-talk/spec.md#requirement-turns-execute-immediately-with-claim-based-exactly-once-semantics
 */
final class TalkTurnDispatcherInstantTest extends TestCase {

	/**
	 * Enqueued job arguments.
	 *
	 * @var array<int, array>
	 */
	private array $enqueued = [];

	/**
	 * Work handed to the post-response runner, not yet run.
	 *
	 * @var array<int, callable>
	 */
	private array $deferred = [];

	/**
	 * Turns the service ran.
	 *
	 * @var integer
	 */
	private int $ran = 0;

	/**
	 * A dispatcher with the given instant-path pieces.
	 *
	 * @param InMemoryMemcache|null $cache     The memcache, or null for none.
	 * @param array                 $providers TaskProcessing providers.
	 * @param bool                  $enabled   The `talk.instantTurns` switch.
	 * @param int                   $cap       The `talk.instantTurnsMax` cap.
	 *
	 * @return TalkTurnDispatcher
	 */
	private function dispatcher(?InMemoryMemcache $cache, array $providers = [], bool $enabled = true, int $cap = 2): TalkTurnDispatcher {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $job, $argument): void {
				$this->assertSame(TalkTurnJob::class, $job);
				$this->enqueued[] = $argument;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$factory->method('createDistributed')->willReturn($cache);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => ($key === 'talk.instantTurnsMax' ? $cap : $default)
		);
		$appConfig->method('getValueBool')->willReturn($enabled);
		$logger = $this->createMock(LoggerInterface::class);
		$claims = new TalkTurnClaims($factory, $appConfig, $logger);

		$service = $this->createMock(TalkTurnServiceInterface::class);
		$service->method('runTurn')->willReturnCallback(
			function (): bool {
				$this->ran++;
				return true;
			}
		);

		$postResponse = $this->createMock(TalkPostResponseRunner::class);
		$postResponse->method('defer')->willReturnCallback(
			function (callable $work, int $budgetSeconds): void {
				$this->assertSame(TalkTurnClaims::DEFAULT_TIMEOUT, $budgetSeconds);
				$this->deferred[] = $work;
			}
		);

		$taskManager = $this->createMock(ITaskProcessingManager::class);
		$taskManager->method('getProviders')->willReturn($providers);

		return new TalkTurnDispatcher(
			$jobList,
			$logger,
			$taskManager,
			new TalkInstantPath(
				executor: new TalkTurnExecutor(claims: $claims, service: $service),
				claims: $claims,
				postResponse: $postResponse,
				appConfig: $appConfig
			)
		);
	}//end dispatcher()

	/**
	 * Hand off one turn with an id.
	 *
	 * @param TalkTurnDispatcher $dispatcher The dispatcher.
	 * @param string             $turnId     The turn id.
	 *
	 * @return string The path.
	 */
	private function handOff(TalkTurnDispatcher $dispatcher, string $turnId = 'turn-1'): string {
		$turn = new TalkTurn(conversationUuid: 'conv-1', speakerUid: 'alice');
		$turn->message = 'Hallo';
		$turn->roomToken = 'room-1';
		$turn->turnId = $turnId;
		$turn->agentId = 'agent-a';
		$turn->messageId = '42';

		return $dispatcher->dispatch(turn: $turn);
	}//end handOff()

	/**
	 * Without a runner, the turn is scheduled after the response: dispatch
	 * returns before the turn runs, and the queued job still holds it.
	 *
	 * @return void
	 */
	public function testWithoutARunnerTheTurnRunsAfterTheResponse(): void {
		$cache = new InMemoryMemcache();
		$dispatcher = $this->dispatcher(cache: $cache);

		$this->assertSame('instant', $this->handOff(dispatcher: $dispatcher));
		$this->assertSame(0, $this->ran, 'The sender\'s request must not wait for the turn.');
		$this->assertCount(1, $this->enqueued, 'The queued job is the safety net, always enqueued.');
		$this->assertSame('turn-1', $this->enqueued[0]['turnId']);
		$this->assertSame('42', $this->enqueued[0]['messageId']);

		($this->deferred[0])();
		$this->assertSame(1, $this->ran);
		$this->assertSame(0, $cache->get('instant-running'), 'The slot is released after the turn.');
	}//end testWithoutARunnerTheTurnRunsAfterTheResponse()

	/**
	 * A registered triggerable runner is nudged and nothing runs in-process.
	 *
	 * @return void
	 */
	public function testARegisteredRunnerIsNudgedInstead(): void {
		$provider = $this->createMock('OCP\\TaskProcessing\\ITriggerableProvider');
		$provider->expects($this->once())->method('trigger');

		$this->assertSame('triggered', $this->handOff(dispatcher: $this->dispatcher(cache: new InMemoryMemcache(), providers: [$provider])));
		$this->assertSame([], $this->deferred);
	}//end testARegisteredRunnerIsNudgedInstead()

	/**
	 * Without atomic claims, without a turn id, or switched off, the turn stays queued.
	 *
	 * @return void
	 */
	public function testWithoutAtomicClaimsOrATurnIdTheTurnIsQueued(): void {
		$this->assertSame('queued', $this->handOff(dispatcher: $this->dispatcher(cache: null)));
		$this->assertSame('queued', $this->handOff(dispatcher: $this->dispatcher(cache: new InMemoryMemcache()), turnId: ''));
		$this->assertSame('queued', $this->handOff(dispatcher: $this->dispatcher(cache: new InMemoryMemcache(), enabled: false)));

		$this->assertSame([], $this->deferred);
		$this->assertCount(3, $this->enqueued);
		$this->assertArrayNotHasKey('turnId', $this->enqueued[1], 'An empty turn id is not enqueued.');
	}//end testWithoutAtomicClaimsOrATurnIdTheTurnIsQueued()

	/**
	 * With the cap full the deferred turn does not run; the queue keeps it.
	 *
	 * @return void
	 */
	public function testAFullCapLeavesTheTurnOnTheQueue(): void {
		$cache = new InMemoryMemcache();
		$cache->set('instant-running', 1);

		$this->assertSame('instant', $this->handOff(dispatcher: $this->dispatcher(cache: $cache, cap: 1)));
		($this->deferred[0])();

		$this->assertSame(0, $this->ran);
		$this->assertNull($cache->get('claim-turn-1'), 'An overflow turn is not claimed, so the job can run it.');
	}//end testAFullCapLeavesTheTurnOnTheQueue()

	/**
	 * The post-response runner runs the work and swallows its failure.
	 *
	 * @return void
	 */
	public function testThePostResponseRunnerRunsTheWorkAndContainsFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$runner = new TalkPostResponseRunner($logger);

		$ran = false;
		$runner->runAfterResponse(
			work: function () use (&$ran): void {
				$ran = true;
			},
			budgetSeconds: 0
		);
		$this->assertTrue($ran);

		$runner->runAfterResponse(
			work: static function (): void {
				throw new \RuntimeException('model down');
			},
			budgetSeconds: 0
		);
	}//end testThePostResponseRunnerRunsTheWorkAndContainsFailures()
}//end class
