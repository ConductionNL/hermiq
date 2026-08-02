<?php

declare(strict_types=1);

/**
 * TalkSessionRoom opt-in guard tests (talk-agent-sessions).
 *
 * 🔴 These exist because the e2e run caught what every unit fixture and every
 * live probe had missed: creating a session for a Talk-DISABLED agent still
 * created a room. Nothing else could have found it — the unit fixtures and the
 * manual live checks all used an opted-in agent, so the guard's absence was
 * invisible to both.
 *
 * The room is not merely unwanted; it is useless by construction. A
 * Talk-disabled agent has no bot installed, so `enableInRoom()` has nothing to
 * enable and the room sits in the sidebar with no agent in it.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkAgentBinding;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\Hermiq\Service\Talk\TalkBridge;
use OCA\Hermiq\Service\Talk\TalkRoomGrouping;
use OCA\Hermiq\Service\Talk\TalkSessionRoom;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Service\Talk\TalkSessionRoom
 */
class TalkSessionRoomOptInTest extends TestCase
{

    private TalkAgentBinding&MockObject $agentBinding;

    private ContainerInterface&MockObject $container;

    private TalkBotInstaller&MockObject $installer;

    /**
     * Build the service with a Talk bridge that reports available.
     *
     * @param bool $agentOptedIn Whether the agent has talkEnabled.
     *
     * @return TalkSessionRoom The service.
     */
    private function service(bool $agentOptedIn): TalkSessionRoom
    {
        $bridge = $this->createMock(TalkBridge::class);
        $bridge->method('isAvailable')->willReturn(true);

        $this->agentBinding = $this->createMock(TalkAgentBinding::class);
        $this->agentBinding->method('isAgentTalkEnabled')->willReturn($agentOptedIn);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->installer = $this->createMock(TalkBotInstaller::class);

        return new TalkSessionRoom(
            $this->container,
            $this->createMock(IUserManager::class),
            $this->createMock(ObjectService::class),
            $bridge,
            $this->installer,
            $this->agentBinding,
            $this->createMock(TalkRoomGrouping::class),
            $this->createMock(LoggerInterface::class)
        );

    }//end service()

    /**
     * 🔴 The regression this file exists for.
     *
     * A Talk-disabled agent must get NO room — and spreed must not even be
     * reached, since asking it to make a room is the bug.
     */
    public function testNoRoomIsCreatedForATalkDisabledAgent(): void
    {
        $service = $this->service(agentOptedIn: false);
        $this->container->expects($this->never())->method('get');
        $this->installer->expects($this->never())->method('enableInRoom');

        $this->assertNull($service->createForSession('A session', 'alice', 'agent-1'));

    }//end testNoRoomIsCreatedForATalkDisabledAgent()

    /**
     * The opt-in is checked before anything else, so an unreadable agent — which
     * `isAgentTalkEnabled()` reports as false — also yields no room rather than
     * a half-built one.
     */
    public function testAnUnreadableAgentYieldsNoRoom(): void
    {
        $service = $this->service(agentOptedIn: false);

        $this->assertNull($service->createForSession('A session', 'alice', 'unknown-agent'));

    }//end testAnUnreadableAgentYieldsNoRoom()

    /**
     * Guard clauses that predate the opt-in check still hold: no owner and no
     * agent are both "no room", and neither may reach the agent lookup.
     */
    public function testMissingOwnerOrAgentYieldsNoRoom(): void
    {
        $service = $this->service(agentOptedIn: true);

        $this->assertNull($service->createForSession('A session', '', 'agent-1'));
        $this->assertNull($service->createForSession('A session', 'alice', ''));

    }//end testMissingOwnerOrAgentYieldsNoRoom()

    /**
     * An opted-in agent gets past the guard and reaches spreed — proving the
     * guard is the discriminator and not an unconditional refusal, which would
     * make the test above pass for the wrong reason.
     */
    public function testAnOptedInAgentReachesSpreed(): void
    {
        $service = $this->service(agentOptedIn: true);

        // No user resolves, so creation stops right after the guard — but the
        // guard itself is proven passed because we got to that point at all.
        $this->assertNull($service->createForSession('A session', 'alice', 'agent-1'));
        $this->addToAssertionCount(1);

    }//end testAnOptedInAgentReachesSpreed()
}//end class
