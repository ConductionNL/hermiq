<?php

/**
 * Hermiq LearnerSignalScope
 *
 * The learner and the resolved register one signal collection reads from.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-5
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Who the signals are read for, and where.
 *
 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-5
 */
final class LearnerSignalScope {

	/**
	 * Build the scope.
	 *
	 * @param string $learnerUid The learner's NC user id.
	 * @param string $register   The RESOLVED register slug, from {@see LearnerSignalRegister}.
	 *
	 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-5
	 */
	public function __construct(
		public readonly string $learnerUid,
		public readonly string $register,
	) {
	}//end __construct()

	/**
	 * The filter map that narrows a read to this learner.
	 *
	 * @param string|null $field The learner field, or null for an unfiltered read.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/archive/2026-07-16-ai-course-recommendations/tasks.md#task-2-5
	 */
	public function filtersOn(?string $field): array {
		if ($field === null) {
			return [];
		}

		return [$field => $this->learnerUid];
	}//end filtersOn()
}//end class
