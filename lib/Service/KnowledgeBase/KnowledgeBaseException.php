<?php

/**
 * Hermiq KnowledgeBaseException.
 *
 * A refusal on a knowledge base route, carrying the HTTP status as its code.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\KnowledgeBase
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
 * @spec openspec/changes/memory-knowledge-bases/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\KnowledgeBase;

use RuntimeException;

/**
 * A knowledge base request that is refused; the code is the HTTP status.
 *
 * @spec openspec/changes/memory-knowledge-bases/specs/knowledge-bases/spec.md#requirement-an-owner-can-create-a-knowledge-base-and-fill-it-from-files-req-kb-001
 */
class KnowledgeBaseException extends RuntimeException {
}//end class
