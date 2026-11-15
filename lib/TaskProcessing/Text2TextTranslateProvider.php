<?php

/**
 * Hermiq `core:text2text:translate` provider: translates a text into the language the task names, from the language it names or a detected one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category TaskProcessing
 * @package  OCA\Hermiq\TaskProcessing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\TaskProcessing;

use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;

/**
 * Hermiq's provider for the `core:text2text:translate` task type.
 *
 * The task's `origin_language` and `target_language` slots are enums, so the
 * provider offers the languages it translates between. The origin may be left
 * to detection; the target may not.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
 */
class Text2TextTranslateProvider extends AbstractTextProvider {

	/**
	 * The origin value that leaves the source language to the model.
	 */
	public const DETECT = 'detect_language';

	/**
	 * The languages offered, by code, with the English name the prompt uses.
	 */
	public const LANGUAGES = [
		'nl' => 'Dutch',
		'en' => 'English',
		'de' => 'German',
		'fr' => 'French',
		'es' => 'Spanish',
		'it' => 'Italian',
		'pt' => 'Portuguese',
		'pl' => 'Polish',
		'tr' => 'Turkish',
		'ar' => 'Arabic',
		'uk' => 'Ukrainian',
		'fy' => 'Frisian',
	];

	/**
	 * The unique id of this provider.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getId(): string {
		return 'hermiq:text2text:translate';
	}//end getId()

	/**
	 * The provider's name as the Assistant shows it.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getName(): string {
		return 'Hermiq';
	}//end getName()

	/**
	 * The task type this provider answers.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getTaskTypeId(): string {
		return TextToTextTranslate::ID;
	}//end getTaskTypeId()

	/**
	 * The languages the two language slots offer; the origin also offers detection.
	 *
	 * @return array Slot name => the ShapeEnumValue options it offers.
	 *
	 * @psalm-return array<string, list<ShapeEnumValue>>
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getInputShapeEnumValues(): array {
		$languages = [];
		foreach (self::LANGUAGES as $code => $name) {
			$languages[] = new ShapeEnumValue($name, $code);
		}

		return [
			'origin_language' => [new ShapeEnumValue('Detect language', self::DETECT), ...$languages],
			'target_language' => $languages,
		];
	}//end getInputShapeEnumValues()

	/**
	 * The origin defaults to detection.
	 *
	 * @return array Slot name => its default value.
	 *
	 * @psalm-return array<array-key, string|numeric>
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	public function getInputShapeDefaults(): array {
		return ['origin_language' => self::DETECT];
	}//end getInputShapeDefaults()

	/**
	 * The translation prompt, naming the target language and, when given, the origin.
	 *
	 * @param string               $text  The text to translate.
	 * @param array<string, mixed> $input The whole task input.
	 *
	 * @return string The prompt.
	 *
	 * @throws ProcessingException When the target language is missing or not offered.
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/specs/taskprocessing-provide-text2text/spec.md#requirement-text-can-be-reformulated-or-translated-in-one-click-through-hermiq-req-t2t-003
	 */
	protected function promptFor(string $text, array $input): string {
		$target = self::LANGUAGES[(string)($input['target_language'] ?? '')] ?? null;
		if ($target === null) {
			throw new ProcessingException('Hermiq translation requires a target language it offers.');
		}

		$origin = self::LANGUAGES[(string)($input['origin_language'] ?? '')] ?? null;
		$from = '';
		if ($origin !== null) {
			$from = ' from ' . $origin;
		}

		return 'Translate the following text' . $from . ' into ' . $target . '. Keep its meaning, tone and layout. '
			. "Reply with only the translation.\n\n" . $text;
	}//end promptFor()

	/**
	 * Not used: a translation needs its languages, so promptFor() frames it.
	 * Without a target this refuses rather than guess one.
	 *
	 * @param string $input The text.
	 *
	 * @return string Never returns.
	 *
	 * @throws ProcessingException Always.
	 */
	protected function buildPrompt(string $input): string {
		return $this->promptFor(text: $input, input: []);
	}//end buildPrompt()
}//end class
