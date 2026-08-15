<?php

/**
 * Hermiq Artefact Marking Failed Exception (ADR-088).
 *
 * Thrown when an artefact was written but could not be marked as agent-authored.
 *
 * This is deliberately an exception rather than a return value. ADR-088 §5 makes
 * a failed mark a FAILED WRITE: an unmarked artefact reported as marked is worse
 * than a refused write, because nothing downstream will ever question it. A
 * boolean would be honoured by the first caller and quietly dropped by the third.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use RuntimeException;

/**
 * Raised when the ADR-088 agent-authored mark could not be applied.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class ArtefactMarkingFailedException extends RuntimeException {

}//end class
