<?php

/**
 * Test stub for OpenRegister NotAuthorizedException.
 *
 * Stands in for OCA\OpenRegister\Exception\NotAuthorizedException when OpenRegister is not
 * installed (standalone CI). Mirrors the real exception: a plain Exception whose default code
 * is 403. OpenRegister's PermissionHandler throws it when the caller may not act on a schema.
 * The real class ships with OpenRegister at runtime.
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
 * Minimal NotAuthorizedException stub for standalone unit runs.
 */
class NotAuthorizedException extends Exception {

	/**
	 * The default message.
	 *
	 * @var string
	 */
	protected $message = 'You are not authorized to perform this action';

	/**
	 * The default code.
	 *
	 * @var integer
	 */
	protected $code = 403;
}//end class
