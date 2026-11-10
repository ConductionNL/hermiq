<?php

/**
 * Test stub for OpenRegister ObjectPatch.
 *
 * Stands in for OCA\OpenRegister\Service\Object\ObjectPatch when OpenRegister is not
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
 * One partial update: the object, the data to merge, where it lives and who
 * makes the change.
 */
final class ObjectPatch {

	/**
	 * Constructor.
	 *
	 * @param string $objectId The object id, uuid or slug.
	 * @param array $data The partial data to merge.
	 * @param ObjectFindTarget $target The register and schema it lives in.
	 * @param IUser|null $currentUser The acting user, or null for the session user.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly array $data,
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?IUser $currentUser = null,
	) {
	}//end __construct()
}//end class
