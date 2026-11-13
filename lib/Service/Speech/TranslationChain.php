<?php

/**
 * Hermiq TranslationChain.
 *
 * Translates a recording in three steps: transcribe it on the instance's own
 * speech service, translate the transcript with the model the organisation
 * binds to the AI feature `speech-translation`, and speak the translation with
 * the voice an administrator mapped to the target language. Every step is
 * recorded, so a reader can see which languages and which engines were used.
 *
 * Transcription and speech never leave the instance. The translation is the
 * only step that may, and only when the feature's binding names a hosted
 * provider; the feature's residency and the organisation's model policy are
 * checked before the transcript is made, so a refused run sends nothing.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Speech
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-1-the-translation-chain-and-its-record
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Speech;

use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Kernel\Contracts\SpeechClientInterface;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\ArtefactMarkingFailedException;
use OCA\OpenRegister\Db\OrganisationMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transcribe, translate, speak; and keep the spoken result in Files, marked.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */
class TranslationChain {

	/**
	 * The AI feature the translation step runs under.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'speech-translation';

	/**
	 * Longest transcript sent for translation and speech, in characters.
	 *
	 * @var int
	 */
	public const MAX_TRANSCRIPT_CHARS = 4000;

	/**
	 * A language code: a two or three letter primary subtag, optionally a region or script.
	 *
	 * @var string
	 */
	private const LANGUAGE_PATTERN = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/';

