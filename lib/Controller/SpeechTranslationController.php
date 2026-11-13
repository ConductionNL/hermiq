<?php

/**
 * Hermiq SpeechTranslationController.
 *
 * "Translate a recording": one recording, given as an upload or as a file in
 * the caller's own Files, is transcribed, translated and spoken, the spoken
 * result is kept in the caller's Files tagged "Agent authored", and, when the
 * action was chosen in a chat session, the request and the result are stored
 * as two turns of that session.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\MessageHistoryHandlerInterface;
use OCA\Hermiq\Service\Speech\SpeechTranslationException;
use OCA\Hermiq\Service\Speech\TranslationChain;
use OCA\Hermiq\Service\Talk\ConversationParticipation;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Translates a recording for the signed-in person.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */
class SpeechTranslationController extends Controller {

	/**
	 * Largest recording accepted, in bytes; the same cap as transcription.
	 *
	 * @var int
	 */
	public const MAX_AUDIO_BYTES = (12 * 1024 * 1024);

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request       The request.
	 * @param IUserSession                   $userSession   The signed-in person.
	 * @param TranslationChain               $chain         Transcribes, translates, speaks and keeps.
	 * @param ObjectService                  $objectService Reads the session.
	 * @param MessageHistoryHandlerInterface $history       Stores the two turns.
	 * @param ConversationParticipation      $participation Owner-or-listed-participant guard.
	 * @param IL10N                          $l10n          Messages in the person's language.
	 * @param LoggerInterface                $logger        Diagnostics, never the transcript.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly TranslationChain $chain,
		private readonly ObjectService $objectService,
		private readonly MessageHistoryHandlerInterface $history,
		private readonly ConversationParticipation $participation,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Where the translation step will run, shown in the modal before the person starts.
	 *
	 * @return JSONResponse `{enabled, provider, model, residency, location, refusal}`.
	 *
	 * @no-admin-idor-exempt No object identifier is accepted; the answer describes the
	 *   caller's own organisation's binding for the feature.
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function where(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->refusal(message: $this->l10n->t('Not signed in.'), code: 'not_signed_in', status: 401);
		}

