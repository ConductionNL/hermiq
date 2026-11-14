<?php

/**
 * Each human turn records where it was typed: `talk` from a Talk room,
 * `hermiq` from the Chat page (chat-carry-a-conversation-across-channels).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-carry-a-conversation-across-channels/specs/session-channel-continuity/spec.md#requirement-each-turn-records-where-it-was-typed-req-schan-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Where a turn was typed, from the writer and through the engine.
 */
class TurnChannelTest extends TestCase {

	/**
	 * Store one message and return the object handed to OpenRegister.
	 *
	 * @param string      $role    The role.
	 * @param string|null $channel The channel passed.
	 *
	 * @return array<string, mixed> The saved payload.
	 */
	private function stored(string $role, ?string $channel): array {
		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved): ObjectEntity {
				$saved = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('turn-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		(new MessageHistoryHandler($objectService, new NullLogger()))->storeMessage(
			conversationId: 'sess-1',
			role: $role,
			content: 'Wat is de status van de inkoop?',
			channel: $channel
		);

		return $saved;
	}//end stored()

	/**
	 * The register's own SessionTurn.channel property.
	 *
	 * @return array<string, mixed> The property.
	 */
	private static function channelProperty(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'), true);

		return $register['components']['schemas']['SessionTurn']['properties']['channel'];
	}//end channelProperty()

	public function testATurnFromTalkIsStoredAsTalkAndOneFromTheChatPageAsHermiq(): void {
		$this->assertSame('talk', $this->stored('user', 'talk')['channel'] ?? null);
		$this->assertSame('hermiq', $this->stored('user', 'hermiq')['channel'] ?? null);

		// The value written is one the register declares.
		$enum = self::channelProperty()['enum'];
		$this->assertSame(['talk', 'hermiq'], $enum);
		$this->assertSame(MessageHistoryHandler::TURN_CHANNELS, $enum);
	}//end testATurnFromTalkIsStoredAsTalkAndOneFromTheChatPageAsHermiq()

	public function testOnlyAHumanTurnWithAKnownChannelCarriesOne(): void {
		$this->assertArrayNotHasKey('channel', $this->stored('assistant', 'talk'), 'An answer is not typed anywhere.');
		$this->assertArrayNotHasKey('channel', $this->stored('user', null), 'Unset reads as hermiq.');
		$this->assertArrayNotHasKey('channel', $this->stored('user', 'sms'), 'An unknown channel is never written.');
		$this->assertArrayNotHasKey('channel', $this->stored('user', ''));
	}//end testOnlyAHumanTurnWithAKnownChannelCarriesOne()

	public function testTheRegisterDeclaresTheChannelOptional(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/hermiq_register.json'), true);
		$turn = $register['components']['schemas']['SessionTurn'];

		$this->assertSame('string', self::channelProperty()['type']);
		$this->assertNotContains('channel', ($turn['required'] ?? []));
		$this->assertTrue(version_compare((string)$register['info']['version'], '0.54.0', '>='), 'The import is gated on info.version.');
	}//end testTheRegisterDeclaresTheChannelOptional()

	/**
	 * The channel the engine hands to the writer for the user turn.
	 *
	 * @param string|null $turnChannel The channel the caller passes.
	 *
	 * @return mixed The channel argument of the stored user turn.
	 */
	private function engineChannel(?string $turnChannel): mixed {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (): ObjectEntity {
				$conversation = new ObjectEntity();
				$conversation->setUuid('sess-1');
				$conversation->setObject(['userId' => 'anne']);
				return $conversation;
			}
		);

		$captured = 'not stored';
		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('storeMessage')->willReturnCallback(
			static function (...$args) use (&$captured): void {
				// By position, in the order of storeMessage(): channel is the 9th.
				$captured = ($args[8] ?? null);
				throw new RuntimeException('stop after the user turn is stored');
			}
		);

		$engine = new Engine(
			$objectService,
			$this->createMock(ContextRetrievalHandler::class),
			$this->createMock(ResponseGenerationHandler::class),
			$this->createMock(ConversationManagementHandler::class),
			$history,
			$this->createMock(ContextAssembler::class),
			new NullLogger(),
			null,
			null,
			new ConversationParticipation(),
			$this->createMock(IUserManager::class)
		);

		try {
			$engine->processMessage(conversationId: 'sess-1', userId: 'anne', userMessage: 'Hallo', turnChannel: $turnChannel);
		} catch (Throwable) {
			// The sentinel stops the run once the user turn is stored.
		}

		return $captured;
	}//end engineChannel()

	public function testTheEngineStoresTheChannelOnTheUserTurn(): void {
		$this->assertSame('talk', $this->engineChannel('talk'));
		$this->assertSame('hermiq', $this->engineChannel('hermiq'));
		$this->assertNull($this->engineChannel(null), 'A caller that names no channel leaves it unset.');
	}//end testTheEngineStoresTheChannelOnTheUserTurn()
}//end class
