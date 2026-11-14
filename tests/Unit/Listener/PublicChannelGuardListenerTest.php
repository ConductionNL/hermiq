<?php

/**
 * A public chat channel is refused on an agent with tools, and a new one gets its token.
 *
 * Pins delivery-public-web-chat task 6 (REQ-PUBCHAT-001) through the real before-save events.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-an-organisation-admin-declares-a-public-chat-channel-req-pubchat-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Listener\PublicChannelGuardListener;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\PublicChat\PublicChannelStore;
use OCA\Hermiq\Service\PublicChat\PublicChatGate;
use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Listener\PublicChannelGuardListener
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicChatGate
 * @uses   \OCA\Hermiq\Service\PublicChat\PublicChannelStore
 * @uses   \OCA\Hermiq\Service\TenantKillSwitch
 * @uses   \OCA\Hermiq\Service\Agent\AgentAvailability
 */
class PublicChannelGuardListenerTest extends TestCase {

	/**
	 * The listener over agents by id.
	 *
	 * @return PublicChannelGuardListener
	 */
	private function listener(): PublicChannelGuardListener {
		$store = $this->getMockBuilder(PublicChannelStore::class)->disableOriginalConstructor()->onlyMethods(['agent', 'isToken'])->getMock();
		$store->method('agent')->willReturnCallback(function (string $id): ?ObjectEntity {
			$tools = ['plain' => [], 'none' => ['__none__'], 'mailer' => ['hermiq.sendMail']];
			if (isset($tools[$id]) === false) {
				return null;
			}

			$agent = new ObjectEntity();
			$agent->setUuid($id);
			$agent->setObject(['tools' => $tools[$id]]);
			return $agent;
		});
		$store->method('isToken')->willReturnCallback(fn (string $t): bool => preg_match('/^[A-Za-z0-9]{16,64}$/', $t) === 1);

		$gate = new PublicChatGate(
			$store,
			$this->createMock(AiFeatureService::class),
			new TenantKillSwitch($this->createMock(ObjectService::class), $this->createMock(LoggerInterface::class)),
			new AgentAvailability(),
			$this->createMock(ICacheFactory::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class)
		);

		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('agentpublicchannel');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(fn (int $id): Schema => ($id === 7 ? $schema : $this->createMock(Schema::class)));
		$register = $this->createMock(Register::class);
		$register->method('getSlug')->willReturn('hermiq');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->with(32, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('Minted0123456789Minted0123456789');

		return new PublicChannelGuardListener($schemas, $registers, $store, $gate, $random, $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * A channel object.
	 *
	 * @param array<string, mixed> $data   The channel's fields.
	 * @param string               $schema The schema id or slug.
	 *
	 * @return ObjectEntity
	 */
	private static function channel(array $data, string $schema='agentpublicchannel'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setRegister('3');
		$object->setObject(array_merge(['name' => 'Website', 'kind' => 'website'], $data));
		return $object;
	}//end channel()

	/**
	 * An agent with a tool grant cannot be public.
	 *
	 * @return void
	 */
	public function testAnAgentWithToolsCannotBePublic(): void {
		$event = new ObjectCreatingEvent(self::channel(['agentId' => 'mailer']));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['agentId' => 'A public assistant cannot use tools. Pick an agent without tools.'], $event->getErrors());
	}//end testAnAgentWithToolsCannotBePublic()

	/**
	 * Picking an agent with tools on an update is refused too, and so is no agent at all.
	 *
	 * @return void
	 */
	public function testAnUpdateToAnAgentWithToolsOrNoAgentIsRefused(): void {
		$update = new ObjectUpdatingEvent(self::channel(['agentId' => 'mailer', 'channelToken' => 'Kept0123456789abcdef'], schema: '7'), self::channel(['agentId' => 'plain']));
		$this->listener()->handle($update);
		self::assertSame(['agentId' => PublicChannelGuardListener::REFUSED_TOOLS], $update->getErrors());

		$none = new ObjectCreatingEvent(self::channel(['agentId' => 'gone']));
		$this->listener()->handle($none);
		self::assertSame(['agentId' => 'Pick the agent that answers on this channel.'], $none->getErrors());
	}//end testAnUpdateToAnAgentWithToolsOrNoAgentIsRefused()

	/**
	 * A tool-free channel is saved, and a new one gets a random token; an existing token is kept.
	 *
	 * @return void
	 */
	public function testAToolFreeChannelGetsItsToken(): void {
		$create = new ObjectCreatingEvent(self::channel(['agentId' => 'none', 'allowedOrigins' => ['https://www.example.nl']]));
		$this->listener()->handle($create);
		self::assertFalse($create->isPropagationStopped());
		self::assertSame(['channelToken' => 'Minted0123456789Minted0123456789'], $create->getModifiedData());

		$update = new ObjectUpdatingEvent(self::channel(['agentId' => 'plain', 'channelToken' => 'Kept0123456789abcdef'], schema: '7'));
		$this->listener()->handle($update);
		self::assertFalse($update->isPropagationStopped());
		self::assertSame([], $update->getModifiedData());
	}//end testAToolFreeChannelGetsItsToken()

	/**
	 * An allowed origin with a path, or not an origin at all, is refused.
	 *
	 * @return void
	 */
	public function testAnOriginThatIsNotOneIsRefused(): void {
		$event = new ObjectCreatingEvent(self::channel(['agentId' => 'plain', 'allowedOrigins' => ['https://www.example.nl', 'https://www.example.nl/chat']]));

		$this->listener()->handle($event);

		self::assertSame(['allowedOrigins' => PublicChannelGuardListener::REFUSED_ORIGIN], $event->getErrors());
	}//end testAnOriginThatIsNotOneIsRefused()

	/**
	 * Other objects, other events and an already stopped save are left alone.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreLeftAlone(): void {
		$listener = $this->listener();

		$agent = new ObjectCreatingEvent(self::channel(['agentId' => 'mailer'], schema: '8'));
		$listener->handle($agent);
		self::assertFalse($agent->isPropagationStopped());

		$slugless = new ObjectCreatingEvent(self::channel(['agentId' => 'mailer'], schema: 'agent'));
		$listener->handle($slugless);
		self::assertFalse($slugless->isPropagationStopped());

		$stopped = new ObjectCreatingEvent(self::channel(['agentId' => 'mailer']));
		$stopped->stopPropagation();
		$listener->handle($stopped);
		self::assertSame([], $stopped->getErrors());

		$plain = $this->createMock(Event::class);
		$plain->expects(self::never())->method('stopPropagation');
		$listener->handle($plain);
	}//end testOtherObjectsAreLeftAlone()
}//end class