		return new JSONResponse(data: $this->chain->whereTranslationRuns(uid: $user->getUID()));
	}//end where()

	/**
	 * Translate one recording; store it as two turns when a session is named.
	 *
	 * The recording is an upload in field `audio` or a `fileId` in the caller's own
	 * Files; a file id the caller cannot read answers 404, like one that does not exist.
	 *
	 * @param string $targetLanguage The language to translate into.
	 * @param string $sourceLanguage The language of the recording, or '' to detect it.
	 * @param int    $fileId         A recording in the caller's Files, or 0 for an upload.
	 * @param string $sessionId      The chat session the action was chosen in, or ''.
	 *
	 * @return JSONResponse `{transcript, translation, spoken, notice, file, translation_record, userTurn?, assistantTurn?}`
	 *                      or `{error, code}`.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-front-office-employee-translates-a-voicemail
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function translate(
		string $targetLanguage = '',
		string $sourceLanguage = '',
		int $fileId = 0,
		string $sessionId = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->refusal(message: $this->l10n->t('Not signed in.'), code: 'not_signed_in', status: 401);
		}

		$uid = $user->getUID();
		$session = null;
		if ($sessionId !== '') {
			$session = $this->objectService->find(id: $sessionId, register: 'hermiq', schema: 'agentsession');
			if ($session === null) {
				return $this->refusal(message: $this->l10n->t('This session does not exist.'), code: 'not_found', status: 404);
			}

			if ($this->participation->mayTakeTurn(conversationData: $session->getObject(), userId: $uid) === false) {
				return $this->refusal(message: $this->l10n->t('You cannot add to this session.'), code: 'forbidden', status: 403);
			}
		}

		$recording = $this->recording(uid: $uid, fileId: $fileId);
		if ($recording instanceof JSONResponse) {
			return $recording;
		}

		try {
			$result = $this->chain->translate(
				audio: $recording['bytes'],
				targetLanguage: $targetLanguage,
				sourceLanguage: $sourceLanguage,
				uid: $uid
			);
			$file = null;
			if ($result['audio'] !== null) {
				$file = $this->chain->keep(uid: $uid, audio: $result['audio'], record: $result['record']);
			}
		} catch (SpeechTranslationException $e) {
			return $this->refusal(message: $e->getMessage(), code: $e->errorCode, status: $e->status());
		}

		$answer = [
			'transcript' => $result['transcript'],
			'translation' => $result['translation'],
			'spoken' => $result['spoken'],
			'notice' => $this->notice(spoken: $result['spoken'], targetLanguage: $targetLanguage),
			'file' => $file,
			'record' => $result['record'],
		];

		if ($session === null) {
			return new JSONResponse(data: $answer);
		}

		return $this->storeTurns(
			sessionId: $sessionId,
			uid: $uid,
			displayName: $user->getDisplayName(),
			recordingName: $recording['name'],
			answer: $answer
		);
	}//end translate()

	/**
	 * The recording: the file in the caller's Files when an id is given, else the upload.
	 *
	 * @param string $uid    The caller.
	 * @param int    $fileId The file id, or 0.
	 *
	 * @return array{bytes: string, name: string}|JSONResponse The recording, or the refusal.
	 */
	private function recording(string $uid, int $fileId): array|JSONResponse {
		if ($fileId > 0) {
			$file = $this->chain->readRecording(uid: $uid, fileId: $fileId);
			if ($file === null) {
				return $this->refusal(message: $this->l10n->t('This recording does not exist.'), code: 'not_found', status: 404);
			}

			if ($file['size'] > self::MAX_AUDIO_BYTES) {
				return $this->refusal(message: $this->tooLarge(size: $file['size']), code: 'invalid_request', status: 400);
			}

			return ['bytes' => $file['bytes'], 'name' => $file['name']];
		}

		$upload = $this->request->getUploadedFile(key: 'audio');
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return $this->refusal(message: $this->l10n->t('No recording was supplied.'), code: 'invalid_request', status: 400);
		}

		$size = (int)($upload['size'] ?? 0);
		if ($size > self::MAX_AUDIO_BYTES) {
			return $this->refusal(message: $this->tooLarge(size: $size), code: 'invalid_request', status: 400);
		}

		$bytes = file_get_contents(filename: (string)($upload['tmp_name'] ?? ''));
		if ($bytes === false || $bytes === '') {
			return $this->refusal(message: $this->l10n->t('No recording was supplied.'), code: 'invalid_request', status: 400);
		}

		return ['bytes' => $bytes, 'name' => (string)($upload['name'] ?? 'recording')];
	}//end recording()

	/**
	 * The message for a recording above the cap, with its size.
	 *
	 * @param int $size The size in bytes.
	 *
	 * @return string The message.
	 */
	private function tooLarge(int $size): string {
		return $this->l10n->t(
			'The recording is %1$s MB; at most %2$s MB can be translated.',
			[(string)round($size / 1048576, 1), (string)(self::MAX_AUDIO_BYTES / 1048576)]
		);
	}//end tooLarge()

	/**
	 * The notice for a translation that could not be spoken, or ''.
	 *
	 * @param bool   $spoken         Whether it was spoken.
	 * @param string $targetLanguage The target language.
	 *
	 * @return string The notice.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-language-without-a-voice-gets-text
	 */
	private function notice(bool $spoken, string $targetLanguage): string {
		if ($spoken === true) {
			return '';
		}

		return $this->l10n->t(
			'There is no voice for %s on this server. The translation is shown as text.',
			[$this->languageName(code: $targetLanguage)]
		);
	}//end notice()

	/**
	 * A language's name in its own language, e.g. "Nederlands", or the code.
	 *
	 * @param string $code The language code.
	 *
	 * @return string The name.
	 */
	private function languageName(string $code): string {
		if (class_exists(\Locale::class) === false) {
			return $code;
		}

		$name = \Locale::getDisplayLanguage($code, $code);
		if ($name === '' || $name === $code) {
			return $code;
		}

		return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
	}//end languageName()

	/**
	 * Store the request and the result as two turns and answer with both.
	 *
	 * @param string               $sessionId     The session.
	 * @param string               $uid           The person.
	 * @param string               $displayName   Their display name at send time.
	 * @param string               $recordingName The recording's file name.
	 * @param array<string, mixed> $answer        The translation answer.
	 *
	 * @return JSONResponse The answer with the two turns, or a 500 when they could not be stored.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
	 */
	private function storeTurns(string $sessionId, string $uid, string $displayName, string $recordingName, array $answer): JSONResponse {
		$request = $this->l10n->t(
			'Translate %1$s into %2$s',
			[$recordingName, $this->languageName(code: (string)$answer['record']['targetLanguage'])]
		);
		$content = $answer['transcript'] . "\n\n" . $answer['translation'];
		if ($answer['notice'] !== '') {
			$content .= "\n\n" . $answer['notice'];
		}

		$attachments = [];
		if (is_array($answer['file']) === true) {
			$attachments[] = [
				'fileId' => $answer['file']['fileId'],
				'name' => $answer['file']['name'],
				'mimeType' => 'audio/wav',
				'size' => $answer['file']['size'],
				'origin' => 'generated',
			];
		}

		// The spoken file's path in the translator's Files, so the chat can play it.
		$audioPath = '';
		if (is_array($answer['file']) === true) {
			$audioPath = (string)$answer['file']['path'];
		}

		$translation = $answer['record'] + [
			'transcript' => $answer['transcript'],
			'translation' => $answer['translation'],
			'audioPath' => $audioPath,
		];

		try {
			$userTurn = $this->history->storeMessage(
				conversationId: $sessionId,
				role: 'user',
				content: $request,
				authorId: $uid,
				authorDisplayName: $displayName
			);
			$assistantTurn = $this->history->storeMessage(
				conversationId: $sessionId,
				role: 'assistant',
				content: $content,
				attachments: $attachments,
				translation: $translation
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SpeechTranslationController] The recording was translated but the turns could not be stored',
				context: ['exception' => $e]
			);
			return $this->refusal(
				message: $this->l10n->t('The recording was translated, but the result could not be added to the chat.'),
				code: 'not_stored',
				status: 500
			);
		}//end try

		return new JSONResponse(
			data: $answer + [
				'userTurn' => ['id' => (string)$userTurn->getUuid(), 'role' => 'user', 'content' => $request, 'attachments' => []],
				'assistantTurn' => [
					'id' => (string)$assistantTurn->getUuid(),
					'role' => 'assistant',
					'content' => $content,
					'attachments' => $attachments,
					'translation' => $translation,
				],
			]
		);
	}//end storeTurns()

	/**
	 * A refusal in the shape the Chat page reads.
	 *
	 * @param string $message The message for the person.
	 * @param string $code    The stable code.
	 * @param int    $status  The HTTP status.
	 *
	 * @return JSONResponse `{ error, code }`.
	 */
	private function refusal(string $message, string $code, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $message, 'code' => $code], statusCode: $status);
	}//end refusal()
}//end class
