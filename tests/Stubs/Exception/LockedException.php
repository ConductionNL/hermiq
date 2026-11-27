<?php

/**
 * Test stub for OpenRegister LockedException.
 *
 * Stands in for OCA\OpenRegister\Exception\LockedException when OpenRegister is not
 * installed (standalone CI). Mirrors the real exception: a plain Exception thrown by the
 * lock handler when an object or advisory lock is already held. The real class ships with
 * OpenRegister at runtime.
 *
 * @category Test
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Minimal LockedException stub for standalone unit runs.
 */
class LockedException extends Exception {
}//end class
