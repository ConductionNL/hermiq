<?php

/**
 * Test stub for OpenRegister ObjectAuditEventOptions.
 *
 * Stands in for OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions when OpenRegister is not
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
 * The explicit actor and address of an object audit event.
 */
class ObjectAuditEventOptions {

	/**
	 * Constructor.
	 *
	 * @param NamedActor $actor The named actor, empty for the session user.
	 * @param string|null $ipAddress The address the action came from.
	 */
	public function __construct(
		public readonly NamedActor $actor = new NamedActor(),
		public readonly ?string $ipAddress = null,
	) {
	}//end __construct()
}//end class
