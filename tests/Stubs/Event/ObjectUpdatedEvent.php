<?php
/**
 * Minimal OpenRegister ObjectUpdatedEvent stub for standalone unit runs
 * and static analysis.
 *
 * Signatures mirrored from openregister lib/Event/ObjectUpdatedEvent.php.
 * See ObjectCreatedEvent alongside this file for the stub discipline.
 *
 * Note the asymmetry with the created/deleted events, and keep it: the getter
 * is `getNewObject()`, NOT `getObject()`, and `$oldObject` is nullable. A
 * listener that reaches for `getObject()` here fails at runtime only, because
 * nothing in static analysis resolves the real class.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal ObjectUpdatedEvent stub.
 */
class ObjectUpdatedEvent extends Event
{

    /**
     * Constructor.
     *
     * @param ObjectEntity      $newObject The object after the update.
     * @param ObjectEntity|null $oldObject The object before the update, when known.
     */
    public function __construct(
        private readonly ObjectEntity $newObject,
        private readonly ?ObjectEntity $oldObject=null
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * The object after the update.
     *
     * @return ObjectEntity The new object.
     */
    public function getNewObject(): ObjectEntity
    {
        return $this->newObject;

    }//end getNewObject()

    /**
     * The object before the update, when known.
     *
     * @return ObjectEntity|null The old object.
     */
    public function getOldObject(): ?ObjectEntity
    {
        return $this->oldObject;

    }//end getOldObject()
}//end class
