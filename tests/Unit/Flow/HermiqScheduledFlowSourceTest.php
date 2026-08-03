<?php
/**
 * hermiq's agentflows must be visible to OpenRegister's scheduler.
 *
 * OpenRegister's scheduler enumerated ONE hard-coded store and never asked the
 * resolvers, so an agentflow with `trigger: schedule` could not fire — no error,
 * no run, nothing. The instance held ZERO runs with trigger `schedule` across
 * 52,478 runs while `hydra-sequencer`, `hydra-dispatch` and `hydra-lock-reaper`
 * all declared one. This is the answer to the enumeration that closes it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Flow;

use OCA\Hermiq\Flow\HermiqFlowResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\IScheduledFlowSource;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Flow\HermiqFlowResolver
 */
class HermiqScheduledFlowSourceTest extends TestCase
{


    /**
     * A resolver whose agentflow store holds the given objects.
     *
     * @param array<int, ObjectEntity>|RuntimeException $flows What findAll yields.
     *
     * @return HermiqFlowResolver The resolver.
     */
    private function resolverListing(array | RuntimeException $flows): HermiqFlowResolver
    {
        $objects = $this->createMock(ObjectService::class);
        if ($flows instanceof RuntimeException) {
            $objects->method('findAll')->willThrowException($flows);
        } else {
            $objects->method('findAll')->willReturn($flows);
        }

        return new HermiqFlowResolver(
            $objects,
            $this->createMock(RegisterMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end resolverListing()


    /**
     * An agentflow object.
     *
     * @param string      $uuid  The uuid.
     * @param array       $data  The object data.
     * @param string|null $owner The entity owner.
     *
     * @return ObjectEntity The object.
     */
    private function agentflow(string $uuid, array $data, ?string $owner=null): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setObject($data);
        if ($owner !== null) {
            $object->setOwner($owner);
        }

        return $object;
    }//end agentflow()


    /**
     * The resolver declares the capability, so the registry will ask it.
     *
     * The registry selects sources with `instanceof`. Implementing the method
     * without the interface would make every one of these assertions pass while
     * the scheduler still never saw a single hermiq flow.
     *
     * @return void
     */
    public function testTheResolverIsAScheduledFlowSource(): void
    {
        $this->assertInstanceOf(IScheduledFlowSource::class, $this->resolverListing([]));
    }//end testTheResolverIsAScheduledFlowSource()


    /**
     * A scheduled agentflow is reported, with its cron and owner.
     *
     * @return void
     */
    public function testAScheduledAgentflowIsReported(): void
    {
        $resolver = $this->resolverListing(
            [
                $this->agentflow(
                    'seq-uuid',
                    ['enabled' => true, 'trigger' => 'schedule', 'cron' => '*/5 * * * *'],
                    'admin'
                ),
            ]
        );

        $candidates = $resolver->scheduledFlows();

        $this->assertCount(1, $candidates);
        $this->assertSame('seq-uuid', $candidates[0]['id']);
        $this->assertSame('*/5 * * * *', $candidates[0]['cron']);
        $this->assertSame('schedule', $candidates[0]['trigger']);
        $this->assertSame('admin', $candidates[0]['owner']);
        $this->assertTrue($candidates[0]['enabled']);
    }//end testAScheduledAgentflowIsReported()


    /**
     * Event-triggered and manual agentflows are NOT offered to the scheduler.
     *
     * Blast radius: the scheduler now enumerates every contributing app on every
     * instance in the fleet. Only flows that genuinely declare a schedule may
     * appear, or the fleet pays for a per-tick walk of every flow it owns.
     *
     * @return void
     */
    public function testOnlyScheduleTriggeredAgentflowsAreReported(): void
    {
        $resolver = $this->resolverListing(
            [
                $this->agentflow('sched', ['enabled' => true, 'trigger' => 'schedule', 'cron' => '* * * * *']),
                $this->agentflow('evented', ['enabled' => true, 'trigger' => 'object.created']),
                $this->agentflow('manual', ['enabled' => true, 'trigger' => 'manual']),
            ]
        );

        $this->assertSame(['sched'], array_column($resolver->scheduledFlows(), 'id'));
    }//end testOnlyScheduleTriggeredAgentflowsAreReported()


    /**
     * A DISABLED schedule is reported with `enabled` false, not omitted.
     *
     * Every hydra agentflow currently ships `enabled: false` deliberately.
     * Reporting the flag rather than filtering on it keeps the "a disabled flow
     * never runs" decision in the scheduler, where it is made once for the whole
     * fleet, instead of in each app that owns flows.
     *
     * @return void
     */
    public function testADisabledScheduleIsReportedAsDisabled(): void
    {
        $resolver = $this->resolverListing(
            [
                $this->agentflow('off', ['enabled' => false, 'trigger' => 'schedule', 'cron' => '* * * * *']),
            ]
        );

        $candidates = $resolver->scheduledFlows();

        $this->assertCount(1, $candidates);
        $this->assertFalse($candidates[0]['enabled']);
    }//end testADisabledScheduleIsReportedAsDisabled()


    /**
     * With no entity owner, the flow's own `owner` field is used.
     *
     * A scheduled run has no session, so a run with no owner cannot write
     * anything (or#2158).
     *
     * @return void
     */
    public function testTheOwnerFieldIsTheFallback(): void
    {
        $resolver = $this->resolverListing(
            [
                $this->agentflow(
                    'f',
                    ['enabled' => true, 'trigger' => 'schedule', 'cron' => '* * * * *', 'owner' => 'bob']
                ),
            ]
        );

        $this->assertSame('bob', $resolver->scheduledFlows()[0]['owner']);
    }//end testTheOwnerFieldIsTheFallback()


    /**
     * An unreadable store yields nothing rather than throwing at the scheduler.
     *
     * @return void
     */
    public function testAnUnreadableStoreYieldsNoScheduledFlows(): void
    {
        $resolver = $this->resolverListing(new RuntimeException('no such register'));

        $this->assertSame([], $resolver->scheduledFlows());
    }//end testAnUnreadableStoreYieldsNoScheduledFlows()
}//end class
