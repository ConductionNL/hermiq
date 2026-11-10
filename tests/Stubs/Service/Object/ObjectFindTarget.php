<?php

/**
 * Test stub for OpenRegister ObjectFindTarget.
 *
 * Stands in for OCA\OpenRegister\Service\Object\ObjectFindTarget when OpenRegister is not
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

/**
 * The register and schema an object is looked up in.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param mixed $register The register entity, id or slug.
	 * @param mixed $schema The schema entity, id or slug.
	 */
	public function __construct(
		public readonly mixed $register = null,
		public readonly mixed $schema = null,
	) {
	}//end __construct()
}//end class
