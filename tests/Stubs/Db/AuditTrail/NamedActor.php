<?php

/**
 * Test stub for OpenRegister NamedActor.
 *
 * Stands in for OCA\OpenRegister\Db\AuditTrail\NamedActor when OpenRegister is not
 * installed (standalone CI), with the real constructor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * An actor named by id and display name.
 */
class NamedActor {

	/**
	 * Constructor.
	 *
	 * @param string|null $id The actor id.
	 * @param string|null $name The actor display name.
	 */
	public function __construct(
		public readonly ?string $id = null,
		public readonly ?string $name = null,
	) {
	}//end __construct()
}//end class
