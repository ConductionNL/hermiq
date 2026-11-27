<?php

/**
 * Contract of LiteracyCourse
 *
 * The public surface of LiteracyCourse that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of LiteracyCourse.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LiteracyCourseInterface {

	/**
	 * Check an answer; a right one records the person's completion of this version.
	 *
	 * @param string $uid The person.
	 * @param string $organisation The organisation they work in.
	 * @param string $slug The lesson.
	 * @param string $locale Their language.
	 * @param int $choice The zero-based index of the chosen option.
	 *
	 * @return array<string, mixed> `correct`, and `explanation` when wrong, plus progress.
	 *
	 * @throws InvalidArgumentException When the lesson does not exist.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function answer(string $uid, string $organisation, string $slug, string $locale, int $choice): array;

	/**
	 * The lessons in a language, with the person's done state and progress.
	 *
	 * @param string $uid The person.
	 * @param string $locale Their language (e.g. nl, nl_NL, en).
	 *
	 * @return array{lessons: array<int, array<string, mixed>>, done: int, total: int}
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-people-can-follow-short-lessons-on-working-with-ai-req-ailit-001
	 */
	public function lessons(string $uid, string $locale): array;
}//end interface
