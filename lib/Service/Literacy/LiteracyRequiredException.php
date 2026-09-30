<?php

/**
 * Hermiq: the course Working with AI is required first (compliance-ai-literacy).
 *
 * Thrown where a person starts a chat, a run by hand or a Talk session with an
 * agent, when their organisation requires the course and they have not finished
 * the current lessons. Scheduled and flow runs never throw it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Literacy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Literacy;

use RuntimeException;

/**
 * The person must finish the course first.
 *
 * @spec openspec/changes/compliance-ai-literacy/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
 */
class LiteracyRequiredException extends RuntimeException {

	/**
	 * A stable code the chat keys its message and link off.
	 */
	public const ERROR_CODE = 'ai_literacy_required';

	/**
	 * The course page, relative to the app.
	 */
	public const COURSE_PATH = '/apps/hermiq/ai-literacy';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(message: 'Finish the short course Working with AI first.', code: 403);

	}//end __construct()

}//end class
