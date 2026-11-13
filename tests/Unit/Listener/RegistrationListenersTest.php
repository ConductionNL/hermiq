<?php

/**
 * Characterisation of the small hand-off listeners: the AI-oversight record
 * listener (approval id set only when the record was taken), the shareable
 * config type and flow oversight registrations (on their own event only;
 * a type that cannot be built is logged, not thrown), and Talk message
 * text extraction from rich-object JSON.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Listener
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Listener;

use OCA\Hermiq\Event\AiOversightRecordedEvent;
use OCA\Hermiq\Flow\OrganisationKillSwitchCheck;
use OCA\Hermiq\Listener\AiOversightRecordedListener;
use OCA\Hermiq\Listener\FlowOversightRegistrationListener;
use OCA\Hermiq\Listener\ShareableConfigTypeListener;
use OCA\Hermiq\Listener\TalkMessageText;
use OCA\Hermiq\Service\AiOversightService;
use OCA\Hermiq\Service\Config\HermiqAgentTemplateShareableConfigType;
use OCA\Hermiq\Service\Config\HermiqSkillShareableConfigType;
use OCA\OpenRegister\Service\Config\RegisterShareableConfigTypesEvent;
use OCA\OpenRegister\Service\Flow\RegisterFlowOversightEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hand-off listeners and Talk text extraction.
 *
 * @covers \OCA\Hermiq\Listener\AiOversightRecordedListener
 * @covers \OCA\Hermiq\Listener\ShareableConfigTypeListener
 * @covers \OCA\Hermiq\Listener\FlowOversightRegistrationListener
 * @covers \OCA\Hermiq\Listener\TalkMessageText
 * @uses   \OCA\Hermiq\Event\AiOversightRecordedEvent
 */
final class RegistrationListenersTest extends TestCase {

	/**
	 * A taken record sets the approval id on the event; a refused one leaves it unhandled.
	 *
	 * @return void
	 */
	public function testAnOversightRecordSetsTheApprovalIdOnlyWhenTaken(): void {
		$oversight = $this->createMock(AiOversightService::class);
		$oversight->method('record')->willReturnCallback(
			static fn (array $record): ?string => (($record['ok'] ?? false) === true ? 'appr-1' : null)
		);
		$listener = new AiOversightRecordedListener(oversight: $oversight);

		$taken = new AiOversightRecordedEvent(record: ['ok' => true]);
		$refused = new AiOversightRecordedEvent(record: ['ok' => false]);
		$listener->handle($taken);
		$listener->handle($refused);
		$listener->handle(new Event());

		self::assertSame(['appr-1', true], [$taken->getApprovalId(), $taken->isHandled()]);
		self::assertSame([null, false], [$refused->getApprovalId(), $refused->isHandled()]);
	}//end testAnOversightRecordSetsTheApprovalIdOnlyWhenTaken()

	/**
	 * The skill and agent-template config types are registered on their event, built through the container.
	 *
	 * @return void
	 */
	public function testTheSkillAndAgentTemplateConfigTypesAreRegistered(): void {
		$skill = $this->createMock(HermiqSkillShareableConfigType::class);
		$template = $this->createMock(HermiqAgentTemplateShareableConfigType::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[HermiqSkillShareableConfigType::class, $skill],
				[HermiqAgentTemplateShareableConfigType::class, $template],
			]
		);
		$event = new RegisterShareableConfigTypesEvent();

		$listener = new ShareableConfigTypeListener(container: $container, logger: $this->createMock(LoggerInterface::class));
		$listener->handle(new Event());
		$listener->handle($event);

		self::assertSame([$skill, $template], $event->getTypes());
	}//end testTheSkillAndAgentTemplateConfigTypesAreRegistered()

	/**
	 * A type that cannot be built is logged as a warning and the other is still registered.
	 *
	 * @return void
	 */
	public function testAnUnbuildableConfigTypeIsLoggedAndTheOtherStillRegisters(): void {
		$template = $this->createMock(HermiqAgentTemplateShareableConfigType::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($template): object {
				if ($id === HermiqSkillShareableConfigType::class) {
					throw new RuntimeException('missing dependency');
				}

				return $template;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[Hermiq] could not register the skill shareable-config type: missing dependency', ['app' => 'hermiq']);
		$event = new RegisterShareableConfigTypesEvent();

		(new ShareableConfigTypeListener(container: $container, logger: $logger))->handle($event);

		self::assertSame([$template], $event->getTypes());
	}//end testAnUnbuildableConfigTypeIsLoggedAndTheOtherStillRegisters()

	/**
	 * The organisation kill switch is registered on the flow oversight event only.
	 *
	 * @return void
	 */
	public function testTheKillSwitchIsRegisteredAsAFlowOversightCheck(): void {
		$check = $this->createMock(OrganisationKillSwitchCheck::class);
		$event = $this->createMock(RegisterFlowOversightEvent::class);
		$event->expects($this->once())->method('registerCheck')->with($this->identicalTo($check));

		$listener = new FlowOversightRegistrationListener(killSwitchCheck: $check);
		$listener->handle(new Event());
		$listener->handle($event);
	}//end testTheKillSwitchIsRegisteredAsAFlowOversightCheck()

	/**
	 * Raw Talk message to its plain text.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function messages(): array {
		return [
			'plain text'               => ['hello @bot', 'hello @bot'],
			'empty'                    => ['', ''],
			'invalid json'             => ['{not json', '{not json'],
			'json without message'     => ['{"text":"x"}', '{"text":"x"}'],
			'non-string message'       => ['{"message":5}', '{"message":5}'],
			'message, no parameters'   => ['{"message":"hi"}', 'hi'],
			'scalar parameters'        => ['{"message":"hi {x}","parameters":"x"}', 'hi {x}'],
			'mention by name'          => ['{"message":"{mention-user1} help","parameters":{"mention-user1":{"type":"user","id":"alice","name":"Alice"}}}', 'Alice help'],
			'falls back to id'         => ['{"message":"{f} ok","parameters":{"f":{"type":"file","id":"42"}}}', '42 ok'],
			'unusable parameter'       => ['{"message":"{a}{b}{c}","parameters":{"a":"x","b":{"name":""},"c":{"name":7}}}', '{a}{b}{c}'],
			'leading whitespace'       => ['  {"message":"hi"}', 'hi'],
		];
	}//end messages()

	/**
	 * Each raw message reads as its plain text.
	 *
	 * @param string $raw      The raw message.
	 * @param string $expected The plain text.
	 *
	 * @return void
	 */
	#[DataProvider('messages')]
	public function testTalkMessagePlainText(string $raw, string $expected): void {
		self::assertSame($expected, (new TalkMessageText())->plainText(raw: $raw));
	}//end testTalkMessagePlainText()
}//end class
