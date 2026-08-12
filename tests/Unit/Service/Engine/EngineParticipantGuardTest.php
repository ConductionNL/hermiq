<?php

/**
 * Unit tests for the engine-layer participant guard.
 *
 * The Talk bridge reaches `Engine::processMessage()` from a background job
 * WITHOUT passing through `ChatController`, so this guard is not redundant
 * defense-in-depth — for that entry point it is the only check there is.
 * Getting it wrong is a cross-tenant data leak, which is why the negative case
 * is asserted directly against the engine rather than only through the
 * controller.
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
 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use Exception;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that the engine refuses a turn from a non-participant.
 *
 * @spec openspec/changes/talk-chat-bridge/specs/talk-shared-sessions/spec.md#requirement-a-session-may-be-taken-up-by-its-owner-or-a-listed-participant
 */
class EngineParticipantGuardTest extends TestCase {

	/**
	 * Message writer, asserted never to be reached on a refusal.
	 *
	 * @var MessageHistoryHandler&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $historyHandler;

	/**
	 * Engine under test.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Conversation payload the stubbed ObjectService returns.
	 *
	 * @var array
	 */
	private array $conversationData = [];

	/**
	 * Build an engine whose conversation lookup returns a fixed payload.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// A REAL ObjectEntity, not a mock: OpenRegister entities expose getters
		// via `Entity::__call`, so PHPUnit cannot configure getObject()/getUuid()
		// against the real class — a failure that only appears in CI.
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (): ObjectEntity {
				$conversation = new ObjectEntity();
				$conversation->setUuid('conv-1');
				$conversation->setObject($this->conversationData);

				return $conversation;
			}
		);

		$this->historyHandler = $this->createMock(MessageHistoryHandler::class);

		$this->engine = new Engine(
			$objectService,
			$this->createMock(ContextRetrievalHandler::class),
			$this->createMock(ResponseGenerationHandler::class),
			$this->createMock(ConversationManagementHandler::class),
			$this->historyHandler,
			$this->createMock(ContextAssembler::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A user who is neither owner nor participant is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testNonParticipantIsRefusedAtTheEngine(): void {
		$this->conversationData = ['userId' => 'alice', 'participants' => ['bob'], 'agentId' => 'a1'];

		// The refusal must happen BEFORE any message is persisted.
		$this->historyHandler->expects($this->never())->method('storeMessage');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Access denied to conversation');

		$this->engine->processMessage(
			conversationId: 'conv-1',
			userId: 'mallory',
			userMessage: 'let me in'
		);

	}//end testNonParticipantIsRefusedAtTheEngine()

	/**
	 * An empty roster still refuses everyone but the owner.
	 *
	 * @return void
	 */
	public function testEmptyRosterRefusesNonOwnerAtTheEngine(): void {
		$this->conversationData = ['userId' => 'alice', 'agentId' => 'a1'];

		$this->historyHandler->expects($this->never())->method('storeMessage');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Access denied to conversation');

		$this->engine->processMessage(
			conversationId: 'conv-1',
			userId: 'bob',
			userMessage: 'hello'
		);

	}//end testEmptyRosterRefusesNonOwnerAtTheEngine()

}//end class
