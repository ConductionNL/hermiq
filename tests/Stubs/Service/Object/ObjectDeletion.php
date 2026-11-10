<?php

/**
 * Test stub for OpenRegister ObjectDeletion.
 *
 * Stands in for OCA\OpenRegister\Service\Object\ObjectDeletion when OpenRegister is not
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
 * One object to delete: its uuid, where it lives, who deletes it and whether
 * the delete is permanent.
 */
final class ObjectDeletion {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The object uuid.
	 * @param ObjectFindTarget $target The register and schema it lives in.
	 * @param IUser|null $currentUser The deleting user, or null for the session user.
	 * @param bool $permanent Whether to delete permanently instead of soft-deleting.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?IUser $currentUser = null,
		public readonly bool $permanent = false,
	) {
	}//end __construct()

	/**
	 * Whether the deletion names both a register and a schema.
	 *
	 * @return bool
	 */
	public function isScoped(): bool {
		return $this->target->register !== null && $this->target->schema !== null;
	}//end isScoped()
}//end class
