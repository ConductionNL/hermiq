<?php

/**
 * Hermiq SpeechTranslationController: a recording from Files or an upload, translated and stored as two turns.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SpeechTranslationController;
use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Service\Speech\SpeechTranslationException;
use OCA\Hermiq\Service\Speech\TranslationChain;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for the translate-a-recording route.
 */
final class SpeechTranslationControllerTest extends TestCase {

	/**
	 * The storeMessage() calls, as named arguments.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * The arguments translate() received.
	 *
	 * @var array<string, mixed>
	 */
	private array $translated = [];

	/**
	 * Whether keep() was called.
	 *
	 * @var bool
	 */
	private bool $kept = false;

	/**
	 * The controller over a fake chain.
	 *
	 * @param string                          $uid     The signed-in person.
	 * @param bool                            $spoken  Whether the chain returns audio.
	 * @param SpeechTranslationException|null $refusal What the chain throws, if anything.
	 *
	 * @return SpeechTranslationController The controller.
	 */
	private function controller(string $uid = 'anne', bool $spoken = true, ?SpeechTranslationException $refusal = null): SpeechTranslationController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn('Anne');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$chain = $this->createMock(TranslationChain::class);
		$chain->method('readRecording')->willReturnCallback(
			static fn (string $uid, int $fileId): ?array => ($fileId === 41 && $uid === 'anne' ? ['bytes' => 'm4a', 'name' => 'terugbelverzoek.m4a', 'size' => 3] : null)
		);
		$chain->method('translate')->willReturnCallback(
			function (string $audio, string $targetLanguage, string $sourceLanguage = '', ?string $uid = null) use ($spoken, $refusal): array {
				$this->translated = compact('audio', 'targetLanguage', 'sourceLanguage', 'uid');
				if ($refusal !== null) {
					throw $refusal;
				}

				return [
					'transcript' => 'مرحبا',
					'translation' => 'Hallo',
					'audio' => ($spoken === true ? 'RIFF' : null),
					'spoken' => $spoken,
					'record' => ['sourceLanguage' => '', 'detectedLanguage' => 'ar', 'targetLanguage' => 'nl', 'ttsVoice' => 'nf_dutch'],
				];
			}
		);
		$chain->method('keep')->willReturnCallback(
			function (): array {
				$this->kept = true;
				return ['fileId' => 733, 'name' => 'ar-to-nl.wav', 'path' => 'Hermiq/Translations/ar-to-nl.wav', 'mimeType' => 'audio/wav', 'size' => 4];
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (string $id): ?ObjectEntity {
				if ($id !== 'sess-1') {
					return null;
				}

				$conversation = new ObjectEntity();
				$conversation->setUuid('sess-1');
				$conversation->setObject(['userId' => 'anne', 'participants' => []]);
				return $conversation;
			}
		);

		$history = $this->createMock(MessageHistoryHandlerInterface::class);
		$history->method('storeMessage')->willReturnCallback(
			function (string $conversationId, string $role, string $content, ?array $sources = null, ?array $context = null, ?string $authorId = null, ?string $authorDisplayName = null, array $attachments = [], ?array $provenance = null, ?array $translation = null): ObjectEntity {
				$this->stored[] = compact('conversationId', 'role', 'content', 'authorId', 'attachments', 'translation');
				$turn = new ObjectEntity();
				$turn->setUuid('turn-' . count($this->stored));
				return $turn;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_NO_FILE]);

		return new SpeechTranslationController(
			request: $request,
			userSession: $session,
			chain: $chain,
			objectService: $objects,
			history: $history,
			participation: new ConversationParticipation(),
			l10n: $l10n,
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * The route is open to signed-in people and rate limited.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testTheRouteIsForSignedInPeopleAndRateLimited(): void {
		$method = new ReflectionMethod(SpeechTranslationController::class, 'translate');
		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertCount(1, $method->getAttributes(UserRateLimit::class));
	}//end testTheRouteIsForSignedInPeopleAndRateLimited()

	/**
	 * A file from their Files becomes their turn and an answer with the transcript, translation and audio.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-front-office-employee-translates-a-voicemail
	 */
	public function testAFileFromFilesIsTranslatedAndStoredAsTwoTurns(): void {
		$response = $this->controller()->translate(targetLanguage: 'nl', fileId: 41, sessionId: 'sess-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['audio' => 'm4a', 'targetLanguage' => 'nl', 'sourceLanguage' => '', 'uid' => 'anne'], $this->translated);
		$this->assertTrue($this->kept);
		$this->assertCount(2, $this->stored);
		$this->assertSame('user', $this->stored[0]['role']);
		$this->assertStringContainsString('terugbelverzoek.m4a', $this->stored[0]['content']);
		$this->assertSame('anne', $this->stored[0]['authorId']);
		$this->assertSame('assistant', $this->stored[1]['role']);
		$this->assertSame("مرحبا\n\nHallo", $this->stored[1]['content']);
		$this->assertSame(733, $this->stored[1]['attachments'][0]['fileId']);
		$this->assertSame('audio/wav', $this->stored[1]['attachments'][0]['mimeType']);
		$this->assertSame('generated', $this->stored[1]['attachments'][0]['origin']);
		$this->assertSame('ar', $this->stored[1]['translation']['detectedLanguage']);
		$this->assertSame('Hallo', $this->stored[1]['translation']['translation']);
		$this->assertSame('Hermiq/Translations/ar-to-nl.wav', $this->stored[1]['translation']['audioPath']);
		$data = $response->getData();
		$this->assertSame('turn-2', $data['assistantTurn']['id']);
		$this->assertSame('', $data['notice']);
	}//end testAFileFromFilesIsTranslatedAndStoredAsTwoTurns()

	/**
	 * A file id the caller cannot read answers 404 and nothing is translated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testAFileTheCallerCannotReadIsA404(): void {
		$response = $this->controller(uid: 'bram')->translate(targetLanguage: 'nl', fileId: 41);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('not_found', $response->getData()['code']);
		$this->assertSame([], $this->translated);
	}//end testAFileTheCallerCannotReadIsA404()

	/**
	 * A session the caller may not add to is refused before the recording is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testASessionTheCallerMayNotAddToIsA403(): void {
		$response = $this->controller(uid: 'bram')->translate(targetLanguage: 'nl', fileId: 41, sessionId: 'sess-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->translated);
		$this->assertSame(404, $this->controller()->translate(targetLanguage: 'nl', fileId: 41, sessionId: 'nope')->getStatus());
	}//end testASessionTheCallerMayNotAddToIsA403()

	/**
	 * No voice: the answer is the text with the notice, and no file is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-language-without-a-voice-gets-text
	 */
	public function testALanguageWithoutAVoiceAnswersWithTextAndTheNotice(): void {
		$data = $this->controller(spoken: false)->translate(targetLanguage: 'nl', fileId: 41)->getData();

		$this->assertFalse($data['spoken']);
		$this->assertNull($data['file']);
		$this->assertFalse($this->kept);
		$this->assertStringStartsWith('There is no voice for ', $data['notice']);
		$this->assertStringEndsWith(' on this server. The translation is shown as text.', $data['notice']);
		$this->assertSame([], $this->stored, 'No session named, so no turns.');
	}//end testALanguageWithoutAVoiceAnswersWithTextAndTheNotice()

	/**
	 * A chain refusal answers with its code and status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-an-on-premise-requirement-stops-a-hosted-translation
	 */
	public function testARefusalAnswersWithItsCodeAndStatus(): void {
		$refusal = new SpeechTranslationException('provider_refused', "Refused by the residency check: feature 'speech-translation' requires on-premise.");
		$response = $this->controller(refusal: $refusal)->translate(targetLanguage: 'nl', fileId: 41, sessionId: 'sess-1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('provider_refused', $response->getData()['code']);
		$this->assertSame([], $this->stored);
	}//end testARefusalAnswersWithItsCodeAndStatus()

	/**
	 * No file id and no upload is a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testNoRecordingIsA400(): void {
		$response = $this->controller()->translate(targetLanguage: 'nl');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('No recording was supplied.', $response->getData()['error']);
	}//end testNoRecordingIsA400()
}//end class
