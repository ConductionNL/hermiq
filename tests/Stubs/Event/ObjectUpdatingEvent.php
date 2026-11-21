<?php

/**
 * Minimal OpenRegister ObjectUpdatingEvent stub for standalone unit runs and
 * static analysis.
 *
 * Mirrored from openregister lib/Event/ObjectUpdatingEvent.php: dispatched
 * before an object is updated, with the new and the stored object; a listener
 * may stop it. Keep the constructor and getters identical to the real event.
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
 * Minimal ObjectUpdatingEvent stub.
 */
class ObjectUpdatingEvent extends Event implements StoppableEventInterface {
	use WriteVetoState;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity      $newObject The object as it will be saved.
	 * @param ObjectEntity|null $oldObject The object as it is stored.
	 */
	public function __construct(
		private readonly ObjectEntity $newObject,
		private readonly ?ObjectEntity $oldObject = null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object as it is stored.
	 *
	 * @return ObjectEntity|null
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()

	/**
	 * The object as it will be saved.
	 *
	 * @return ObjectEntity
	 */
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()
}//end class
