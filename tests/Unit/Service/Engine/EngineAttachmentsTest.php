<?php

/**
 * The engine resolves a turn's attachments as the person who sent it, before the
 * turn is stored and before any model is called, keeps them on the user turn as
 * references, and hands them to the response handler so every file passes the
 * AI feature's checks (chat-attachments-and-images, task 3).
 *
 * Built over the real TurnAttachmentResolver, so the test fails if the engine and
 * the resolver disagree on who the speaker is.
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\Chat\AttachmentRefusedException;
use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Chat\TurnAttachmentResolver;
use OCA\Hermiq\Service\Engine\ContextAssembler;
use OCA\Hermiq\Service\Engine\ContextRetrievalHandler;
use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Engine\Engine;
use OCA\Hermiq\Service\Engine\MessageHistoryHandler;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the attachment step of Engine::processMessage().
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
 */
class EngineAttachmentsTest extends TestCase {

	/**
	 * The storeMessage calls, as positional argument lists.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $stored = [];

	/**
	 * The attachments generateResponse was handed, or null when it was never called.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $handed = null;

	/**
	 * The speaker generateResponse was handed, or null when it was never called.
	 *
	 * @var string|null
	 */
	private ?string $handedSpeaker = null;

	/**
	 * Anne's private offerte: only Anne's Files hold file 48213.
	 *
	 * @return IRootFolder
	 */
	private function filesOnlyAnneCanRead(): IRootFolder {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(48213);
		$file->method('getName')->willReturn('offerte-dakrenovatie-2026.pdf');
		$file->method('getMimetype')->willReturn('application/pdf');
		$file->method('getSize')->willReturn(184233);
		$file->method('isReadable')->willReturn(true);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($file): Folder {
				$folder = $this->createMock(Folder::class);
				$folder->method('getById')->willReturnCallback(
					static function (int $id) use ($uid, $file): array {
						if ($uid === 'anne' && $id === 48213) {
							return [$file];
						}

						return [];
					}
				);
				return $folder;
			}
		);

