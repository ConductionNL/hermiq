<?php

/**
 * Hermiq AuthoringRequest
 *
 * Who asked for a lesson-authoring action, and with what.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\LessonAuthoring
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
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\LessonAuthoring;

/**
 * Who asked for a lesson-authoring action, and with what.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class AuthoringRequest {

	/**
	 * Constructor.
	 *
	 * @param string $userId The requesting user.
	 * @param string $prompt The built prompt.
	 * @param array{lessonTextLength: int, goalCount: int} $sizes Input sizes for the call log.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $prompt,
		public readonly array $sizes,
	) {
	}//end __construct()
}//end class
