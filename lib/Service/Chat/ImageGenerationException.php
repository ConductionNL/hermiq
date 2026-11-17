<?php

/**
 * Hermiq ImageGenerationException.
 *
 * Why an image was not created, with a stable code for the tool result and the
 * chat action (chat-attachments-and-images D7).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use RuntimeException;

/**
 * An image that was not created, and why.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002
 */
class ImageGenerationException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $errorCode The stable code (invalid_prompt, feature_not_enabled,
	 *                          provider_unavailable, task_failed, marking_failed).
	 * @param string $message   The message for the person or the model.
	 */
	public function __construct(public readonly string $errorCode, string $message) {
		parent::__construct(message: $message);
	}//end __construct()
}//end class
