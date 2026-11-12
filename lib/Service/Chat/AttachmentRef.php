<?php

/**
 * Hermiq AttachmentRef
 *
 * One attachment whose text is read for a model: its file, its name and who sent it.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

/**
 * An attachment to read as its sender.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */
final class AttachmentRef {

	/**
	 * Build the reference.
	 *
	 * @param int    $fileId  The file id in the sender's Files.
	 * @param string $name    The name the person sees.
	 * @param string $speaker The person who sent it.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $name,
		public readonly string $speaker,
	) {
	}//end __construct()
}//end class
