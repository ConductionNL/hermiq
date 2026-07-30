<?php

/**
 * Hermiq Talk turn hand-off tests.
 *
 * Task 4.4 exists to stop the two hand-offs from drifting apart: if the
 * triggered path and the queued path each grew their own way of running a turn,
 * only one of them would ever be exercised in practice and the other would rot
 * unnoticed. These tests pin the property that makes that impossible — there is
 * exactly ONE execution route, `TalkTurnService::runTurn()`, and the choice of
 * hand-off changes only how quickly it is reached.
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
 * @spec openspec/changes/talk-chat-bridge/tasks.md#4-out-of-request-turn-execution-one-service-two-hand-offs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Cron\TalkTurnJob;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\Hermiq\Service\Talk\TalkTurnService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Both hand-offs converge on one service.
 *
 * @covers \OCA\Hermiq\Service\Talk\TalkTurnDispatcher
 * @covers \OCA\Hermiq\Cron\TalkTurnJob
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-turn-hand-off-is-event-driven-when-possible-and-queued-otherwise
 */
class TalkTurnHandOffTest extends TestCase
{

    /**
     * The turn under test.
     *
     * @var array<string, string>
     */
    private const TURN = [
        'conversationUuid' => 'conv-1',
        'speakerUid'       => 'alice',
        'message'          => 'summarise the tender',
        'roomToken'        => 'room-1',
    ];

    /**
     * Build a dispatcher whose TaskProcessing manager offers the given providers.
     *
     * @param array|null $providers Providers to advertise, or null for no manager at all.
     * @param mixed      $jobList   The job list to record enqueues on.
     *
     * @return TalkTurnDispatcher The dispatcher.
     */
    private function makeDispatcher(?array $providers, $jobList): TalkTurnDispatcher
    {
        $taskManager = null;
        if ($providers !== null) {
            $taskManager = $this->createMock(ITaskProcessingManager::class);
            $taskManager->method('getProviders')->willReturn($providers);
        }

        return new TalkTurnDispatcher(
            $jobList,
            $this->createMock(LoggerInterface::class),
            $taskManager
        );

    }//end makeDispatcher()

    /**
     * A triggerable provider, declared by name because `ITriggerableProvider`
     * is absent from the pinned OCP — the same reason the dispatcher checks it
     * with `is_a()` on a string rather than importing it.
     *
     * @return object The fake provider, exposing `trigger()`.
     */
    private function makeTriggerableProvider(): object
    {
        if (interface_exists('OCP\\TaskProcessing\\ITriggerableProvider') === false) {
            eval('namespace OCP\\TaskProcessing; interface ITriggerableProvider { public function trigger(): void; }');
        }

        return new class implements \OCP\TaskProcessing\ITriggerableProvider {

            /**
             * Whether the runner was nudged.
             *
             * @var bool
             */
            public bool $triggered = false;

            /**
             * Nudge the runner.
             *
             * @return void
             */
            public function trigger(): void
            {
                $this->triggered = true;
            }
        };

    }//end makeTriggerableProvider()

    /**
     * The queued hand-off enqueues the turn for `TalkTurnJob`.
     *
     * @return void
     */
    public function testQueuedHandOffEnqueuesTheTurnForTalkTurnJob(): void
    {
        $enqueued = [];

        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(
            function (string $job, $argument) use (&$enqueued): void {
                $enqueued[] = [$job, $argument];
            }
        );

        $path = $this->makeDispatcher(null, $jobList)->dispatch(
            conversationUuid: self::TURN['conversationUuid'],
            speakerUid: self::TURN['speakerUid'],
            message: self::TURN['message'],
            roomToken: self::TURN['roomToken']
        );

        $this->assertSame('queued', $path);
        $this->assertCount(1, $enqueued);
        $this->assertSame(TalkTurnJob::class, $enqueued[0][0]);
        $this->assertSame(self::TURN, $enqueued[0][1]);

    }//end testQueuedHandOffEnqueuesTheTurnForTalkTurnJob()

    /**
     * 🔴 The anti-divergence property: taking the triggered path does NOT give
     * the turn a second route. The fast path is a nudge on top of the same
     * durable enqueue, so the identical argument reaches the identical job —
     * only the reported path differs. If the triggered path ever stopped
     * enqueueing, a nudged runner that found nothing to pull would drop the
     * turn silently, and the queued path would be the only one anybody tested.
     *
     * @return void
     */
    public function testTriggeredHandOffEnqueuesTheSameTurnForTheSameJob(): void
    {
        $enqueued = [];

        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(
            function (string $job, $argument) use (&$enqueued): void {
                $enqueued[] = [$job, $argument];
            }
        );

        $provider = $this->makeTriggerableProvider();

        $path = $this->makeDispatcher([$provider], $jobList)->dispatch(
            conversationUuid: self::TURN['conversationUuid'],
            speakerUid: self::TURN['speakerUid'],
            message: self::TURN['message'],
            roomToken: self::TURN['roomToken']
        );

        $this->assertSame('triggered', $path);
        $this->assertTrue($provider->triggered, 'The triggerable runner was never nudged.');

        // Same job, same argument as the queued hand-off — one route, not two.
        $this->assertCount(1, $enqueued);
        $this->assertSame(TalkTurnJob::class, $enqueued[0][0]);
        $this->assertSame(self::TURN, $enqueued[0][1]);

    }//end testTriggeredHandOffEnqueuesTheSameTurnForTheSameJob()

    /**
     * The job both hand-offs land on is a pure delegate to `TalkTurnService`.
     *
     * This is the other half of the property: the two hand-offs converging on
     * one job only means anything because that job holds no turn logic of its
     * own to diverge from.
     *
     * @return void
     */
    public function testTheJobBothHandOffsLandOnDelegatesToTalkTurnService(): void
    {
        $seen = null;

        $service = $this->createMock(TalkTurnService::class);
        $service->method('runTurn')->willReturnCallback(
            function (string $conversationUuid, string $speakerUid, string $message, string $roomToken) use (&$seen): bool {
                $seen = [
                    'conversationUuid' => $conversationUuid,
                    'speakerUid'       => $speakerUid,
                    'message'          => $message,
                    'roomToken'        => $roomToken,
                ];
                return true;
            }
        );

        $job = new TalkTurnJob($this->createMock(ITimeFactory::class), $service);

        // `run()` is protected — it is core's entry point, not ours. No
        // setAccessible(): it has been a no-op since PHP 8.1 and is deprecated
        // in 8.5, which is the runtime this suite runs on.
        $run = new \ReflectionMethod(TalkTurnJob::class, 'run');
        $run->invoke($job, self::TURN);

        $this->assertSame(self::TURN, $seen, 'The job did not hand the turn to TalkTurnService unchanged.');

    }//end testTheJobBothHandOffsLandOnDelegatesToTalkTurnService()

    /**
     * An incomplete turn is dropped rather than handed on half-formed.
     *
     * @return void
     */
    public function testAnIncompleteTurnIsNotHandedToTheService(): void
    {
        $service = $this->createMock(TalkTurnService::class);
        $service->expects($this->never())->method('runTurn');

        $job = new TalkTurnJob($this->createMock(ITimeFactory::class), $service);

        $run = new \ReflectionMethod(TalkTurnJob::class, 'run');
        $run->invoke($job, ['conversationUuid' => 'conv-1', 'speakerUid' => '', 'message' => 'hi', 'roomToken' => 'room-1']);

    }//end testAnIncompleteTurnIsNotHandedToTheService()
}//end class
