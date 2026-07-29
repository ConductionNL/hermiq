<?php

/**
 * Unit tests for TalkBridgeStatus.
 *
 * The panel this backs exists so an administrator never has to reach for `occ`
 * or SQL to answer "why is this agent replying here?". Its most important
 * property is therefore that it RENDERS rather than errors — including on an
 * instance with no Talk at all, where every spreed call would throw.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkBridgeStatus;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkTurnDispatcher;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the admin-panel status reporter.
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-chat-bridge/spec.md#requirement-administrators-can-see-the-bridges-configuration
 */
class TalkBridgeStatusTest extends TestCase
{

    /**
     * Talk availability probe.
     *
     * @var TalkBridge&\PHPUnit\Framework\MockObject\MockObject
     */
    private $bridge;

    /**
     * Room→agent map.
     *
     * @var TalkAgentBinding&\PHPUnit\Framework\MockObject\MockObject
     */
    private $agentBinding;

    /**
     * Hand-off path reporter.
     *
     * @var TalkTurnDispatcher&\PHPUnit\Framework\MockObject\MockObject
     */
    private $dispatcher;

    /**
     * Grouping support probe.
     *
     * @var TalkRoomGrouping&\PHPUnit\Framework\MockObject\MockObject
     */
    private $grouping;

    /**
     * Server container.
     *
     * @var ContainerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $container;

    /**
     * Service under test.
     *
     * @var TalkBridgeStatus
     */
    private TalkBridgeStatus $status;

    /**
     * Wire mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bridge       = $this->createMock(TalkBridge::class);
        $this->agentBinding = $this->createMock(TalkAgentBinding::class);
        $this->dispatcher   = $this->createMock(TalkTurnDispatcher::class);
        $this->grouping     = $this->createMock(TalkRoomGrouping::class);
        $this->container    = $this->createMock(ContainerInterface::class);

        $this->status = new TalkBridgeStatus(
            $this->bridge,
            $this->agentBinding,
            $this->dispatcher,
            $this->grouping,
            $this->createMock(ObjectService::class),
            $this->container,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * With Talk absent the panel still gets a renderable payload.
     *
     * @return void
     */
    public function testTalkAbsentStillDescribes(): void
    {
        $this->bridge->method('isAvailable')->willReturn(false);
        $this->grouping->method('isSupported')->willReturn(false);
        $this->dispatcher->method('hasTriggerableProvider')->willReturn(false);

        // No spreed class may be resolved when Talk is unavailable.
        $this->container->expects($this->never())->method('get');

        $result = $this->status->describe();

        $this->assertFalse($result['talkAvailable']);
        $this->assertFalse($result['botInstalled']);
        $this->assertSame([], $result['rooms']);
        $this->assertSame('queued', $result['handOffPath']);

    }//end testTalkAbsentStillDescribes()

    /**
     * A spreed call that throws degrades to a renderable payload, not a 500.
     *
     * @return void
     */
    public function testSpreedFailureDegradesGracefully(): void
    {
        $this->bridge->method('isAvailable')->willReturn(true);
        $this->grouping->method('isSupported')->willReturn(true);
        $this->dispatcher->method('hasTriggerableProvider')->willReturn(false);
        $this->container->method('get')->willThrowException(new RuntimeException('spreed exploded'));

        $result = $this->status->describe();

        $this->assertTrue($result['talkAvailable']);
        $this->assertFalse($result['botInstalled'], 'An unresolvable bot mapper means "not installed", not an error.');
        $this->assertSame([], $result['rooms']);

    }//end testSpreedFailureDegradesGracefully()

    /**
     * The hand-off path is reported honestly.
     *
     * `queued` is the truthful answer until a triggerable runner exists, and
     * the panel must not imply replies are immediate when they are not.
     *
     * @return void
     */
    public function testHandOffPathReflectsTriggerableProvider(): void
    {
        $this->bridge->method('isAvailable')->willReturn(false);
        $this->grouping->method('isSupported')->willReturn(false);
        $this->dispatcher->method('hasTriggerableProvider')->willReturn(true);

        $this->assertSame('triggered', $this->status->describe()['handOffPath']);

    }//end testHandOffPathReflectsTriggerableProvider()

    /**
     * The bot address reported is the in-process app scheme.
     *
     * @return void
     */
    public function testReportsTheAppSchemeBotUrl(): void
    {
        $this->bridge->method('isAvailable')->willReturn(false);
        $this->grouping->method('isSupported')->willReturn(false);
        $this->dispatcher->method('hasTriggerableProvider')->willReturn(false);

        $this->assertStringStartsWith('nextcloudapp://', $this->status->describe()['botUrl']);

    }//end testReportsTheAppSchemeBotUrl()
}//end class