	/**
	 * Constructor.
	 *
	 * @param SpeechClientInterface     $speech        The instance's speech sidecar.
	 * @param ProviderFactoryInterface  $providers     The governed LLM provider layer.
	 * @param SpeechVoiceMap            $voices        The administrator's voice per language.
	 * @param TranslatedAudioFiles      $files         Writes the spoken result to Files.
	 * @param AgentArtefactMarker       $marker        Tags the file "Agent authored".
	 * @param AiFeatureLookup           $features      The AI-feature governance register.
	 * @param ProviderResidencyRegistry $residencies   Where each provider runs.
	 * @param OrganisationMapper        $organisations The person's active organisation.
	 * @param LoggerInterface           $logger        Diagnostics, never the transcript.
	 */
	public function __construct(
		private readonly SpeechClientInterface $speech,
		private readonly ProviderFactoryInterface $providers,
		private readonly SpeechVoiceMap $voices,
		private readonly TranslatedAudioFiles $files,
		private readonly AgentArtefactMarker $marker,
		private readonly AiFeatureLookup $features,
		private readonly ProviderResidencyRegistry $residencies,
		private readonly OrganisationMapper $organisations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Where the translation step would run for this person, so the modal can say it before they start.
	 *
	 * @param string $uid The person.
	 *
	 * @return array{enabled: bool, provider: string, model: string, residency: string, location: string, refusal: string, voices: list<string>}
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
	 */
	public function whereTranslationRuns(string $uid): array {
		$where = [
			'enabled' => $this->featureEnabled(),
			'provider' => '',
			'model' => '',
			'residency' => '',
			'location' => '',
			'refusal' => '',
			// The languages that can be spoken; any other target is "text only".
			'voices' => array_keys($this->voices->all()),
		];

		try {
			$driver = $this->resolveDriver(organisation: $this->organisationOf(uid: $uid));
		} catch (SpeechTranslationException $e) {
			$where['refusal'] = $e->getMessage();
			return $where;
		}

		$residency = $this->residencies->forProvider(provider: $driver['provider']);
		return array_merge($where, $driver, ['residency' => $residency['residency'], 'location' => $residency['location']]);
	}//end whereTranslationRuns()

	/**
	 * Translate one recording and speak the result.
	 *
	 * @param string      $audio          The recording's bytes.
	 * @param string      $targetLanguage The language to translate into.
	 * @param string      $sourceLanguage The language the person says it is in, or ''.
	 * @param string|null $uid            The person, or null for an instance-level task.
	 * @param bool        $allowNextcloud Whether the `nextcloud` chat provider may translate;
	 *                                    false from a TaskProcessing provider, which would recurse.
	 *
	 * @return array{transcript: string, translation: string, audio: string|null, spoken: bool, record: array<string, string>}
	 *
	 * @throws SpeechTranslationException When a step is refused or fails.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The recursion guard is generateText()'s own flag, passed through.
	 */
	public function translate(
		string $audio,
		string $targetLanguage,
		string $sourceLanguage='',
		?string $uid=null,
		bool $allowNextcloud=true,
	): array {
		$this->assertRequest(audio: $audio, targetLanguage: $targetLanguage, sourceLanguage: $sourceLanguage);

		if ($this->featureEnabled() === false) {
			throw new SpeechTranslationException(
				'feature_not_enabled',
				'Translating a recording must be enabled as the AI feature "' . self::FEATURE_SLUG . '" before it can be used.'
			);
		}

		// The feature's binding, residency and the model policy are checked BEFORE
		// the recording is transcribed: a refused run never produces a transcript
		// that could be sent anywhere.
		$organisation = $this->organisationOf(uid: (string)$uid);
		$driver = $this->resolveDriver(organisation: $organisation);

		$heard = $this->transcribe(audio: $audio, sourceLanguage: $sourceLanguage);
		$from = $sourceLanguage;
		if ($from === '') {
			$from = $heard['language'];
		}

		$translation = $this->translateText(
			text: $heard['text'],
			from: $from,
			into: $targetLanguage,
			uid: $uid,
			organisation: $organisation,
			allowNextcloud: $allowNextcloud
		);

		$record = [
			'sourceLanguage' => $sourceLanguage,
			'detectedLanguage' => $heard['language'],
			'targetLanguage' => $targetLanguage,
			'sttEngine' => $heard['engine'],
			'translationProvider' => $driver['provider'],
			'translationModel' => $driver['model'],
			'translationResidency' => $this->residencies->forProvider(provider: $driver['provider'])['residency'],
			'ttsEngine' => '',
			'ttsVoice' => '',
		];

		$spoken = $this->speak(text: $translation, targetLanguage: $targetLanguage);
		$record['ttsEngine'] = $spoken['engine'];
		$record['ttsVoice'] = $spoken['voice'];

		$this->logger->info(
			message: '[TranslationChain] Recording translated',
			context: ['user' => (string)$uid, 'record' => $record, 'spoken' => $spoken['audio'] !== null]
		);

		return [
			'transcript' => $heard['text'],
			'translation' => $translation,
			'audio' => $spoken['audio'],
			'spoken' => $spoken['audio'] !== null,
			'record' => $record,
		];
	}//end translate()

	/**
	 * A recording in the person's own Files, read as them; null when they cannot read it.
	 *
	 * @param string $uid    The person.
	 * @param int    $fileId The file id.
	 *
	 * @return array{bytes: string, name: string, size: int}|null The recording.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function readRecording(string $uid, int $fileId): ?array {
		return $this->files->readRecording(uid: $uid, fileId: $fileId);
	}//end readRecording()

	/**
	 * Save the spoken translation in the person's Files and tag it "Agent authored", or keep nothing.
	 *
	 * @param string $uid    The person.
	 * @param string $audio  The WAV bytes.
	 * @param array  $record The translation record.
	 *
	 * @return array{fileId: int, name: string, path: string, mimeType: string, size: int} The saved file.
	 *
	 * @throws SpeechTranslationException When the file cannot be written or marked.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function keep(string $uid, string $audio, array $record): array {
		$source = (string)($record['sourceLanguage'] ?? '');
		if ($source === '') {
			$source = (string)($record['detectedLanguage'] ?? '');
		}

		try {
			$file = $this->files->save(
				uid: $uid,
				bytes: $audio,
				sourceLanguage: $source,
				targetLanguage: (string)($record['targetLanguage'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TranslationChain] The spoken translation could not be saved',
				context: ['user' => $uid, 'error' => $e->getMessage()]
			);
			throw new SpeechTranslationException('not_saved', 'The spoken translation could not be saved in your Files.');
		}

		try {
			$this->marker->markFile(fileId: (int)$file->getId());
		} catch (ArtefactMarkingFailedException $e) {
			// ADR-088 decision 6: an unmarked artefact is not kept.
			$file->delete();
			throw new SpeechTranslationException(
				'marking_failed',
				'The spoken translation could not be marked as agent-authored, so it was not kept.'
			);
		}

		return [
			'fileId' => (int)$file->getId(),
			'name' => $file->getName(),
			'path' => TranslatedAudioFiles::FOLDER . '/' . $file->getName(),
			'mimeType' => 'audio/wav',
			'size' => (int)$file->getSize(),
		];
	}//end keep()

	/**
	 * Refuse no audio, a recording above the transcript cap's audio budget, and malformed language codes.
	 *
	 * @param string $audio          The recording.
	 * @param string $targetLanguage The target language.
	 * @param string $sourceLanguage The source language, or ''.
	 *
	 * @return void
	 *
	 * @throws SpeechTranslationException With invalid_request.
	 */
	private function assertRequest(string $audio, string $targetLanguage, string $sourceLanguage): void {
		if ($audio === '') {
			throw new SpeechTranslationException('invalid_request', 'No recording was supplied.');
		}

		if (preg_match(self::LANGUAGE_PATTERN, $targetLanguage) !== 1) {
			throw new SpeechTranslationException('invalid_request', 'Choose the language to translate into.');
		}

		if ($sourceLanguage !== '' && preg_match(self::LANGUAGE_PATTERN, $sourceLanguage) !== 1) {
			throw new SpeechTranslationException('invalid_request', 'The language of the recording is not a language code.');
		}
	}//end assertRequest()

	/**
	 * The provider and model the feature resolves to, with every gate applied.
	 *
	 * @param string $organisation The person's organisation ('' for instance scope).
	 *
	 * @return array{provider: string, model: string}
	 *
	 * @throws SpeechTranslationException With provider_refused when a gate refuses.
	 */
	private function resolveDriver(string $organisation): array {
		try {
			$driver = $this->providers->createChatDriver(
				llmConfig: $this->providers->getLlmConfig(),
				organisation: $organisation,
				aiFeature: self::FEATURE_SLUG
			);
		} catch (ResidencyViolationException | ModelPolicyViolationException | RedactionRequiredException | ProviderUnavailableException $e) {
			throw new SpeechTranslationException('provider_refused', $e->getMessage());
		}

		return ['provider' => (string)$driver->provider, 'model' => (string)$driver->model];
	}//end resolveDriver()

	/**
	 * Transcribe on the speech sidecar.
	 *
	 * @param string $audio          The recording.
	 * @param string $sourceLanguage The language given, or '' to detect.
	 *
	 * @return array{text: string, language: string, engine: string}
	 *
	 * @throws SpeechTranslationException With speech_unavailable or nothing_heard.
	 */
	private function transcribe(string $audio, string $sourceLanguage): array {
		try {
			$result = $this->speech->transcribe(audio: $audio, language: $sourceLanguage);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TranslationChain] Transcription failed',
				context: ['error' => $e->getMessage()]
			);
			throw new SpeechTranslationException('speech_unavailable', 'The speech service is unavailable.');
		}

