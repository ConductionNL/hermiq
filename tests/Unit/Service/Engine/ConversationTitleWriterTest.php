<?php

/**
 * Unit tests for ConversationTitleWriter (session-context-performance).
 *
 * The writer runs detached from the reply, so its risks are different from the
 * synchronous code it replaces: it has no session, so it must impersonate the
 * conversation's owner; it must not overwrite a title the user has set in the
 * meantime; it must not blank the conversation via PUT-semantic save; it must
 * still enforce the org's model policy; and it must never throw at a reply that
 * has already been delivered.
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
 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\ConversationTitleWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the deferred conversation-title writer.
 *
 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
 */
class ConversationTitleWriterTest extends TestCase {

	/**
	 * The session the writer impersonates through.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Resolves the owner UID.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * Ordered record of what the writer did, in the order it did it.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Wire a session in which `alice` exists and nobody is logged in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = [];

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			function (string $uid) use ($alice): ?IUser {
				if ($uid === 'alice') {
					return $alice;
				}

				return null;
			}
		);

		$this->userSession = $this->createMock(IUserSession::class);
		// A job has no session user; record every switch so ordering can be asserted.
		$this->userSession->method('getUser')->willReturn(null);
		$this->userSession->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$uid = 'null';
				if ($user !== null) {
					$uid = $user->getUID();
				}

				$this->calls[] = 'setUser:' . $uid;
			}
		);

	}//end setUp()

	/**
	 * A conversation ObjectEntity.
	 *
	 * @param array<string, mixed> $payload The conversation object data.
	 * @param string $organisation The organisation the conversation belongs to.
	 *
	 * @return ObjectEntity
	 */
	private function conversation(array $payload, string $organisation = ''): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('conv-1');
		$entity->setObject($payload);
		if ($organisation !== '') {
			$entity->setOrganisation($organisation);
		}

