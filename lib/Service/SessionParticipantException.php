<?php

/**
 * A refused change to a session's participant list, carrying its HTTP status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use RuntimeException;

/**
 * The code is the HTTP status the controller answers with (400, 404 or 409).
 *
 * @spec openspec/specs/session-participants/spec.md#requirement-the-owner-invites-colleagues-into-a-session-req-spart-001
 */
class SessionParticipantException extends RuntimeException {
}//end class
