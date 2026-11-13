<?php

/**
 * Hermiq TurnEnrichment
 *
 * What a turn may add: background titling, prompt variables, attachments and generated images.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use OCA\Hermiq\Service\Chat\ImageGenerationService;
use OCA\Hermiq\Service\Chat\TurnAttachmentResolver;
use OCP\BackgroundJob\IJobList;

/**
 * What a turn may add: background titling, prompt variables, attachments and generated images.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class TurnEnrichment {

	/**
	 * Constructor.
	 *
	 * @param IJobList|null $jobList Queues the conversation-title job
	 *                               so naming never sits on the reply's
	 *                               critical path
	 *                               (session-context-performance).
	 *                               Nullable/defaulted for the same
	 *                               backward-compat reason; a null list
	 *                               simply means no title is queued —
	 *                               the reply is unaffected, which is
	 *                               the point of deferring it.
	 * @param PromptVariableResolver|null $promptVariables Fills the placeholders in
	 *                                                     Agent.prompt for the acting
	 *                                                     person (agents-instruction-variables);
	 *                                                     null leaves the prompt as written.
	 * @param TurnAttachmentResolver|null $attachmentResolver Reads a turn's attachments as the
	 *                                                        person who sent it
	 *                                                        (chat-attachments-and-images); null
	 *                                                        refuses any turn that carries one.
	 * @param ImageGenerationService|null $images Hands over the images the agent created in the
	 *                                            turn, for the answer (chat-attachments-and-images D8).
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ?IJobList $jobList = null,
		public readonly ?PromptVariableResolver $promptVariables = null,
		public readonly ?TurnAttachmentResolver $attachmentResolver = null,
		public readonly ?ImageGenerationService $images = null,
	) {
	}//end __construct()
}//end class
