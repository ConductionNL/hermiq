<?php

/**
 * Minimal OpenRegister ObjectCreatedEvent stub for standalone unit runs
 * and static analysis.
 *
 * Signatures mirrored from openregister lib/Event/ObjectCreatedEvent.php.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm; the real class wins whenever OpenRegister is
 * enabled.
 *
 * Keep the constructor and getter identical to the real event. A listener is
 * only as correct as the shape it unwraps, and a stub that drifts turns a
 * genuine breakage into a green run — the exact failure this app already paid
 * for once on the Talk reaction payload.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal ObjectCreatedEvent stub.
 */
class ObjectCreatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The created object.
	 */
	public function __construct(
		private readonly ObjectEntity $object,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The created object.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
