<?php

/**
 * Hermiq AttachmentTextSource.
 *
 * Turns a PDF or office file into text for a model that cannot read the file
 * itself. OpenRegister holds the extractors, but they are internal to it; the
 * public facade hermiq needs is asked for in design D6 and has no class name
 * yet. Until an adapter over that facade is registered, no source is wired and
 * AttachmentTextReader leaves such a file out and says so.
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

/**
 * Extracts a file's text, read as the person.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */
interface AttachmentTextSource {

	/**
	 * Whether an extractor is configured; false means every file is left out.
	 *
	 * @return bool True when extractText() can answer.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function isAvailable(): bool;

	/**
	 * The file's text, resolved in the person's own Files.
	 *
	 * Writes no index, chunk or vector as a side effect.
	 *
	 * @param int    $fileId The file id.
	 * @param string $userId The uid whose Files the file is read from.
	 *
	 * @return string|null The text; null when the file is unreadable for them or has none.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function extractText(int $fileId, string $userId): ?string;
}//end interface