		return $root;
	}//end filesOnlyAnneCanRead()

	/**
	 * The engine over a shared session owned by Anne with Bram as participant.
	 *
	 * @param \Throwable|null $responseFailure What generateResponse throws after recording its input.
	 * @param list<string>    $notices         The attachment notices the handler keeps for the answer.
	 * @param list<array<string, mixed>> $created The images the agent created during the turn.
	 *
	 * @return Engine
	 */
	private function engine(?\Throwable $responseFailure = null, array $notices = [], array $created = []): Engine {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (): ObjectEntity {
				$conversation = new ObjectEntity();
				$conversation->setUuid('sess-1');
				$conversation->setObject(['userId' => 'anne', 'participants' => ['bram']]);
				return $conversation;
			}
		);

		$history = $this->createMock(MessageHistoryHandler::class);
		$history->method('storeMessage')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->stored[] = $args;
				$turn = new ObjectEntity();
				$turn->setUuid('turn-' . count($this->stored));
				return $turn;
			}
		);
		$history->method('buildMessageHistory')->willReturn([]);

		$response = $this->createMock(ResponseGenerationHandler::class);
		$response->method('generateResponse')->willReturnCallback(
			function (...$args) use ($responseFailure, $notices, &$response): string {
				$response->attachmentNotices = $notices;
				// Positional, in the order of generateResponse(): attachments is the 13th.
				$this->handed = ($args[12] ?? []);
				// The 14th is the speaker, whose Files the native parts are read from.
				$this->handedSpeaker = ($args[13] ?? null);
				if ($responseFailure !== null) {
					throw $responseFailure;
				}

				return 'Antwoord';
			}
		);

		$context = $this->createMock(ContextRetrievalHandler::class);
		$context->method('retrieveContext')->willReturn(['text' => '', 'sources' => []]);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $id): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn(ucfirst($id));
				return $user;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$images = $this->createMock(ImageGenerationService::class);
		$images->method('takeCreated')->willReturnOnConsecutiveCalls($created, []);

		return new Engine(
			$objectService,
			$context,
			$response,
			$this->createMock(ConversationManagementHandler::class),
			$history,
			$this->createMock(ContextAssembler::class),
			new NullLogger(),
			null,
			null,
			new ConversationParticipation(),
			$users,
			null,
			new TurnAttachmentResolver($this->filesOnlyAnneCanRead(), $l10n),
			$images
		);
	}//end engine()

	/**
	 * Bram names a file only Anne can read: refused, nothing stored, no model call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-participant-cannot-attach-a-colleagues-private-file-by-id
	 */
	public function testAParticipantCannotAttachAColleaguesFile(): void {
		$engine = $this->engine();

		try {
			$engine->processMessage(
				conversationId: 'sess-1',
				userId: 'bram',
				userMessage: 'Wat staat hierin?',
				attachments: [['fileId' => 48213]]
			);
			$this->fail('The colleague\'s file was attached.');
		} catch (AttachmentRefusedException $refusal) {
			$this->assertSame('This file is not available to you', $refusal->getMessage());
		}

		$this->assertSame([], $this->stored, 'No turn is stored for a refused attachment.');
		$this->assertNull($this->handed, 'The model is never called.');

	}//end testAParticipantCannotAttachAColleaguesFile()

	/**
	 * On the Talk path the session owner's uid runs the turn but the speaker is the
	 * author; the file is read as the author, not as the owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testTheAuthorIsTheSpeakerNotTheRunningUser(): void {
		$engine = $this->engine();

		$this->expectException(AttachmentRefusedException::class);
		$engine->processMessage(
			conversationId: 'sess-1',
			userId: 'anne',
			userMessage: 'Kijk even mee',
			authorId: 'bram',
			authorDisplayName: 'Bram',
			attachments: [['fileId' => 48213]]
		);

	}//end testTheAuthorIsTheSpeakerNotTheRunningUser()

	/**
	 * Anne's own file is stored on her turn as a reference and handed on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testAReadableFileIsStoredOnTheTurnAndHandedOn(): void {
		$engine = $this->engine();

		$engine->processMessage(
			conversationId: 'sess-1',
			userId: 'anne',
			userMessage: 'Wat staat er over de oplevertermijn?',
			attachments: [['fileId' => 48213, 'origin' => 'files']]
		);

		$expected = [['fileId' => 48213, 'name' => 'offerte-dakrenovatie-2026.pdf', 'mimeType' => 'application/pdf', 'size' => 184233, 'origin' => 'files']];
		// PHPUnit hands the callback positional arguments in the order of
		// MessageHistoryHandler::storeMessage(): attachments is the 8th.
		$this->assertSame($expected, ($this->stored[0][7] ?? null), 'The user turn carries the reference.');
		$this->assertSame('user', $this->stored[0][1]);
		$this->assertSame([], ($this->stored[1][7] ?? []), 'The assistant turn carries none.');
		$this->assertSame($expected, $this->handed);
		$this->assertSame('anne', $this->handedSpeaker, 'The speaker is handed on, so native parts are read as her.');

	}//end testAReadableFileIsStoredOnTheTurnAndHandedOn()

	/**
	 * The redaction check refuses the attached file before the provider call: the
	 * person reads a refusal naming the file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#scenario-a-feature-that-requires-redaction-refuses-an-unredacted-attachment
	 */
	public function testAnUnredactedAttachmentIsRefusedByName(): void {
		// ResponseGenerationHandler wraps whatever the factory throws, keeping it as
		// the previous exception; the engine must look through that wrapper.
		$engine = $this->engine(
			new \Exception(
				'Failed to generate response: refused',
				422,
				new RedactionRequiredException(featureSlug: 'chat-companion', documentReference: '48213', reason: 'none recorded')
			)
		);

		try {
			$engine->processMessage(
				conversationId: 'sess-1',
				userId: 'anne',
				userMessage: 'Vat samen',
				attachments: [['fileId' => 48213]]
			);
			$this->fail('The unredacted attachment was not refused.');
		} catch (AttachmentRefusedException $refusal) {
			$this->assertStringContainsString('offerte-dakrenovatie-2026.pdf', $refusal->getMessage());
			$this->assertInstanceOf(RedactionRequiredException::class, $refusal->getPrevious());
		}

	}//end testAnUnredactedAttachmentIsRefusedByName()

	/**
	 * The notices the handler kept for the answer travel on the engine's result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function testTheAttachmentNoticesTravelOnTheResult(): void {
		$notice = 'This model cannot see images. plattegrond.png was not sent.';

		$result = $this->engine(null, [$notice])->processMessage(
			conversationId: 'sess-1',
			userId: 'anne',
			userMessage: 'Waar is de nooduitgang?',
			attachments: [['fileId' => 48213, 'origin' => 'files']]
		);

		$this->assertSame([$notice], ($result['attachmentNotices'] ?? null));

	}//end testTheAttachmentNoticesTravelOnTheResult()

	/**
	 * A turn without attachments never opens anyone's Files and stores none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function testATurnWithoutAttachmentsIsUnchanged(): void {
		$engine = $this->engine();
		$engine->processMessage(conversationId: 'sess-1', userId: 'bram', userMessage: 'Hallo');

		$this->assertSame([], ($this->stored[0][7] ?? []));
		$this->assertSame([], $this->handed);

	}//end testATurnWithoutAttachmentsIsUnchanged()

	/**
	 * An image the agent created during the turn is stored on the answer and travels on the result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004
	 */
	public function testAnImageTheAgentCreatedIsStoredOnTheAnswer(): void {
		$image = ['fileId' => 902, 'name' => 'image-1.png', 'mimeType' => 'image/png', 'size' => 2048, 'origin' => 'generated'];

		$result = $this->engine(null, [], [$image])->processMessage(
			conversationId: 'sess-1',
			userId: 'anne',
			userMessage: 'Maak een infographic met de afvalkalender van april'
		);

		$assistant = array_values(array_filter($this->stored, static fn (array $args): bool => ($args['role'] ?? null) === 'assistant'));
		$this->assertCount(1, $assistant);
		$this->assertSame([$image], ($assistant[0]['attachments'] ?? null));
		$this->assertSame([$image], ($result['attachments'] ?? null));
	}//end testAnImageTheAgentCreatedIsStoredOnTheAnswer()

	/**
	 * An answer without a created image carries an empty list.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAnImageCarriesNoAttachments(): void {
		$result = $this->engine()->processMessage(conversationId: 'sess-1', userId: 'anne', userMessage: 'Hallo');

		$this->assertSame([], ($result['attachments'] ?? null));
	}//end testAnAnswerWithoutAnImageCarriesNoAttachments()
}//end class
