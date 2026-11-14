<?php

/**
 * Hermiq SpeechVoiceMap: the administrator's voice per language.
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Speech;

use InvalidArgumentException;
use OCA\Hermiq\Service\Speech\SpeechVoiceMap;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the voice map.
 */
class SpeechVoiceMapTest extends TestCase {

	/**
	 * What was written to app config, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A map over an app config holding the given raw value.
	 *
	 * @param string $raw The stored JSON.
	 *
	 * @return SpeechVoiceMap The map.
	 */
	private function map(string $raw): SpeechVoiceMap {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'speech_voices' ? $raw : $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;
				return true;
			}
		);
		return new SpeechVoiceMap(appConfig: $config);
	}//end map()

	/**
	 * A mapped language gets its voice; a region falls back to its primary language.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testAVoiceIsFoundByLanguageAndByPrimaryLanguage(): void {
		$map = $this->map('{"fr":"ff_siwis","pt-BR":"pf_dora","en":"af_heart"}');

		$this->assertSame('ff_siwis', $map->voiceFor(language: 'fr'));
		$this->assertSame('pf_dora', $map->voiceFor(language: 'pt-BR'));
		$this->assertSame('af_heart', $map->voiceFor(language: 'en-GB'));
		$this->assertNull($map->voiceFor(language: 'nl'));
		$this->assertNull($map->voiceFor(language: '../etc'));
	}//end testAVoiceIsFoundByLanguageAndByPrimaryLanguage()

	/**
	 * A corrupt or hand-edited value never yields an invalid entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testInvalidStoredEntriesAreLeftOut(): void {
		$this->assertSame([], $this->map('not json')->all());
		$this->assertSame(['fr' => 'ff_siwis'], $this->map('{"fr":"ff_siwis","x":"a","de":"bad voice!","es":42}')->all());
	}//end testInvalidStoredEntriesAreLeftOut()

	/**
	 * Saving stores the map sorted by language under `speech_voices`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testSavingStoresTheSortedMap(): void {
		$stored = $this->map('')->save(map: ['fr' => 'ff_siwis', 'EN' => 'af_heart']);

		$this->assertSame(['en' => 'af_heart', 'fr' => 'ff_siwis'], $stored);
		$this->assertSame('{"en":"af_heart","fr":"ff_siwis"}', $this->stored['hermiq/speech_voices']);
	}//end testSavingStoresTheSortedMap()

	/**
	 * A language that is not a code is refused, and nothing is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testAnInvalidLanguageIsRefused(): void {
		try {
			$this->map('')->save(map: ['French' => 'ff_siwis']);
			$this->fail('An invalid language must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('"French" is not a language code', $e->getMessage());
		}

		$this->assertSame([], $this->stored);
	}//end testAnInvalidLanguageIsRefused()

	/**
	 * A voice that is not a voice id, or a map that is not an object, is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function testAnInvalidVoiceOrShapeIsRefused(): void {
		try {
			$this->map('')->save(map: ['fr' => 'ff siwis; rm']);
			$this->fail('An invalid voice must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('The voice for "fr"', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->map('')->save(map: 'fr=ff_siwis');
	}//end testAnInvalidVoiceOrShapeIsRefused()
}//end class
