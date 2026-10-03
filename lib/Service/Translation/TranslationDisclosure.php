<?php

/**
 * Hermiq TranslationDisclosure (ai-translation-provenance).
 *
 * The sentence a reader sees next to an AI-made translation: "Translated by AI
 * from Dutch. This translation may contain errors.", in the reader's language
 * where this table has one. The sentence is fixed text, never model output: a
 * disclosure written by the model it discloses is a claim nobody can check.
 *
 * The table below was drafted by an AI lane on 2026-09-27 and awaits a human
 * translator (design.md D4 of ai-translation-provenance lists every row).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Translation
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
 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Translation;

/**
 * Builds the fixed AI-translation disclosure sentence for a language pair.
 *
 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in
 */
class TranslationDisclosure {

	/**
	 * A BCP-47 shaped tag: a 2 or 3 letter primary subtag, then optional
	 * 1 to 8 character alphanumeric subtags. Deliberately a shape check, not a
	 * registry lookup: its job is to keep free text out of a prompt.
	 *
	 * @var string
	 */
	public const LANGUAGE_TAG_PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/';

	/**
	 * The BCP-47 tag for an undetermined language.
	 *
	 * @var string
	 */
	public const UNDETERMINED = 'und';

	/**
	 * The language the disclosure falls back to.
	 *
	 * @var string
	 */
	private const FALLBACK_LANGUAGE = 'en';

	/**
	 * Per target language: the sentence naming the source language (`%s`) and
	 * the sentence for an undetermined source. Languages whose grammar would
	 * inflect "from <language>" use "Original language: <language>" instead,
	 * so the language name never needs a case ending.
	 *
	 * @var array<string, array{named: string, unnamed: string}>
	 */
	private const SENTENCES = [
		'en' => [
			'named'   => 'Translated by AI from %s. This translation may contain errors.',
			'unnamed' => 'Translated by AI. This translation may contain errors.',
		],
		'nl' => [
			'named'   => 'Vertaald door AI. Oorspronkelijke taal: %s. Deze vertaling kan fouten bevatten.',
			'unnamed' => 'Vertaald door AI. Deze vertaling kan fouten bevatten.',
		],
		'de' => [
			'named'   => 'Von KI übersetzt. Originalsprache: %s. Diese Übersetzung kann Fehler enthalten.',
			'unnamed' => 'Von KI übersetzt. Diese Übersetzung kann Fehler enthalten.',
		],
		'fr' => [
			'named'   => "Traduit par IA. Langue d'origine : %s. Cette traduction peut contenir des erreurs.",
			'unnamed' => 'Traduit par IA. Cette traduction peut contenir des erreurs.',
		],
		'es' => [
			'named'   => 'Traducido por IA. Idioma original: %s. Esta traducción puede contener errores.',
			'unnamed' => 'Traducido por IA. Esta traducción puede contener errores.',
		],
		'it' => [
			'named'   => "Tradotto dall'IA. Lingua originale: %s. Questa traduzione può contenere errori.",
			'unnamed' => "Tradotto dall'IA. Questa traduzione può contenere errori.",
		],
		'pt' => [
			'named'   => 'Traduzido por IA. Idioma original: %s. Esta tradução pode conter erros.',
			'unnamed' => 'Traduzido por IA. Esta tradução pode conter erros.',
		],
		'pl' => [
			'named'   => 'Przetłumaczone przez AI. Język oryginału: %s. To tłumaczenie może zawierać błędy.',
			'unnamed' => 'Przetłumaczone przez AI. To tłumaczenie może zawierać błędy.',
		],
		'ro' => [
			'named'   => 'Tradus de IA. Limba originală: %s. Această traducere poate conține erori.',
			'unnamed' => 'Tradus de IA. Această traducere poate conține erori.',
		],
		'bg' => [
			'named'   => 'Преведено от ИИ. Оригинален език: %s. Този превод може да съдържа грешки.',
			'unnamed' => 'Преведено от ИИ. Този превод може да съдържа грешки.',
		],
		'tr' => [
			'named'   => 'Yapay zekâ ile çevrildi. Orijinal dil: %s. Bu çeviri hatalar içerebilir.',
			'unnamed' => 'Yapay zekâ ile çevrildi. Bu çeviri hatalar içerebilir.',
		],
		'ar' => [
			'named'   => 'تُرجم بواسطة الذكاء الاصطناعي. اللغة الأصلية: %s. قد تحتوي هذه الترجمة على أخطاء.',
			'unnamed' => 'تُرجم بواسطة الذكاء الاصطناعي. قد تحتوي هذه الترجمة على أخطاء.',
		],
		'uk' => [
			'named'   => 'Перекладено ШІ. Мова оригіналу: %s. Цей переклад може містити помилки.',
			'unnamed' => 'Перекладено ШІ. Цей переклад може містити помилки.',
		],
		'ru' => [
			'named'   => 'Переведено ИИ. Язык оригинала: %s. Этот перевод может содержать ошибки.',
			'unnamed' => 'Переведено ИИ. Этот перевод может содержать ошибки.',
		],
		'fa' => [
			'named'   => 'ترجمه با هوش مصنوعی. زبان اصلی: %s. این ترجمه ممکن است خطا داشته باشد.',
			'unnamed' => 'ترجمه با هوش مصنوعی. این ترجمه ممکن است خطا داشته باشد.',
		],
		'zh' => [
			'named'   => '由人工智能翻译。原文语言：%s。此译文可能有错误。',
			'unnamed' => '由人工智能翻译。此译文可能有错误。',
		],
	];

