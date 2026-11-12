<?php

/**
 * Test stub for OpenRegister SaveActor.
 *
 * Stands in for OCA\OpenRegister\Service\Object\SaveActor when OpenRegister is not
 * installed (standalone CI), with the real constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * Who saves an object and who owns it.
 */
final class SaveActor {

	/**
	 * Constructor.
	 *
	 * @param IUser|null  $currentUser Explicit acting user; null uses the session.
	 * @param string|null $owner       Explicit owner.
	 * @param bool        $unowned     Stamp the system identity as owner.
	 */
	public function __construct(
		public readonly ?IUser $currentUser = null,
		public readonly ?string $owner = null,
		public readonly bool $unowned = false,
	) {
	}//end __construct()
}//end class
