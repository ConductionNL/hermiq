<?php

/**
 * Test stub for OpenRegister ObjectAuditEvent.
 *
 * Stands in for OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent when OpenRegister is not
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A non-CRUD action on one object.
 */
class ObjectAuditEvent {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object the entry is about.
	 * @param string $action The audit action.
	 * @param array<string, mixed> $context The context payload.
	 * @param ObjectAuditEventOptions $options The explicit actor and address.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $action,
		public readonly array $context = [],
		public readonly ObjectAuditEventOptions $options = new ObjectAuditEventOptions(),
	) {
	}//end __construct()
}//end class
