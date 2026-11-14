<?php

/**
 * Hermiq TranslationChain: transcribe, translate under the feature, speak, and record each step.
 *
 * Drives the real chain with the real SpeechVoiceMap, TranslatedAudioFiles and
 * AgentArtefactMarker; the speech sidecar, the provider layer, Files, the system
 * tags, the feature register and the residency registry are doubles
 * (chat-translate-a-recording tasks 1 and 2).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Speech
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
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Speech;

use OCA\Hermiq\Kernel\Contracts\ProviderFactoryInterface;
use OCA\Hermiq\Kernel\Contracts\SpeechClientInterface;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\Speech\SpeechTranslationException;
use OCA\Hermiq\Service\Speech\SpeechVoiceMap;
use OCA\Hermiq\Service\Speech\TranslatedAudioFiles;
use OCA\Hermiq\Service\Speech\TranslationChain;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the translate-a-recording chain.
 */
class TranslationChainTest extends TestCase {

	/**
	 * The calls made, in order: transcribe, driver, generate, synthesise.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The arguments generateText() was called with.
	 *
	 * @var array<string, mixed>
	 */
	private array $generated = [];

	/**
	 * The voice synthesise() was called with.
	 *
	 * @var string
	 */
	private string $spokenWith = '';

	/**
	 * Files written into the person's folder, by name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Whether the written file was deleted.
	 *
	 * @var bool
	 */
	private bool $deleted = false;