		return $entity;
	}//end conversation()

	/**
	 * An ObjectService whose find() returns the given conversation.
	 *
	 * @param ObjectEntity|null $conversation The conversation to return.
	 *
	 * @return ObjectService&MockObject
	 */
	private function objectService(?ObjectEntity $conversation): ObjectService&MockObject {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($conversation);
		return $objectService;
	}//end objectService()

	/**
	 * Build the writer under test.
	 *
	 * @param ObjectService&MockObject $objectService The object service.
	 * @param ConversationManagementHandler&MockObject $handler The title handler.
	 *
	 * @return ConversationTitleWriter
	 */
	private function writer(ObjectService $objectService, ConversationManagementHandler $handler): ConversationTitleWriter {
		return new ConversationTitleWriter(
			$objectService,
			$handler,
			$this->userSession,
			$this->userManager,
			new NullLogger()
		);

	}//end writer()

	/**
	 * The owner is impersonated around the whole job, and released afterwards.
	 *
	 * This is the regression the unit suite previously could not see. Mocks have no
	 * RBAC and no credential broker, so a writer that ran as nobody passed every test
	 * while doing nothing at all in production: the broker refused to resolve a
	 * credential for an unauthenticated principal (so the title silently degraded to a
	 * fallback string) and OpenRegister then refused the write outright — `User
	 * 'Anonymous' does not have permission to 'update' objects in schema 'Conversation'`.
	 * Ordering is asserted, not just occurrence: impersonating AFTER generating would
	 * leave the credential lookup anonymous and reproduce the bug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function testTheOwnerIsImpersonatedAroundGenerationAndTheWriteThenReleased(): void {
		$objectService = $this->objectService(
			$this->conversation(['title' => 'New conversation', 'userId' => 'alice'])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function () : ObjectEntity {
				$this->calls[] = 'save';
				return new ObjectEntity();
			}
		);

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->method('generateConversationTitle')->willReturnCallback(
			function () : string {
				$this->calls[] = 'generate';
				return 'Leave policy';
			}
		);
		$handler->method('ensureUniqueTitle')->willReturn('Leave policy');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'What is our leave policy?',
			userId: 'alice'
		);

		$this->assertSame(
			['setUser:alice', 'generate', 'save', 'setUser:null'],
			$this->calls,
			'The title must be generated AND written while impersonating the owner, and the '
				. 'prior session user restored afterwards.'
		);

	}//end testTheOwnerIsImpersonatedAroundGenerationAndTheWriteThenReleased()

	/**
	 * The session is released even when the write blows up.
	 *
	 * Jobs share a worker process, so a leaked session would hand the next job this
	 * user's identity — a far worse bug than an unnamed conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function testTheSessionIsReleasedEvenWhenTheWriteThrows(): void {
		$objectService = $this->objectService(
			$this->conversation(['title' => 'New conversation', 'userId' => 'alice'])
		);
		$objectService->method('saveObject')->willThrowException(new RuntimeException('DB down'));

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->method('generateConversationTitle')->willReturn('Leave policy');
		$handler->method('ensureUniqueTitle')->willReturn('Leave policy');

		try {
			$this->writer($objectService, $handler)->write(
				conversationId: 'conv-1',
				userMessage: 'What is our leave policy?',
				userId: 'alice'
			);
		} catch (RuntimeException $e) {
			// The throw is not what is under test here; the session release is.
		}

		$this->assertSame('setUser:null', end($this->calls), 'The impersonated session must be released.');

	}//end testTheSessionIsReleasedEvenWhenTheWriteThrows()

	/**
	 * A conversation owned by someone else is never titled.
	 *
	 * The job payload is the only thing that decides whose identity the writer assumes,
	 * so the object has to confirm it. Otherwise a stale or malformed payload could name
	 * another user's conversation while burning that user's own provider credential.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function testAConversationOwnedBySomeoneElseIsNotTitled(): void {
		$objectService = $this->objectService(
			$this->conversation(['title' => 'New conversation', 'userId' => 'bob'])
		);
		$objectService->expects($this->never())->method('saveObject');

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->expects($this->never())->method('generateConversationTitle');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'anything',
			userId: 'alice'
		);

		$this->assertSame('setUser:null', end($this->calls), 'The session must still be released.');

	}//end testAConversationOwnedBySomeoneElseIsNotTitled()

	/**
	 * An owner who no longer exists is skipped, not written as Anonymous.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function testAnUnresolvableOwnerIsSkippedWithoutTouchingTheSession(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->expects($this->never())->method('generateConversationTitle');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'anything',
			userId: 'deleted-user'
		);

		$this->assertSame([], $this->calls, 'No session switch may happen for an unresolvable owner.');

	}//end testAnUnresolvableOwnerIsSkippedWithoutTouchingTheSession()

	/**
	 * The whole conversation object survives the title write.
	 *
	 * `saveObject()` is PUT-semantic: any schema property left out of the payload is
	 * written back as null. A `['title' => …]` patch would therefore silently blank
	 * userId/agentId/metadata. Asserts a NON-CHANGED field survives, because that is
	 * the field a regression would eat.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function testTitleWriteCarriesTheWholeObjectForward(): void {
		$conversation = $this->conversation(
			[
				'title' => 'New conversation',
				'userId' => 'alice',
				'agentId' => 'agent-1',
				'metadata' => ['source' => 'companion'],
			]
		);

		$saved = null;
		$objectService = $this->objectService($conversation);
		$objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, mixed $extend = null, mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saved): ObjectEntity {
					$saved = $object;
					return new ObjectEntity();
				}
			);

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->method('generateConversationTitle')->willReturn('Leave policy');
		$handler->method('ensureUniqueTitle')->willReturn('Leave policy');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'What is our leave policy?',
			userId: 'alice'
		);

		$this->assertSame('Leave policy', $saved['title']);
		// The fields nobody asked to change must still be there.
		$this->assertSame('alice', $saved['userId']);
		$this->assertSame('agent-1', $saved['agentId']);
		$this->assertSame(['source' => 'companion'], $saved['metadata']);

	}//end testTitleWriteCarriesTheWholeObjectForward()

	/**
	 * The org's model policy is still enforced on the deferred call.
	 *
	 * `generateConversationTitle()` treats a null organisation as "skip policy
	 * enforcement", so dropping it here would turn a latency fix into a governance
	 * hole: titles would generate on a model the org's policy forbids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-the-deferred-title-write-preserves-the-whole-conversation-object
	 */
	public function testTheConversationsOrganisationIsPassedToGeneration(): void {
		$conversation = $this->conversation(
			[
				'title' => 'New conversation',
				'userId' => 'alice',
			],
			organisation: 'org-uuid-1'
		);

		$objectService = $this->objectService($conversation);
		$objectService->method('saveObject')->willReturn(new ObjectEntity());

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->expects($this->once())
			->method('generateConversationTitle')
			->with('What is our leave policy?', 'org-uuid-1')
			->willReturn('Leave policy');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'What is our leave policy?',
			userId: 'alice'
		);

	}//end testTheConversationsOrganisationIsPassedToGeneration()

	/**
	 * A conversation the user has already titled is never renamed.
	 *
	 * The decision is made at write time against a fresh read, so a job that runs
	 * late — or twice — cannot clobber a title the user chose in the meantime.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
	 */
	public function testAUserTitledConversationIsLeftAlone(): void {
		$conversation = $this->conversation(
			[
				'title' => 'Q3 planning',
				'userId' => 'alice',
			]
		);

		$objectService = $this->objectService($conversation);
		$objectService->expects($this->never())->method('saveObject');

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->expects($this->never())->method('generateConversationTitle');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'anything',
			userId: 'alice'
		);

	}//end testAUserTitledConversationIsLeftAlone()

	/**
	 * The lowercase placeholder is recognised.
	 *
	 * Regression: the create path writes `New conversation` while the old check
	 * matched `New Conversation` with a case-SENSITIVE strpos, so every conversation
	 * started from the streaming path was permanently unnameable — 129 of 181 on the
	 * reference instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
	 */
	public function testTheLowercasePlaceholderIsRecognisedAsUntitled(): void {
		$conversation = $this->conversation(
			[
				'title' => 'New conversation',
				'userId' => 'alice',
			]
		);

		$objectService = $this->objectService($conversation);
		$objectService->expects($this->once())->method('saveObject')->willReturn(new ObjectEntity());

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->expects($this->once())->method('generateConversationTitle')->willReturn('Leave policy');
		$handler->method('ensureUniqueTitle')->willReturn('Leave policy');

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'What is our leave policy?',
			userId: 'alice'
		);

	}//end testTheLowercasePlaceholderIsRecognisedAsUntitled()

	/**
	 * A failed generation leaves the placeholder and does not throw.
	 *
	 * The reply has already been delivered; a naming hiccup must not surface as a
	 * failed job with nothing useful to retry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
	 */
	public function testAFailedGenerationIsSwallowedAndWritesNothing(): void {
		$conversation = $this->conversation(
			[
				'title' => 'New conversation',
				'userId' => 'alice',
			]
		);

		$objectService = $this->objectService($conversation);
		$objectService->expects($this->never())->method('saveObject');

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->method('generateConversationTitle')->willThrowException(new RuntimeException('LLM down'));

		$this->writer($objectService, $handler)->write(
			conversationId: 'conv-1',
			userMessage: 'What is our leave policy?',
			userId: 'alice'
		);

		$this->addToAssertionCount(1);

	}//end testAFailedGenerationIsSwallowedAndWritesNothing()

	/**
	 * A conversation deleted before the job ran is a no-op, not an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/session-context-performance/specs/agent-engine-port/spec.md#requirement-conversation-title-generation-does-not-block-the-reply
	 */
	public function testAMissingConversationIsANoOp(): void {
		$objectService = $this->objectService(null);
		$objectService->expects($this->never())->method('saveObject');

		$handler = $this->createMock(ConversationManagementHandler::class);
		$handler->expects($this->never())->method('generateConversationTitle');

		$this->writer($objectService, $handler)->write(
			conversationId: 'gone',
			userMessage: 'anything',
			userId: 'alice'
		);

		$this->addToAssertionCount(1);

	}//end testAMissingConversationIsANoOp()
}//end class
