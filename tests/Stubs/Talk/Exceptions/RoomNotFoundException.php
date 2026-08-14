<?php

/**
 * Test stub for OCA\Talk\Exceptions\RoomNotFoundException (spreed 24.0.1).
 *
 * Thrown by Manager::getRoomForUserByToken() when the user has no access to the room
 * (the membership check). Used by DeliveryService tests to drive the fallback path.
 *
 * @category Stub
 * @package  OCA\Talk\Exceptions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Talk\Exceptions;

use Exception;

/**
 * Minimal stub of the spreed RoomNotFoundException.
 */
class RoomNotFoundException extends Exception {
}//end class
