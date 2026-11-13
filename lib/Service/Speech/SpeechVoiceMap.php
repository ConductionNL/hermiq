<?php

/**
 * Hermiq SpeechVoiceMap.
 *
 * The administrator's map from a language code to a voice of the configured
 * speech model, kept in app config as `speech_voices`. A language without a
 * voice gets a written translation only; a voice is never guessed from the
 * shape of its id, because voice ids are a model's naming convention, not a
 * contract.
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
 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Speech;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads and stores the language-to-voice map.
 *
 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001
 */
class SpeechVoiceMap {

	/**
	 * The app config key.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'speech_voices';

	/**
	 * The most languages the map holds.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 100;

	/**
	 * A language code: a two or three letter primary subtag, optionally a region or script.
	 *
	 * @var string
	 */
	private const LANGUAGE_PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/';

	/**
	 * A voice id: letters, digits, underscore, dot and hyphen.
	 *
	 * @var string
	 */
	private const VOICE_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config holding the map.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The stored map, with any entry that is not a valid language and voice left out.
	 *
	 * @return array<string, string> Voice id per language code.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function all(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$map = [];
		foreach ($decoded as $language => $voice) {
			if ($this->isLanguage(value: $language) === true && $this->isVoice(value: $voice) === true) {
				$map[$this->normalise(language: (string)$language)] = (string)$voice;
			}
		}

		ksort($map);
		return $map;
	}//end all()

	/**
	 * The voice for one language: an exact match first, then its primary subtag.
	 *
	 * @param string $language The language code, e.g. `fr` or `pt-BR`.
	 *
	 * @return string|null The voice id, or null when no voice is mapped.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#scenario-a-language-without-a-voice-gets-text
	 */
	public function voiceFor(string $language): ?string {
		if ($this->isLanguage(value: $language) === false) {
			return null;
		}

		$map = $this->all();
		$language = $this->normalise(language: $language);
		if (isset($map[$language]) === true) {
			return $map[$language];
		}

		$primary = explode('-', $language)[0];
		return ($map[$primary] ?? null);
	}//end voiceFor()

	/**
	 * Replace the map.
	 *
	 * @param mixed $map The new map: an object of voice id per language code.
	 *
	 * @return array<string, string> The stored map.
	 *
	 * @throws InvalidArgumentException When the map, a language code or a voice id is not valid.
	 *
	 * @spec openspec/changes/chat-translate-a-recording/tasks.md#task-4-the-voice-map-in-admin-settings
	 */
	public function save(mixed $map): array {
		if (is_array($map) === false) {
			throw new InvalidArgumentException('The voice map is an object of a voice per language.');
		}

		if (count($map) > self::MAX_ENTRIES) {
			throw new InvalidArgumentException('The voice map holds at most ' . self::MAX_ENTRIES . ' languages.');
		}

		$stored = [];
		foreach ($map as $language => $voice) {
			if ($this->isLanguage(value: $language) === false) {
				throw new InvalidArgumentException('"' . (string)$language . '" is not a language code.');
			}

			if ($this->isVoice(value: $voice) === false) {
				throw new InvalidArgumentException('The voice for "' . (string)$language . '" is not a voice id.');
			}

			$stored[$this->normalise(language: (string)$language)] = (string)$voice;
		}

		ksort($stored);
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($stored));
		return $stored;
	}//end save()

	/**
	 * Whether a value is a language code.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a code such as `nl` or `pt-BR`.
	 */
	private function isLanguage(mixed $value): bool {
		return is_string($value) === true && preg_match(self::LANGUAGE_PATTERN, $value) === 1;
	}//end isLanguage()

	/**
	 * Whether a value is a voice id.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an id such as `ff_siwis`.
	 */
	private function isVoice(mixed $value): bool {
		return is_string($value) === true && preg_match(self::VOICE_PATTERN, $value) === 1;
	}//end isVoice()

	/**
	 * A language code with its primary subtag lower-cased.
	 *
	 * @param string $language The code.
	 *
	 * @return string The normalised code.
	 */
	private function normalise(string $language): string {
		$parts = explode('-', $language);
		$parts[0] = strtolower($parts[0]);
		return implode('-', $parts);
	}//end normalise()
}//end class