		$text = trim((string)($result['text'] ?? ''));
		if ($text === '') {
			throw new SpeechTranslationException('nothing_heard', 'No speech was heard in the recording.');
		}

		if (mb_strlen($text) > self::MAX_TRANSCRIPT_CHARS) {
			throw new SpeechTranslationException(
				'invalid_request',
				'The recording is too long to translate: its transcript has ' . mb_strlen($text)
				. ' characters, and at most ' . self::MAX_TRANSCRIPT_CHARS . ' can be translated.'
			);
		}

		return [
			'text' => $text,
			'language' => (string)($result['language'] ?? ''),
			'engine' => (string)($result['engine'] ?? ''),
		];
	}//end transcribe()

	/**
	 * Translate the transcript under the feature, with the fixed instruction.
	 *
	 * @param string      $text           The transcript.
	 * @param string      $from           The source language, or '' when unknown.
	 * @param string      $into           The target language.
	 * @param string|null $uid            The person.
	 * @param string      $organisation   The organisation.
	 * @param bool        $allowNextcloud Whether the `nextcloud` provider may run.
	 *
	 * @return string The translation.
	 *
	 * @throws SpeechTranslationException With provider_refused or translation_failed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Passed through to generateText().
	 */
	private function translateText(
		string $text,
		string $from,
		string $into,
		?string $uid,
		string $organisation,
		bool $allowNextcloud,
	): string {
		try {
			$translation = trim(
				$this->providers->generateText(
					prompt: $this->prompt(text: $text, from: $from, into: $into),
					userId: $uid,
					allowNextcloud: $allowNextcloud,
					organisation: $organisation,
					aiFeature: self::FEATURE_SLUG
				)
			);
		} catch (ResidencyViolationException | ModelPolicyViolationException | RedactionRequiredException $e) {
			throw new SpeechTranslationException('provider_refused', $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TranslationChain] Translation failed',
				context: ['error' => $e->getMessage()]
			);
			throw new SpeechTranslationException('translation_failed', 'The recording could not be translated.');
		}

		if ($translation === '') {
			throw new SpeechTranslationException('translation_failed', 'The recording could not be translated.');
		}

		return $translation;
	}//end translateText()

	/**
	 * The fixed translation instruction.
	 *
	 * @param string $text The transcript.
	 * @param string $from The source language, or ''.
	 * @param string $into The target language.
	 *
	 * @return string The prompt.
	 */
	private function prompt(string $text, string $from, string $into): string {
		$source = 'the language it is in';
		if ($from !== '') {
			$source = 'the language with code "' . $from . '"';
		}

		return 'Translate the transcript below from ' . $source . ' into the language with code "' . $into . '". '
			. 'Keep names and numbers as they are. Add nothing, leave nothing out, and do not explain. '
			. 'Reply with the translation only.'
			. "\n\nTranscript:\n" . $text;
	}//end prompt()

	/**
	 * Speak the translation with the target language's voice, or nothing when no voice is mapped.
	 *
	 * @param string $text           The translation.
	 * @param string $targetLanguage The target language.
	 *
	 * @return array{audio: string|null, engine: string, voice: string}
	 *
	 * @throws SpeechTranslationException With speech_unavailable when synthesis fails.
	 */
	private function speak(string $text, string $targetLanguage): array {
		$voice = $this->voices->voiceFor(language: $targetLanguage);
		if ($voice === null) {
			return ['audio' => null, 'engine' => '', 'voice' => ''];
		}

		try {
			$result = $this->speech->synthesise(text: $text, voice: $voice);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TranslationChain] Synthesis failed',
				context: ['error' => $e->getMessage()]
			);
			throw new SpeechTranslationException('speech_unavailable', 'The speech service is unavailable.');
		}

		$audio = (string)($result['audio'] ?? '');
		if ($audio === '') {
			throw new SpeechTranslationException('speech_unavailable', 'The speech service returned no audio.');
		}

		return ['audio' => $audio, 'engine' => (string)($result['engine'] ?? ''), 'voice' => $voice];
	}//end speak()

	/**
	 * Whether the AI feature has been enabled (acknowledged) for use.
	 *
	 * @return bool True only when its lifecycle is `enabled`.
	 */
	private function featureEnabled(): bool {
		try {
			$feature = $this->features->findBySlugForGate(slug: self::FEATURE_SLUG);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[TranslationChain] The feature lookup failed; treated as not enabled',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}

		return ($feature !== null && (string)($feature->getObject()['lifecycle'] ?? '') === 'enabled');
	}//end featureEnabled()

	/**
	 * The person's active organisation, or '' for instance scope.
	 *
	 * @param string $uid The person, or ''.
	 *
	 * @return string The organisation uuid, or ''.
	 */
	private function organisationOf(string $uid): string {
		if ($uid === '') {
			return '';
		}

		try {
			return (string)($this->organisations->getActiveOrganisationWithFallback($uid) ?? '');
		} catch (Throwable $e) {
			return '';
		}
	}//end organisationOf()
}//end class