	/**
	 * Each language's own name for itself: the fallback when ext-intl cannot
	 * name the source language in the reader's language.
	 *
	 * @var array<string, string>
	 */
	private const ENDONYMS = [
		'en' => 'English',
		'nl' => 'Nederlands',
		'de' => 'Deutsch',
		'fr' => 'français',
		'es' => 'español',
		'it' => 'italiano',
		'pt' => 'português',
		'pl' => 'polski',
		'ro' => 'română',
		'bg' => 'български',
		'tr' => 'Türkçe',
		'ar' => 'العربية',
		'uk' => 'українська',
		'ru' => 'русский',
		'fa' => 'فارسی',
		'zh' => '中文',
		'so' => 'Soomaali',
		'ti' => 'ትግርኛ',
		'prs' => 'دری',
		'ps' => 'پښتو',
		'ku' => 'Kurdî',
		'am' => 'አማርኛ',
		'hu' => 'magyar',
		'lt' => 'lietuvių',
		'lv' => 'latviešu',
		'sk' => 'slovenčina',
		'cs' => 'čeština',
		'hr' => 'hrvatski',
		'sr' => 'српски',
		'bs' => 'bosanski',
		'sq' => 'shqip',
		'el' => 'Ελληνικά',
		'hi' => 'हिन्दी',
		'ur' => 'اردو',
		'vi' => 'Tiếng Việt',
		'th' => 'ไทย',
		'id' => 'Bahasa Indonesia',
		'tl' => 'Tagalog',
		'fy' => 'Frysk',
		'pap' => 'Papiamentu',
		'srn' => 'Sranantongo',
	];

	/**
	 * Whether a value is a BCP-47 shaped language tag.
	 *
	 * @param mixed $tag The candidate.
	 *
	 * @return bool True for a well-shaped tag.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-010-language-tags-and-the-original-reference-are-validated-before-any-prompt
	 */
	public function isLanguageTag(mixed $tag): bool {
		return is_string($tag) === true && preg_match(self::LANGUAGE_TAG_PATTERN, $tag) === 1;
	}//end isLanguageTag()

	/**
	 * The primary subtag of a tag, lower-cased (`pt-BR` becomes `pt`).
	 *
	 * @param string $tag A BCP-47 tag.
	 *
	 * @return string The primary subtag.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in
	 */
	public function primarySubtag(string $tag): string {
		return strtolower(explode('-', $tag)[0]);
	}//end primarySubtag()

	/**
	 * The disclosure sentence for a translation from `$sourceLanguage` into
	 * `$targetLanguage`, and the language that sentence is written in.
	 *
	 * @param string $sourceLanguage The source tag, or `und`.
	 * @param string $targetLanguage The target tag.
	 *
	 * @return array{disclosure: string, disclosureLanguage: string} The sentence and its language.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in
	 */
	public function forLanguages(string $sourceLanguage, string $targetLanguage): array {
		$language = $this->primarySubtag(tag: $targetLanguage);
		if (array_key_exists($language, self::SENTENCES) === false) {
			$language = self::FALLBACK_LANGUAGE;
		}

		$sentences = self::SENTENCES[$language];
		if ($this->primarySubtag(tag: $sourceLanguage) === self::UNDETERMINED || $sourceLanguage === '') {
			return ['disclosure' => $sentences['unnamed'], 'disclosureLanguage' => $language];
		}

		return [
			'disclosure'         => sprintf($sentences['named'], $this->languageName(tag: $sourceLanguage, inLanguage: $language)),
			'disclosureLanguage' => $language,
		];
	}//end forLanguages()

	/**
	 * The name of language `$tag`, written in language `$inLanguage` when
	 * ext-intl can, else the language's own name for itself, else the tag.
	 *
	 * @param string $tag The language to name.
	 * @param string $inLanguage The language to write the name in.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in
	 */
	public function languageName(string $tag, string $inLanguage): string {
		$primary = $this->primarySubtag(tag: $tag);

		if ($this->intlAvailable() === true) {
			$name = (string)locale_get_display_language($primary, $inLanguage);
			if ($name !== '' && strtolower($name) !== $primary) {
				return $name;
			}
		}

		return self::ENDONYMS[$primary] ?? strtoupper($primary);
	}//end languageName()

	/**
	 * Whether ext-intl can name languages on this machine. A seam, so a test can
	 * pin the endonym path and get a result that does not depend on the machine.
	 *
	 * @return bool True when `locale_get_display_language()` exists.
	 *
	 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-009-the-disclosure-is-written-in-the-target-language-or-says-which-language-it-is-in
	 */
	protected function intlAvailable(): bool {
		return function_exists('locale_get_display_language');
	}//end intlAvailable()
}//end class
