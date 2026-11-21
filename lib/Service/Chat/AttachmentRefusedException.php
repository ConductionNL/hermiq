<?php

/**
 * Hermiq AttachmentRefusedException.
 *
 * An upload Hermiq will not keep: a refused type or a file over the size cap.
 * The message is already in the person's language and is shown to them as is.
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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use RuntimeException;

/**
 * An upload refused for its type or size; the message is user-facing.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001
 */
class AttachmentRefusedException extends RuntimeException {
}//end class
