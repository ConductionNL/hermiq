<?php

/**
 * Hermiq IntakeReview.
 *
 * One external review of a conversation: the declared review tool, the arguments
 * passed through to it untouched, and when it was asked. Built by IntakeService from
 * its review() call and never handed out of it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Intake
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use DateTimeImmutable;

/**
 * One review request.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
 */
final class IntakeReview {

	/**
	 * When the review was asked.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $at;

	/**
	 * Ask for the review.
	 *
	 * @param string               $toolId    The declared review tool.
	 * @param array<string, mixed> $arguments The arguments, passed through untouched.
	 */
	public function __construct(
		public readonly string $toolId,
		public readonly array $arguments,
	) {
	}//end __construct()
}//end class
