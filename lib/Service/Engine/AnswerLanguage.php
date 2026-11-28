<?php
/**
 * Hermiq: the language a person asked agents to answer in (personal-preferences).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\PersonalPreferences;

/**
 * Turns the person's "Taal van antwoorden" preference into one instruction
 * for the context a run starts with.
 *
 * Only the languages the settings dialog offers are understood. Any other
 * stored value adds nothing, so the preference can never carry free text
 * into an agent's instructions.
 *
 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */
class AnswerLanguage {

	/**
	 * The instruction per offered language code.
	 *
	 * @var array<string, string>
	 */
	private const INSTRUCTIONS = [
		'nl'    => 'Answer in Dutch.',
		'nl-b1' => 'Answer in Dutch, in plain language at B1 level: short sentences and common words.',
		'en'    => 'Answer in English.',
		'de'    => 'Answer in German.',
		'fr'    => 'Answer in French.',
	];

	/**
	 * Constructor.
	 *
	 * @param PersonalPreferences $preferences The person's own preferences.
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function __construct(
		private readonly PersonalPreferences $preferences,
	) {
	}//end __construct()

	/**
	 * The instruction block for a person, or '' when they chose no language or
	 * one that is not offered.
	 *
	 * @param string $userId The person the run acts for.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
	 */
	public function instructionFor(string $userId): string {
		$code = strtolower($this->preferences->value(userId: $userId, key: PersonalPreferences::ANSWER_LANGUAGE));
		if (isset(self::INSTRUCTIONS[$code]) === false) {
			return '';
		}

		return "Answer language (the person's own preference):\n" . self::INSTRUCTIONS[$code];
	}//end instructionFor()
}//end class
