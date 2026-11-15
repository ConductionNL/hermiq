<?php

/**
 * Contract of LessonAuthoringEngine
 *
 * The public surface of LessonAuthoringEngine that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

/**
 * Contract of LessonAuthoringEngine.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LessonAuthoringEngineInterface {

	/**
	 * Action: draft a lesson outline from learning goals.
	 *
	 * @var string
	 */
	public const ACTION_OUTLINE = 'outline';

	/**
	 * Action: suggest questions for a lesson.
	 *
	 * @var string
	 */
	public const ACTION_QUESTIONS = 'questions';

	/**
	 * Action: rewrite a text at a lower reading level.
	 *
	 * @var string
	 */
	public const ACTION_SIMPLIFY = 'simplify';

	/**
	 * Action: suggest which given goals a lesson covers.
	 *
	 * @var string
	 */
	public const ACTION_GOAL_SUGGESTIONS = 'goal-suggestions';

	/**
	 * The reading levels `simplify` accepts, each with the description the prompt uses.
	 *
	 * @var array<string, string>
	 */
	public const READING_LEVELS = [
		'A1' => 'CEFR A1: very short sentences and only the most common everyday words',
		'A2' => 'CEFR A2: short sentences and common words',
		'B1' => 'CEFR B1: plain language most adults understand',
		'B2' => 'CEFR B2: clear language, with subject terms explained',
		'1F' => 'Dutch referentieniveau 1F: the basic level expected at the end of primary school',
		'2F' => 'Dutch referentieniveau 2F: the level expected at the end of vmbo or mbo 2 and 3',
		'3F' => 'Dutch referentieniveau 3F: the level expected at the end of havo or mbo 4',
	];

	/**
	 * The reading level used when the caller names none.
	 *
	 * @var string
	 */
	public const DEFAULT_READING_LEVEL = 'B1';

	/**
	 * The fewest questions `questions` returns.
	 *
	 * @var int
	 */
	public const MIN_QUESTIONS = 1;

	/**
	 * The most questions `questions` returns.
	 *
	 * @var int
	 */
	public const MAX_QUESTIONS = 10;

	/**
	 * Draft a lesson outline that serves the given learning goals.
	 *
	 * @param array<int, string> $goalTitles The goals the lesson should serve (at least one).
	 * @param string $lessonText Optional existing notes to build on ('' for none).
	 * @param string $language BCP-47 tag of the outline's language.
	 * @param string $userId The requesting user, forwarded to the provider.
	 *
	 * @return array<string, mixed> The success envelope plus `draftText`, or the unavailable envelope.
	 *
	 * @spec openspec/specs/lesson-authoring/spec.md#requirement-req-004-draft-a-lesson-outline-from-learning-goals
	 */
	public function draftOutline(array $goalTitles, string $lessonText, string $language, string $userId): array;

	/**
	 * Rewrite a text at a lower reading level, keeping its language and meaning.
	 *
	 * Rewrites a text only. It never assesses, ranks or places a pupil, which is
	 * what keeps this action out of EU AI Act Annex III 3(b).
	 *
	 * @param string $lessonText The text to rewrite.
	 * @param string $readingLevel A key of READING_LEVELS; an unknown value falls back to the default.
	 * @param string $userId The requesting user, forwarded to the provider.
	 *
	 * @return array<string, mixed> The success envelope plus `draftText` and `readingLevel`, or the unavailable envelope.
	 *
	 * @spec openspec/specs/lesson-authoring/spec.md#requirement-req-006-rewrite-a-text-at-a-lower-reading-level
	 */
	public function simplify(string $lessonText, string $readingLevel, string $userId): array;

	/**
	 * Suggest which of the given goals a lesson covers, by position in the caller's list.
	 *
	 * @param string $lessonText The lesson text.
	 * @param array<int, string> $goalTitles The candidate goals, in the caller's order.
	 * @param string $userId The requesting user, forwarded to the provider.
	 *
	 * @return array<string, mixed> The success envelope plus `suggestedGoals`, or the unavailable envelope.
	 *
	 * @spec openspec/specs/lesson-authoring/spec.md#requirement-req-007-suggest-which-given-goals-a-lesson-covers
	 */
	public function suggestGoals(string $lessonText, array $goalTitles, string $userId): array;

	/**
	 * Suggest questions a teacher can ask about a lesson.
	 *
	 * @param string $lessonText The lesson text.
	 * @param array<int, string> $goalTitles Optional goals to focus the questions on.
	 * @param int $questionCount How many questions (clamped to MIN_QUESTIONS..MAX_QUESTIONS).
	 * @param string $language BCP-47 tag of the questions' language.
	 * @param string $userId The requesting user, forwarded to the provider.
	 *
	 * @return array<string, mixed> The success envelope plus `questions`, or the unavailable envelope.
	 *
	 * @spec openspec/specs/lesson-authoring/spec.md#requirement-req-005-suggest-questions-for-a-lesson
	 */
	public function suggestQuestions(
		string $lessonText,
		array $goalTitles,
		int $questionCount,
		string $language,
		string $userId,
	): array;
}//end interface
