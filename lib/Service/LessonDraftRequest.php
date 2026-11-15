<?php

/**
 * Hermiq LessonDraftRequest.
 *
 * One request to draft lesson material with the model: the lesson text (or the
 * teacher's notes), the language to write in, the cleaned learning goals, and
 * the requesting user the provider call is made for.
 *
 * @category ValueObject
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-002-only-lesson-text-and-goal-titles-reach-the-model
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One lesson drafting request.
 *
 * @spec openspec/changes/lesson-authoring-ai-delegate/specs/lesson-authoring/spec.md#requirement-req-002-only-lesson-text-and-goal-titles-reach-the-model
 */
final class LessonDraftRequest {

	/**
	 * The learning goals, cleaned and keyed by their position in the caller's list.
	 *
	 * @var array<int, string>
	 */
	public array $goals = [];

	/**
	 * The requesting user, forwarded to the provider.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * Start the request.
	 *
	 * @param string $lessonText The lesson text, or the notes to build on ('' for none).
	 * @param string $language   BCP-47 tag of the language to write in.
	 */
	public function __construct(
		public readonly string $lessonText,
		public readonly string $language,
	) {
	}//end __construct()
}//end class
