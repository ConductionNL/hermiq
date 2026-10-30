<?php

/**
 * A turn in a session with participants records who asked it, whatever the
 * entry point (chat-work-together-in-one-session). The web chat and the stream
 * pass no author; the engine fills it in when the session is shared, so /chat
 * can show a colleague's name above their question. A session without
 * participants keeps storing no author, as before.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
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
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Tests the author captured on a shared session's user turn.
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
 */
class EngineSharedSessionAuthorTest extends TestCase {

	/**
	 * Run one turn as $uid in a session with the given roster; return the storeMessage author args.
	 *
	 * @param array<int, string> $participants The roster.
	 * @param string $uid The speaker.
	 *
	 * @return array{0: ?string, 1: ?string} [authorId, authorDisplayName].
	 */
	private function authorOfTurn(array $participants, string $uid): array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function () use ($participants): ObjectEntity {
				$conversation = new ObjectEntity();
				$conversation->setUuid('sess-1');
				$conversation->setObject(['userId' => 'anne', 'participants' => $participants]);
				return $conversation;
			}
		);

		$captured = [null, null];
		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('storeMessage')->willReturnCallback(
			static function (...$args) use (&$captured): void {
				// PHPUnit hands the callback the arguments by position, in the order of
				// MessageHistoryHandler::storeMessage(): authorId is 6th, the name 7th.
				$captured = [$args[5] ?? null, $args[6] ?? null];
				throw new RuntimeException('stop after the user turn is stored');
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $id): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn(['bram' => 'Bram Jansen', 'anne' => 'Anne de Vries'][$id] ?? $id);
				return $user;
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
			$users
		);

		try {
			$engine->processMessage(conversationId: 'sess-1', userId: $uid, userMessage: 'Wat staat er over groen?');
		} catch (Throwable) {
			// The sentinel stops the run once the user turn is stored.
		}

		return $captured;
	}//end authorOfTurn()

	/**
	 * A participant's turn in a shared session carries their uid and name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testASharedSessionRecordsWhoAsked(): void {
		$this->assertSame(['bram', 'Bram Jansen'], $this->authorOfTurn(['bram'], 'bram'));
		$this->assertSame(['anne', 'Anne de Vries'], $this->authorOfTurn(['bram'], 'anne'), 'The owner is named too once others are in it.');

	}//end testASharedSessionRecordsWhoAsked()

	/**
	 * A session with nobody else in it stores no author, as before.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/session-participants/spec.md#requirement-an-invited-colleague-reads-and-takes-turns-req-spart-002
	 */
	public function testASingleSpeakerSessionIsUnchanged(): void {
		$this->assertSame([null, null], $this->authorOfTurn([], 'anne'));

	}//end testASingleSpeakerSessionIsUnchanged()
}//end class
