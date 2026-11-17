<?php

/**
 * Minimal OpenRegister ObjectCreatingEvent stub for standalone unit runs and
 * static analysis.
 *
 * Mirrored from openregister lib/Event/ObjectCreatingEvent.php (through
 * AbstractPreWriteObjectEvent and AbstractObjectEntityEvent): dispatched
 * before an object is created; a listener may stop it. Keep the constructor
 * and getters identical to the real event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Minimal ObjectCreatingEvent stub.
 */
class ObjectCreatingEvent extends Event implements StoppableEventInterface {
	use WriteVetoState;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object about to be created.
	 */
	public function __construct(
		private readonly ObjectEntity $object,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object about to be created.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