	/**
	 * The chain over doubles.
	 *
	 * @param string                 $lifecycle   The feature's lifecycle.
	 * @param array<string, string>  $voices      The stored voice map.
	 * @param RuntimeException|null  $driverFails What createChatDriver() throws, if anything.
	 * @param string                 $heard       What the sidecar transcribes.
	 * @param bool                   $tagFails    Whether assigning the tag throws.
	 *
	 * @return TranslationChain The chain.
	 */
	private function chain(
		string $lifecycle = 'enabled',
		array $voices = ['nl' => 'nf_dutch', 'fr' => 'ff_siwis'],
		?RuntimeException $driverFails = null,
		string $heard = 'مرحبا، أرجو أن تتصلوا بي',
		bool $tagFails = false,
	): TranslationChain {
		$speech = $this->createMock(SpeechClientInterface::class);
		$speech->method('transcribe')->willReturnCallback(
			function (string $audio, string $language = '') use ($heard): array {
				$this->calls[] = 'transcribe:' . $language;
				return ['text' => $heard, 'language' => 'ar', 'engine' => 'faster-whisper'];
			}
		);
		$speech->method('synthesise')->willReturnCallback(
			function (string $text, string $voice = 'af_heart'): array {
				$this->calls[] = 'synthesise';
				$this->spokenWith = $voice;
				return ['audio' => 'RIFF-wav-bytes', 'engine' => 'kokoro'];
			}
		);

		$providers = $this->createMock(ProviderFactoryInterface::class);
		$providers->method('getLlmConfig')->willReturn(['chatProvider' => 'ollama']);
		$providers->method('createChatDriver')->willReturnCallback(
			function (array $llmConfig, ?string $agentModel = null, ?float $agentTemperature = null, ?string $organisation = null, ?int $agentMaxTokens = null, ?string $aiFeature = null) use ($driverFails): ChatDriver {
				$this->calls[] = 'driver:' . $organisation . ':' . $aiFeature;
				if ($driverFails !== null) {
					throw $driverFails;
				}

				return new ChatDriver(provider: 'ollama', chat: null, model: 'qwen3');
			}
		);
		$providers->method('generateText')->willReturnCallback(
			function (string $prompt, ?string $userId = null, bool $allowNextcloud = true, ?string $organisation = null, ?string $aiFeature = null): string {
				$this->calls[] = 'generate';
				$this->generated = compact('prompt', 'userId', 'allowNextcloud', 'organisation', 'aiFeature');
				return "  Hallo, wilt u mij terugbellen?\n";
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn((string)json_encode($voices));

		$feature = new ObjectEntity();
		$feature->setObject(['slug' => 'speech-translation', 'lifecycle' => $lifecycle]);
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlugForGate')->willReturn($feature);

		$residencies = $this->createMock(ProviderResidencyRegistry::class);
		$residencies->method('forProvider')->willReturnCallback(
			static fn (string $provider): array => ['provider' => $provider, 'residency' => 'on-premise', 'location' => 'Utrecht']
		);

		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-utrecht');

		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('12');
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTag')->willReturn($tag);
		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		if ($tagFails === true) {
			$mapper->method('assignTags')->willThrowException(new RuntimeException('tag store down'));
		}

		return new TranslationChain(
			speech: $speech,
			providers: $providers,
			voices: new SpeechVoiceMap(appConfig: $config),
			files: new TranslatedAudioFiles(rootFolder: $this->root()),
			marker: new AgentArtefactMarker(tagManager: $tags, tagMapper: $mapper, logger: new NullLogger()),
			features: $features,
			residencies: $residencies,
			organisations: $organisations,
			logger: new NullLogger()
		);
	}//end chain()

	/**
	 * A Files root whose `Hermiq/Translations` folder records what is written.
	 *
	 * @return IRootFolder The root.
	 */
	private function root(): IRootFolder {
		$created = $this->createMock(File::class);
		$created->method('getId')->willReturn(733);
		$created->method('getName')->willReturn('ar-to-nl.wav');
		$created->method('getSize')->willReturn(14);
		$created->method('delete')->willReturnCallback(function (): void {
			$this->deleted = true;
		});

		$leaf = $this->createMock(Folder::class);
		$leaf->method('getNonExistingName')->willReturnArgument(0);
		$leaf->method('newFile')->willReturnCallback(
			function (string $name, $content) use ($created): File {
				$this->written[$name] = (string)$content;
				return $created;
			}
		);
		$middle = $this->createMock(Folder::class);
		$middle->method('nodeExists')->willReturn(false);
		$middle->method('newFolder')->willReturn($leaf);
		$home = $this->createMock(Folder::class);
		$home->method('nodeExists')->willReturn(true);
		$home->method('get')->willReturn($middle);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		return $root;
	}//end root()

	/**
	 * An Arabic voicemail into Dutch: transcript, translation, audio and a record of all three engines.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-front-office-employee-translates-a-voicemail
	 */
	public function testAnArabicRecordingIsTranslatedIntoDutchAndSpokenWithTheDutchVoice(): void {
		$result = $this->chain()->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: 'balie');

		$this->assertSame('مرحبا، أرجو أن تتصلوا بي', $result['transcript']);
		$this->assertSame('Hallo, wilt u mij terugbellen?', $result['translation']);
		$this->assertSame('RIFF-wav-bytes', $result['audio']);
		$this->assertTrue($result['spoken']);
		$this->assertSame(
			[
				'sourceLanguage' => '',
				'detectedLanguage' => 'ar',
				'targetLanguage' => 'nl',
				'sttEngine' => 'faster-whisper',
				'translationProvider' => 'ollama',
				'translationModel' => 'qwen3',
				'translationResidency' => 'on-premise',
				'ttsEngine' => 'kokoro',
				'ttsVoice' => 'nf_dutch',
			],
			$result['record']
		);
		$this->assertSame('nf_dutch', $this->spokenWith);
	}//end testAnArabicRecordingIsTranslatedIntoDutchAndSpokenWithTheDutchVoice()

	/**
	 * The translation runs under the feature, as the person, in their organisation, with the detected language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
	 */
	public function testTheTranslationRunsUnderTheFeatureInThePersonsOrganisation(): void {
		$this->chain()->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: 'balie');

		$this->assertSame('speech-translation', $this->generated['aiFeature']);
		$this->assertSame('org-utrecht', $this->generated['organisation']);
		$this->assertSame('balie', $this->generated['userId']);
		$this->assertTrue($this->generated['allowNextcloud']);
		$this->assertStringContainsString('"ar"', $this->generated['prompt']);
		$this->assertStringContainsString('"nl"', $this->generated['prompt']);
		$this->assertStringContainsString('مرحبا، أرجو أن تتصلوا بي', $this->generated['prompt']);
		$this->assertSame(['driver:org-utrecht:speech-translation', 'transcribe:', 'generate', 'synthesise'], $this->calls);
	}//end testTheTranslationRunsUnderTheFeatureInThePersonsOrganisation()

