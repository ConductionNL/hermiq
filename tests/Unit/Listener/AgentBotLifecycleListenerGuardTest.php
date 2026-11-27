<?php

/**
 * Guard-path tests for AgentBotLifecycleListener.
 *
 * The bot follows the agent, so only an identifiable agent write may install
 * or remove one: any other event, and an agent with no uuid to key the bot
 * on, must leave the installer untouched.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\AgentBotLifecycleListener;
use OCA\Hermiq\Service\Agent\AgentObjectMatcher;
use OCA\Hermiq\Service\Talk\TalkBotInstaller;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the events the bot lifecycle must ignore.
 *
 * @covers \OCA\Hermiq\Listener\AgentBotLifecycleListener
 * @uses   \OCA\Hermiq\Service\Agent\AgentObjectMatcher
 *
 * @spec openspec/specs/talk-agent-sessions/spec.md#requirement-an-agents-bot-follows-the-agents-lifecycle
 */
class AgentBotLifecycleListenerGuardTest extends TestCase {

	/**
	 * The bot installer.
	 *
	 * @var TalkBotInstaller&MockObject
	 */
	private $installer;

	/**
	 * Listener under test.
	 *
	 * @var AgentBotLifecycleListener
	 */
	private AgentBotLifecycleListener $listener;

	/**
	 * Build the listener over a matcher that recognises Hermiq's agent schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->installer = $this->createMock(TalkBotInstaller::class);

		$schema = new Schema();
		$schema->setSlug('agent');
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$register = new Register();
		$register->setSlug('hermiq');
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$this->listener = new AgentBotLifecycleListener(
			$this->installer,
			new AgentObjectMatcher($schemaMapper, $registerMapper),
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * An event that is not an object write installs and removes nothing.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventLeavesTheBotAlone(): void {
		$this->installer->expects($this->never())->method('installForAgent');
		$this->installer->expects($this->never())->method('uninstallForAgent');

		$this->listener->handle(new Event());

	}//end testAnUnrelatedEventLeavesTheBotAlone()

	/**
	 * An agent with no uuid has nothing to key a bot on, so none is installed.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutAUuidGetsNoBot(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('');
		$agent->setSchema('4365');
		$agent->setRegister('2428');
		$agent->setObject(['name' => 'Nameless', 'talkEnabled' => true]);

		$this->installer->expects($this->never())->method('installForAgent');
		$this->installer->expects($this->never())->method('uninstallForAgent');

		$this->listener->handle(new ObjectCreatedEvent(object: $agent));

	}//end testAnAgentWithoutAUuidGetsNoBot()

	/**
	 * The same agent with a uuid does get its bot, so the guard above is the uuid alone.
	 *
	 * @return void
	 */
	public function testTheSameAgentWithAUuidGetsItsBot(): void {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-uuid-9');
		$agent->setSchema('4365');
		$agent->setRegister('2428');
		$agent->setObject(['name' => 'Named', 'talkEnabled' => true]);

		$this->installer->expects($this->once())
			->method('installForAgent')
			->with('agent-uuid-9', 'Named');

		$this->listener->handle(new ObjectCreatedEvent(object: $agent));

	}//end testTheSameAgentWithAUuidGetsItsBot()
}//end class
