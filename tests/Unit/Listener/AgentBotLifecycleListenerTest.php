<?php

declare(strict_types=1);

/**
 * AgentBotLifecycleListener unit tests (talk-agent-sessions).
 *
 * These cover the ROUTING — which agent write leads to which spreed call — and
 * deliberately stop there. Whether spreed actually installs, renames or removes
 * the row is proven live, because that behaviour lives in another app's mapper
 * and a double would only assert what this test already assumes. Two facts
 * found that way and NOT discoverable here: the bot secret must be unique per
 * agent (a unique index on `secret` alone), and `BotUninstallEvent` takes
 * (secret, url) rather than (url, secret).
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\AgentBotLifecycleListener;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Listener\AgentBotLifecycleListener
 */
class AgentBotLifecycleListenerTest extends TestCase
{

    private TalkBotInstaller&MockObject $installer;

    private AgentBotLifecycleListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installer = $this->createMock(TalkBotInstaller::class);

        // ObjectEntity::getSchema() yields the schema's NUMERIC id, never its
        // slug, so the fixtures below carry '4365' — asserting against 'agent'
        // would test a shape the database never produces.
        //
        // Both mappers are stubbed because BOTH checks are load-bearing: this
        // instance really does have two schemas slugged `agent`, so the register
        // is what tells Hermiq's apart from another app's.
        $schema = new Schema();
        $schema->setSlug('agent');
        $other = new Schema();
        $other->setSlug('conversation');
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willReturnCallback(
            static fn (int|string $id): Schema => ((int) $id === 4365) ? $schema : $other
        );

        $register = new Register();
        $register->setSlug('hermiq');
        $foreign = new Register();
        $foreign->setSlug('pipelinq');
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willReturnCallback(
            static fn (int|string $id): Register => ((int) $id === 2428) ? $register : $foreign
        );

        $this->listener = new AgentBotLifecycleListener(
            $this->installer,
            $schemaMapper,
            $registerMapper,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * Build an agent object.
     *
     * @param bool   $talkEnabled Whether the agent opted into Talk.
     * @param string $schema      The schema slug.
     *
     * @return ObjectEntity The object.
     */
    private function makeAgent(bool $talkEnabled=true, string $schema='4365'): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('agent-uuid-1');
        $object->setSchema($schema);
        $object->setRegister('2428');
        $object->setObject(['name' => 'Release Notes Agent', 'talkEnabled' => $talkEnabled]);

        return $object;

    }//end makeAgent()

    /**
     * A Talk-enabled agent gets its bot installed under its own name.
     */
    public function testCreatingATalkEnabledAgentInstallsItsBot(): void
    {
        $this->installer->expects($this->once())
            ->method('installForAgent')
            ->with('agent-uuid-1', 'Release Notes Agent');
        $this->installer->expects($this->never())->method('uninstallForAgent');

        $this->listener->handle(new ObjectCreatedEvent(object: $this->makeAgent()));

    }//end testCreatingATalkEnabledAgentInstallsItsBot()

    /**
     * A rename is the same call — spreed's install is an upsert on (url, secret).
     */
    public function testRenamingAnAgentReinstallsUnderTheNewName(): void
    {
        $renamed = $this->makeAgent();
        $renamed->setObject(['name' => 'Release Notes Agent v2', 'talkEnabled' => true]);

        $this->installer->expects($this->once())
            ->method('installForAgent')
            ->with('agent-uuid-1', 'Release Notes Agent v2');

        $this->listener->handle(new ObjectUpdatedEvent(newObject: $renamed, oldObject: $this->makeAgent()));

    }//end testRenamingAnAgentReinstallsUnderTheNewName()

    /**
     * Deleting an agent removes its bot.
     */
    public function testDeletingAnAgentUninstallsItsBot(): void
    {
        $this->installer->expects($this->once())->method('uninstallForAgent')->with('agent-uuid-1');
        $this->installer->expects($this->never())->method('installForAgent');

        $this->listener->handle(new ObjectDeletedEvent(object: $this->makeAgent()));

    }//end testDeletingAnAgentUninstallsItsBot()

    /**
     * 🔴 Turning Talk OFF must remove the bot, not merely stop using it.
     *
     * A bot left installed stays addressable in every room it was enabled in,
     * so "disabled" would still answer — which is the opposite of what the
     * operator asked for.
     */
    public function testDisablingTalkUninstallsTheBot(): void
    {
        $this->installer->expects($this->once())->method('uninstallForAgent')->with('agent-uuid-1');
        $this->installer->expects($this->never())->method('installForAgent');

        $this->listener->handle(
            new ObjectUpdatedEvent(newObject: $this->makeAgent(talkEnabled: false), oldObject: $this->makeAgent())
        );

    }//end testDisablingTalkUninstallsTheBot()

    /**
     * Objects of other schemas are none of this listener's business — it is
     * registered on the GLOBAL object events, so it sees every write in the
     * instance and must ignore almost all of them.
     */
    public function testOtherSchemasAreIgnored(): void
    {
        $this->installer->expects($this->never())->method('installForAgent');
        $this->installer->expects($this->never())->method('uninstallForAgent');

        $this->listener->handle(new ObjectCreatedEvent(object: $this->makeAgent(schema: '5018')));

    }//end testOtherSchemasAreIgnored()

    /**
     * A failing installer must never break the agent write that triggered it.
     */
    public function testAnInstallerFailureNeverBreaksTheAgentWrite(): void
    {
        $this->installer->method('installForAgent')->willThrowException(new \RuntimeException('talk down'));

        $this->listener->handle(new ObjectCreatedEvent(object: $this->makeAgent()));

        $this->addToAssertionCount(1);

    }//end testAnInstallerFailureNeverBreaksTheAgentWrite()
}//end class