	/**
	 * A source language the person gives is passed to transcription and to the translation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002
	 */
	public function testAGivenSourceLanguageIsUsedAndRecorded(): void {
		$result = $this->chain()->translate(audio: 'm4a-bytes', targetLanguage: 'fr', sourceLanguage: 'en', uid: 'balie');

		$this->assertSame('transcribe:en', $this->calls[1]);
		$this->assertStringContainsString('"en"', $this->generated['prompt']);
		$this->assertSame('en', $result['record']['sourceLanguage']);
		$this->assertSame('ff_siwis', $result['record']['ttsVoice']);
	}//end testAGivenSourceLanguageIsUsedAndRecorded()

	/**
	 * No voice for the target: the chain stops after the translation, with no audio and no speech call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-language-without-a-voice-gets-text
	 */
	public function testALanguageWithoutAVoiceGetsTheTextOnly(): void {
		$result = $this->chain(voices: ['fr' => 'ff_siwis'])->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: 'balie');

		$this->assertSame('Hallo, wilt u mij terugbellen?', $result['translation']);
		$this->assertNull($result['audio']);
		$this->assertFalse($result['spoken']);
		$this->assertSame('', $result['record']['ttsEngine']);
		$this->assertNotContains('synthesise', $this->calls);
	}//end testALanguageWithoutAVoiceGetsTheTextOnly()

	/**
	 * A residency refusal stops the run before the recording is transcribed, naming feature and residency.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-an-on-premise-requirement-stops-a-hosted-translation
	 */
	public function testAnOnPremiseRequirementStopsAHostedTranslationBeforeAnythingIsSent(): void {
		$chain = $this->chain(driverFails: new ResidencyViolationException('speech-translation', 'on-premise', 'outside-eu', 'openai'));

		try {
			$chain->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: 'balie');
			$this->fail('A residency refusal must stop the run.');
		} catch (SpeechTranslationException $e) {
			$this->assertSame('provider_refused', $e->errorCode);
			$this->assertSame(422, $e->status());
			$this->assertStringContainsString('speech-translation', $e->getMessage());
			$this->assertStringContainsString('on-premise', $e->getMessage());
		}

		$this->assertSame(['driver:org-utrecht:speech-translation'], $this->calls, 'Nothing is transcribed or sent.');
	}//end testAnOnPremiseRequirementStopsAHostedTranslationBeforeAnythingIsSent()

	/**
	 * A feature that is not enabled refuses the run with the feature named, before any call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-2-the-feature-its-residency-and-the-pre-start-notice
	 */
	public function testAFeatureThatIsNotEnabledIsRefusedWithItsName(): void {
		try {
			$this->chain(lifecycle: 'disabled')->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: 'balie');
			$this->fail('A disabled feature must refuse the run.');
		} catch (SpeechTranslationException $e) {
			$this->assertSame('feature_not_enabled', $e->errorCode);
			$this->assertSame(409, $e->status());
			$this->assertStringContainsString('speech-translation', $e->getMessage());
		}

		$this->assertSame([], $this->calls);
	}//end testAFeatureThatIsNotEnabledIsRefusedWithItsName()

	/**
	 * A malformed target language is refused before anything runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
	 */
	public function testAMalformedTargetLanguageIsRefused(): void {
		$this->expectException(SpeechTranslationException::class);
		$this->expectExceptionMessage('Choose the language to translate into.');

		$this->chain()->translate(audio: 'm4a-bytes', targetLanguage: 'Dutch!', uid: 'balie');
	}//end testAMalformedTargetLanguageIsRefused()

	/**
	 * A recording with no speech in it is refused after transcription, and nothing is translated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
	 */
	public function testARecordingWithNoSpeechIsRefused(): void {
		try {
			$this->chain(heard: '   ')->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: 'balie');
			$this->fail('A silent recording must be refused.');
		} catch (SpeechTranslationException $e) {
			$this->assertSame('nothing_heard', $e->errorCode);
		}

		$this->assertNotContains('generate', $this->calls);
	}//end testARecordingWithNoSpeechIsRefused()

	/**
	 * A TaskProcessing call refuses the `nextcloud` provider for the translation step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-other-apps-can-use-the-chain-through-taskprocessing-where-nextcloud-defines-it-req-strn-004
	 */
	public function testTheRecursionGuardIsPassedToTheProviderLayer(): void {
		$this->chain()->translate(audio: 'm4a-bytes', targetLanguage: 'nl', uid: null, allowNextcloud: false);

		$this->assertFalse($this->generated['allowNextcloud']);
		$this->assertSame('', $this->generated['organisation']);
	}//end testTheRecursionGuardIsPassedToTheProviderLayer()

	/**
	 * The spoken result is saved under Hermiq/Translations, named after both languages, and marked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testTheSpokenResultIsKeptInFilesUnderTranslations(): void {
		$file = $this->chain()->keep(uid: 'balie', audio: 'RIFF-wav-bytes', record: ['sourceLanguage' => '', 'detectedLanguage' => 'ar', 'targetLanguage' => 'nl']);

		$this->assertSame(733, $file['fileId']);
		$this->assertSame('Hermiq/Translations/ar-to-nl.wav', $file['path']);
		$this->assertSame('audio/wav', $file['mimeType']);
		$names = array_keys($this->written);
		$this->assertCount(1, $names);
		$this->assertMatchesRegularExpression('/^ar-to-nl-\d{4}-\d{2}-\d{2}-\d{6}\.wav$/', $names[0]);
		$this->assertSame('RIFF-wav-bytes', $this->written[$names[0]]);
		$this->assertFalse($this->deleted);
	}//end testTheSpokenResultIsKeptInFilesUnderTranslations()

	/**
	 * A file that cannot be marked is deleted and the keep fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-3-the-route-the-saved-file-and-its-mark
	 */
	public function testAFileThatCannotBeMarkedIsNotKept(): void {
		try {
			$this->chain(tagFails: true)->keep(uid: 'balie', audio: 'RIFF-wav-bytes', record: ['targetLanguage' => 'nl']);
			$this->fail('An unmarked file must not be kept.');
		} catch (SpeechTranslationException $e) {
			$this->assertSame('marking_failed', $e->errorCode);
		}

		$this->assertTrue($this->deleted);
	}//end testAFileThatCannotBeMarkedIsNotKept()

	/**
	 * Where the translation runs: the bound provider, model and declared residency.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003
	 */
	public function testWhereTheTranslationRunsIsReportedBeforeTheStart(): void {
		$where = $this->chain()->whereTranslationRuns(uid: 'balie');

		$this->assertSame(
			[
				'enabled' => true,
				'provider' => 'ollama',
				'model' => 'qwen3',
				'residency' => 'on-premise',
				'location' => 'Utrecht',
				'refusal' => '',
				'voices' => ['fr', 'nl'],
			],
			$where
		);
	}//end testWhereTheTranslationRunsIsReportedBeforeTheStart()

	/**
	 * A refused binding is reported as the refusal, not as a place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-an-on-premise-requirement-stops-a-hosted-translation
	 */
	public function testARefusedBindingIsReportedAsTheRefusal(): void {
		$where = $this->chain(driverFails: new ResidencyViolationException('speech-translation', 'on-premise', 'outside-eu', 'openai'))
			->whereTranslationRuns(uid: 'balie');

		$this->assertSame('', $where['provider']);
		$this->assertStringContainsString('on-premise', $where['refusal']);
	}//end testARefusedBindingIsReportedAsTheRefusal()
}//end class
